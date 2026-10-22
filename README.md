# Ferry for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/ferry.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/ferry)

Ferry manages 1C database connections, source structure snapshots, synchronization mappings, and Kafka Connect connectors in Filament. It also provides reusable `FieldMapping` and `FieldBinding` form fields.

## Features

- `Connection` model with an encrypted password that is never returned to the interface.
- `ConnectionFactory` service that tests current (possibly unsaved) form values, always closes the temporary connection, and reports a sanitized result.
- Queued structure preparation built on `phpinnacle/rosetta`, storing the semantic 1C metadata as `ConnectionMetadata` records.
- Filament `ConnectionResource` with create/edit/list pages, "Test connection" and "Fetch structure" actions, a structure status badge and driver-based default port suggestion.
- Reusable JSON Schema-based `FieldMapping` form field with connection lines, type checks, array collections, inline static editors, and reversible column layout.
- `FieldBinding` form field that binds configured sources to strings or custom Filament schemas.
- `Sync` model storing the source object, the field mapping schema and the generated destination table name.
- Static destinations: application-registered tables such as customers or products that synchronizations fill instead of a generated table.
- Filament `SyncResource` that selects an active connection with a ready structure and an object from the published snapshot, maps source fields to column names and keeps the physical destination table in sync with the record.
- Kafka Connect connector management built on `phpinnacle/franz`: one Debezium source connector per connection, one JDBC sink connector per synchronization, with status and applied configuration stored in `Connector` models.
- Policy-backed connection and synchronization management (`ConnectionPolicy`, `SyncPolicy`), custom database connection and optional tenancy.

## Installation

```bash
composer require phpinnacle/ferry
php artisan vendor:publish --tag="phpinnacle-ferry-migrations"
php artisan migrate
```

The package publishes a single `create_ferry_tables` migration for connections, metadata, synchronizations and connectors.

Register `FerryPlugin::make()` in the target Filament panel. Publish `phpinnacle-ferry-config` when using a non-default database connection, tenant model or timeout. Structure preparation runs on the queue, so a worker must be running.

Connector management talks to Kafka Connect over PSR-18, so the application binds the transport it prefers. Kafka dependencies are resolved when a connector operation begins; constructing observers or opening a synchronization form does not initialize them:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

$this->app->bind(ClientInterface::class, Client::class);
$this->app->bind(RequestFactoryInterface::class, HttpFactory::class);
$this->app->bind(StreamFactoryInterface::class, HttpFactory::class);
```

## Source structure

Creating a connection, or changing any of its credentials, starts a new preparation run: the connection switches to `StructureStatus::Preparing` and `PrepareStructureJob` is dispatched after the database transaction commits. Only the connection id and the run id travel through the queue; credentials never do.

The run reads the source in bounded chunks and writes them into a draft revision. The published snapshot is replaced only once the whole draft is complete, so a failed refresh never damages the previous one. A source without Rosetta-supported objects is a valid empty snapshot.

```php
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Connection;

$connection = Connection::query()
    ->where('is_active', true)
    ->where('status', StructureStatus::Ready)
    ->firstOrFail();

foreach ($connection->publishedMetadata()->get() as $object) {
    $object->kind;       // PHPinnacle\Rosetta\Enums\MetadataKind
    $object->system;     // array<string, PHPinnacle\Rosetta\Contracts\Field>
    $object->properties; // list<PHPinnacle\Rosetta\Data\MetadataProperty>
    $object->values;     // list<PHPinnacle\Rosetta\Data\EnumerationValue>
    $object->children;   // document tabular sections
}
```

Property comments read by Rosetta are preserved in the snapshot JSON and restored as `MetadataProperty::$comment`. Existing properties without a comment remain readable.

`publishedMetadata()` returns the root objects of the published revision; `publishedObject($source)` finds one of them by its external id and returns `null` when absent. Use the `metadata()` relation to reach every stored revision. `is_active` states whether the connection may be used at all, while `status` only reports whether its local structure is ready.

When a run fails for good, the connection switches to `StructureStatus::Failed`, keeps its previous snapshot, stores a sanitized description in `last_error` and reports the original exception through `report()`. An interrupted run that stops sending heartbeats is retired on the next preparation attempt. `Connection::canPrepareStructure($staleAfter)` and `prepareStructure($staleAfter)` accept the staleness threshold in seconds; the panel action reads it from `phpinnacle-ferry.structure.stale_after`.

## Synchronizations

A synchronization selects a connection with a ready structure and one object from its published snapshot, then maps source fields (system fields by key, properties by id) to destination columns. For dynamic destinations, column names must be unique, start with a lowercase letter, contain only lowercase letters, digits and underscores, and differ from the reserved `idrref`. The form requires at least one source binding or static value. Saving creates a physical table named `{prefix}{code}` in the Ferry connection, with `idrref` as its primary key and nullable mapped columns typed from the source, their transformations or static values.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the synchronization drops the table after a data-loss confirmation. When a structure refresh removes the source object, removes a mapped field or changes a field type, the synchronization is paused automatically; the table and its stored rows stay untouched.

A change of logical column type drops and recreates that column, so it requires the same data-loss confirmation as removing a mapping.

The model owns this lifecycle regardless of the caller: creating a `Sync` creates its destination table, changing its `schema` applies the difference and deleting it drops the table, so a synchronization created from a command, job or seeder behaves exactly like one created from the panel. Saving state and table changes is transactional; eligible connectors update after commit.

`SyncSchema::fromBindings($object, $bindings, $registry, $destination, $transforms)` converts source-to-column bindings and optional field transforms and constants into typed mappings, checking source metadata and the optional static destination. Pass the `TransformRegistry` explicitly. Transform parameters must already be validated by their native Filament forms. Dynamic column-name validation belongs to `SyncSchema::validateColumns($columns, $fail)` and is applied by the form before conversion.

`Sync::brokenColumns($object, $destinationFields)` and `hasValidSchema($object, $destinationFields)` compare saved mappings with current declarations; `shouldResume($object, $destinationFields)` applies the automatic-resume policy. Pass a list of typed `DestinationField` values for a static destination, or `null` for a dynamic or unavailable destination. `schemaChanges()` plans additions, renames, and removals from the original and current typed mappings; `droppedColumns()` exposes removals for confirmation. Table updates use the same plan and preserve stored values on renames.

A pause requested by an administrator and a pause caused by a broken mapping are distinguished by `is_paused`. Saving the edit form resumes only a synchronization that was paused automatically and whose mapping is valid again; a pause requested through the pause action survives editing and requires the activate action. `ConnectorManager::activate()` refuses a synchronization whose mapping no longer matches its source or destination.

## Static destinations

A synchronization writes either into a generated `{prefix}{code}` table (dynamic) or into a table the application already owns (static). `DynamicDestination` and `StaticDestination` independently implement the `Destination` interface, which exposes the target table and `connector($sync, $object, $topic, $transforms)`. Dynamic destinations need only their generated table name; static destinations describe the application's target and its mapping fields. Register static destinations on the plugin:

```php
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\FerryPlugin;

FerryPlugin::make()->destinations(new StaticDestination(
    key: 'customers',
    label: 'Customers',
    table: 'customers',
    primaryKey: 'id',
    fields: [
        new DestinationField('name', 'Name', ColumnType::String, required: true),
        new DestinationField('manager_id', 'Manager', ColumnType::Reference, required: false),
    ],
    connection: 'sales',
    fixedValues: ['type' => 'customer'],
));
```

The readonly `key` identifies the destination in the persisted synchronization. `table` is its target table; `fields` lists the permitted columns, their logical types and whether they are required. `StaticDestination` implements Filament's `HasLabel` contract. A `null` connection uses the Ferry connection. Fixed values are written into every row through `InsertField` transforms.

`ColumnType::jsonSchema()` projects a logical type into the inline type and format used by the mapping editor; references remain distinct from strings, and dates use the `date-time` format.

`Sync.type` explicitly selects `DestinationType::Static` or `DestinationType::Dynamic` (the default). `Sync.destination` stores the registered key for static synchronizations or the generated table name for dynamic ones. `DestinationFactory::get($key)` returns the registered destination or `null`. Use `getOrFail($key)` when a static destination is required. `DestinationFactory::resolve($sync)` returns the registered static destination or a `DynamicDestination` for its generated table. `fieldsFor($sync)` returns the registered static destination's fields, or `null` for a dynamic or unavailable destination. `SyncObserver` initializes only a dynamic record's destination table name when created; static records retain their registered destination key.

Saving a static synchronization accepts only declared destination fields whose logical `ColumnType` matches the transformed source or static value (`Reference` covers 1C references and identifiers). Every required field must be mapped, no destination may be mapped twice, and the source key cannot be remapped: it always feeds the configured primary key. Ferry never creates, alters or drops a static table, and its sink connector upserts rows without propagating source deletions. Removing a registered destination causes the next structure review to pause its synchronizations and report their mappings as out of date; saving and activation remain blocked until it is registered again.

The synchronization form has one destination select: Dynamic followed by the registered static destinations. Selecting a different destination clears the field mapping; the saved synchronization keeps its explicit type and destination key. A dynamic synchronization lists every mappable 1C field with its title and technical column and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections.

The static synchronization form adds an exact-format connection condition to preserve its logical `ColumnType` contract: references and dates require an explicit Cast before connecting to a plain string destination. The reusable `FieldMapping` component keeps its more general directional format compatibility described below.

## Transforms

A transform changes data on its way to the destination as a Kafka Connect transform of the sink connector. Editor descriptions and execution handlers share a stable key but are registered separately. `Transformation` is an immutable description with Filament-style `label()`, `icon()`, `color()`, `description()`, and `schema()` methods. `key()` identifies it and `scope()` returns `TransformScope::Record` or `TransformScope::Field`. It implements Filament's `HasLabel`, `HasIcon`, `HasColor`, and `HasDescription` contracts; icons and descriptions are optional.

### Editor descriptions

`FerryPlugin` provides the built-in cast and record filter descriptions. Register optional descriptions on the plugin:

```php
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;

FerryPlugin::make()->transforms(Trim::make(), Substring::make(), MaskField::make());
```

A custom description uses the same API:

```php
use Filament\Forms\Components\TextInput;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

Transformation::make('replace', TransformScope::Field)
    ->inputSchema(['type' => 'string'])
    ->outputSchema(['type' => 'string'])
    ->label('Replace text')
    ->color('warning')
    ->schema(fn () => [TextInput::make('replacement')->required()]);
```

Labels, icons, descriptions, and colors accept values or closures. `color()` defaults to `primary` and accepts a Filament color name, a shade palette such as `Color::Emerald`, or `null`, styling source chips and Builder labels. `schema()` accepts an array of Filament components or a closure receiving the selected `?ConnectionMetadata $object`; `getSchema($object)` returns fresh components for each form. Fluent methods return a new description, leaving the original unchanged.

`inputSchema()` describes supported values using the same compatibility rules as `FieldMapping`: types, directional formats, string lengths and array items. It also accepts a predicate `fn (JsonSchema $input) => bool` for additional restrictions. Without an input schema, a description accepts any input. `accepts($input)` checks a `Forms\JsonSchema` value object directly, without projecting it to `ColumnType`.

`outputSchema()` declares a fixed JSON Schema array or a callback receiving the full input schema and settings. For example, a string transform that preserves null values can declare:

```php
use PHPinnacle\Ferry\Forms\JsonSchema;

->outputSchema(fn (JsonSchema $input, array $params) => [
    'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
])
```

`getOutputSchema($input, $params)` returns a `JsonSchema` object. Without an output declaration it preserves the complete input schema, which is appropriate only when the transform guarantees that all its constraints remain true. A callback can return null when its settings are incomplete and no output can be determined. An empty schema array means unconstrained values, not an invalid chain.

Each step consumes the preceding output schema. Cast replaces the value constraints with the selected scalar type and preserves nullability; repeated casts use that result. Trim, Substring and Mask accept strings, including formatted and nullable strings, but reject `date-time` and `ferry-reference`, which represent logical dates and references. Their outputs retain nullability and discard the original format, enum, pattern and length bounds. Custom descriptions can preserve or establish guarantees their execution actually provides.

Forms, menus and previews use descriptions without resolving execution handlers or requiring their configuration. Parameter validation in `FieldTransforms` runs the description's native Filament schema, including relative field rules. `ColumnType` conversion happens only when synchronization mappings are saved; the final output must have a supported scalar column type.

### Execution handlers

Configure execution separately in `config/phpinnacle-ferry.php`. The default configuration includes handlers for all five supplied descriptions, including the optional editor choices:

```php
use PHPinnacle\Ferry\Transforms\CastTransform;
use PHPinnacle\Ferry\Transforms\FilterTransform;
use PHPinnacle\Ferry\Transforms\MaskFieldTransform;
use PHPinnacle\Ferry\Transforms\SubstringTransform;
use PHPinnacle\Ferry\Transforms\TrimTransform;

// phpinnacle-ferry.transforms
[
    'cast' => CastTransform::class,
    'filter' => FilterTransform::class,
    'trim' => TrimTransform::class,
    'substring' => SubstringTransform::class,
    'mask' => MaskFieldTransform::class,
];
```

Custom handlers implement `Contracts\TransformHandler`: `normalize()` projects parameters onto current object metadata for structure review, `sourceColumns()` declares additional reads, and `connector()` builds Kafka Connect settings. Handlers resolve through Laravel's container only when needed. Input/output JSON Schemas belong to the editor description; parameter validation belongs to its native Filament form.

`TransformRegistry` stores the UI descriptions registered through the plugin. Its `get($key)`, `getOrFail($key)` and `forScope($scope)` methods read that catalog. `resultSchema(JsonSchema $input, array $settings)` passes the full schema through the ordered chain and returns null for an incompatible step or an unavailable output. Forms use this registry without resolving handlers.

`TransformFactory::handler($key)` and `handlerOrFail($key)` resolve handlers from configuration independently of the UI registry. The factory also builds connector pipelines and checks their runtime availability. Input validation in `SyncSchema` uses only `TransformRegistry`. Workers can execute saved transforms without resolving the UI registry or booting a Filament panel. Hiding a transform from the editor does not disable its saved pipelines; remove its handler configuration to make it unavailable at runtime.

Transform settings are ordered `['key' => 'mask', 'params' => ['replacement' => '***']]` entries; parameter values are strings, and `params` may be omitted when empty. A synchronization stores record transforms in `Sync::$transforms` and field transforms on each `Data\FieldMapping`, with their logical `result` type. Native Filament forms validate parameters before conversion. `SyncSchema::fromBindings()` checks field scope, the type chain and destination compatibility; `SyncSchema::fromTransforms($settings, $registry)` checks record scope. The sink applies record transforms, then field transforms, after `unwrap` and before renames. Static values are inserted after renames. Saving refreshes the sink connector; the shared source connector changes only when its capture configuration changes. Structure review pauses synchronizations with unavailable handlers or required source columns, and connector activation refuses them.

### Built-in transforms

The built-in `CastTransform` casts a field to a `ColumnType`. `ColumnType::castType()` exposes its Kafka Connect type and is `null` for references and dates, which Kafka Connect cannot cast. Static values use the separate mapping mechanism: `Data\StaticValue` stores their type and value in the mapping's `constant` property, outside its transform list. They are validated as static values and compiled to `InsertField` plus a cast when needed, without registering a transform handler. Numeric sources can be cast to any supported target type.

`TrimTransform`, `SubstringTransform`, and `MaskFieldTransform` are optional field transforms, offered in the editor explicitly rather than enabled there for every installation. All accept string inputs and preserve their logical type. The selected source field is supplied by the mapping; administrators do not enter a second field name.

| Key | Settings form | Kafka Connect class |
| --- | --- | --- |
| `trim` | No settings; trims whitespace from both ends of the selected string. | `org.phpinnacle.toblerone.TrimTransform$Value` |
| `substring` | Required zero-based `start`, optional exclusive `end`, shown side by side on large screens. A blank end is omitted; zero is retained. | `org.phpinnacle.toblerone.SubstringTransform$Value` |
| `mask` | Required `replacement`, default `***`, replacing the entire string. | `org.apache.kafka.connect.transforms.MaskField$Value` |

Trim requires the Toblerone plugin and preserves null field values and tombstones. Substring serializes indexes as `fields=source:start[:end]` and requires the Toblerone plugin on the worker. It uses Java UTF-16 indexes without clamping: indexes must fit every input string. Masking uses Apache's `fields` and `replacement` settings and skips tombstones through the built-in predicate. Other MaskField options keep their Kafka defaults. Forms validate the settings; Ferry does not execute the transformations in PHP.

The record `FilterTransform` keeps (`include`) or drops (`exclude`) records whose selected string, reference or boolean field equals a value. It uses Confluent's `Filter$Value` with a JSON Path condition. The native form validates the selected column and value, then derives the persisted field type from object metadata during dehydration. Record transforms declare additional reads through `sourceColumns()`, and `SourceFactory` includes them in capture. Structure review uses `normalize()` to detect changes to the saved filter's field type. Unmapped filter columns are excluded from destination writes by the sink whitelist.

Records missing the field are dropped by `include` and kept by `exclude`. Filters skip tombstones through the negated `RecordIsTombstone` predicate, preserving Debezium deletions. The form resets transforms when the connection or object changes.

### Editing synchronization transforms

The synchronization form configures record transforms in a **Record transforms** Builder above the field mapping. Blocks show their icon, label, optional description, and settings; they can repeat, reorder, or be removed. The form converts native Builder state to the public `key`/`params` format on fill and submission.

`FieldTransforms` inside `sourceSettings()` provides an **Add transform** menu for compatible registered field transforms. Boolean sources initially offer Cast; casting to string enables Trim, Substring, and Mask. Compatible transforms can repeat. Transforms with settings open their native form; those without settings are added immediately. Chips show execution order: click a configurable label to edit, use its separate remove button to delete, and drag the label or press Alt+Left / Alt+Right to reorder. Existing chips remain editable and removable after an incompatible change; validation rejects the unsupported chain and additions remain unavailable until it is corrected. Settings modals contain no deletion action. These controls work before connecting or binding a source.

`FieldMapping` and `FieldBinding` keep transform settings in `sources[source].transforms`, independently of their connections or bindings. `SyncForm` combines these settings with connected or bound fields on save and restores them on fill; settings for unused sources remain in the editor but are not persisted in the synchronization. Static values have their own mapping controls. Several transforms of one field apply in chip order, and the static mapping checks a connection with the type its source field has after those transforms, so a cast makes a string field connectable to a boolean destination.

## Kafka Connect connectors

Data actually moves through Kafka Connect. A connection owns a single Debezium PostgreSQL source connector named `ferry-source-{code}`, and every synchronization owns a JDBC sink connector named `ferry-sink-{code}`. Adding synchronizations never creates a second source connector: the shared one captures the union of the tables and columns of the connection's active and paused synchronizations through `table.include.list` and `column.include.list`, and its replication slot and publication are namespaced by connection code so connections never collide on a shared Kafka Connect cluster.

Pausing a synchronization keeps its table in that scope on purpose. The source connector keeps advancing the write-ahead log for the other synchronizations of the connection, so a table dropped from the capture scope would silently lose every change made while the sink was paused.

`ConnectorManager` is the entry point and every operation is idempotent, since configuration is pushed with `PUT /connectors/{name}/config`. `SourceFactory` builds source configurations and supplies connector names and topics; each `Destination` builds its sink configuration. `ConnectorManager` applies them, refreshes status and publishes snapshot signals through `SignalProducer`.

```php
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;

$connectors = app(ConnectorManager::class);

$connectors->activate($sync);        // push both configs, resume them and mark the synchronization active
$connectors->pause($sync);           // pause the sink connector while the source keeps capturing its table
$connectors->restart($sync);         // restart the failed tasks of both connectors
$connectors->delete($sync);          // delete the sink connector and rescope the source connector
$connectors->refreshSource($record); // push the source configuration after a credential change
$connectors->checkStatus($sync);   // update the sink Connector record
$connectors->checkStatus($record); // update the source Connector record
```

Both `Connection` and `Sync` store a nullable `connector_id`; their `connector` relation returns a `Connector` model, or `null` before any configuration has been applied or status checked. The model stores the Kafka Connect `name`, `status`, `error`, `checked_at` and the last successfully applied `config`. Configuration is a JSON object exposed as `array<string, string>`; because it includes database credentials, Laravel encrypts it in a `text` column using `encrypted:array` and excludes it from model serialization. A status check does not change the stored configuration.

A source connector failure concerns all synchronizations of its connection, while a sink connector failure concerns its synchronization alone. A connector that reports itself as running while one of its tasks has failed is recorded as failed together with the trace of that task, so a stalled transfer stays visible. Both states are shown as badges in the Filament tables and can be refreshed from there. Deleting a synchronization removes only its sink connector; the source connector is owned by the connection and is removed with it.

Connectors pin their converters instead of relying on worker defaults. The sink unwraps Debezium envelopes, applies the configured transform pipeline and renames fields. Its `fields.whitelist` includes only its own mapped columns, constants and fixed values, so synchronizations reading the same source table do not write each other's columns.

Debezium only snapshots a source connector's tables once, when its replication slot is first created; widening `table.include.list`/`column.include.list` for an already running connector does not back-fill the rows that existed before the change. The source connector therefore always runs with `read.only=true` and `signal.enabled.channels=kafka`, and `ConnectorManager::activate()` compares the connector's `column.include.list` before and after pushing the new configuration. When the diff exposes new columns, including those of a newly captured table or a record filter, it publishes an `execute-snapshot` signal for each affected table through its `SignalProducer`, keyed by the connector's `topic.prefix` as Debezium's Kafka signal channel requires. The very first synchronization of a connection never needs a signal, since Debezium's own initial snapshot already covers it.

## Configuration

`phpinnacle-ferry.connection` selects the database connection for Ferry's models and dynamic destination tables. Static destinations may select their own connection. `phpinnacle-ferry.target_host` optionally overrides the database host seen by the JDBC worker, leaving Laravel's connection settings unchanged. Connector activation supports PostgreSQL sources and PostgreSQL JDBC destinations.

`phpinnacle-ferry.structure` tunes the preparation pipeline: `chunk_size` root objects per job, the job `timeout`, the retry `backoff` delays, and `stale_after` seconds before a silent run is considered interrupted. `phpinnacle-ferry.sync.table_prefix` sets the name prefix of synchronization destination tables. `phpinnacle-ferry.kafka_connect` points at the Kafka Connect REST API: `base_uri` is read from `FERRY_KAFKA_CONNECT_URL` and is required before any connector operation, `headers` are sent with every request. Configure HTTP timeouts on the application's PSR-18 client. `phpinnacle-ferry.kafka_connect.signal` configures the Kafka signal channel used for ad hoc incremental snapshots: `topic` and `bootstrap_servers` are read from `FERRY_KAFKA_SIGNAL_TOPIC` and `FERRY_KAFKA_BOOTSTRAP_SERVERS`, `group_id` defaults to `ferry-signal`, and `flush_timeout` bounds how long publishing a signal may block. Publishing signals requires the `ext-rdkafka` PHP extension.

## Field mapping

`FieldMapping` connects the named properties of two inline JSON Schema objects. Configuration accepts arrays or Filament-evaluated closures. Property names are stable identifiers, including numeric names and names containing dots.

```php
use PHPinnacle\Ferry\Forms\FieldMapping;

FieldMapping::make('mapping')
    ->jsonSchema(
        source: ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'title' => 'Display name'],
            'email' => ['type' => 'string'],
            'phones' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]],
        dest: ['type' => 'object', 'required' => ['contact'], 'properties' => [
            'contact' => ['type' => 'string', 'title' => 'Contact name'],
            'phone_numbers' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]],
    );
```

Required destinations come from the destination object's `required` list. Array destinations derive their multiple-connection capability from `type: array` and `items`.

### State and connections

State separates source settings from destination mappings. Each destination entry has an explicit `kind`:

```php
[
    'sources' => [],
    'targets' => [
        'contact' => ['kind' => 'source', 'source' => 'name'],
        'phone_numbers' => ['kind' => 'source', 'source' => 'phones'],
    ],
]
```

An array destination supports two mutually exclusive forms:

- Whole array: `['phone_numbers' => ['kind' => 'source', 'source' => 'phones']]` connects the source array as one value.
- Individual items: `['phone_numbers' => [['kind' => 'source', 'source' => 'name'], ['kind' => 'source', 'source' => 'email']]]` collects one value from each source, checked against `items`. Settings remain associated with their source, independently of the item collection.

Connecting a whole array replaces the individual items; connecting an item replaces a whole array. Arrays are never flattened. An array source can itself become an item when `items` describes a compatible array. Each source permits at most one connection. Click a line to remove it, or focus it and press Enter or Space.

`inverse()` only swaps the columns: destinations appear on the left and sources on the right. State, static editors, cardinality, and source settings stay the same, so switching orientation requires no data conversion.

### Compatibility and output schemas

Schema compatibility is directional: declared source values must fit the destination's supported constraints. Checks include types, nullability, formats, string lengths, alternatives, and nested array items. An omitted type imposes no type restriction; other supported constraints still apply. Type names match exactly, so numeric widening requires an explicit transformation. A formatted string can connect to an unrestricted string. A destination with a format requires the same source format: UUID cannot connect to `date` or `date-time`, and `date` cannot connect to `date-time`.

String bounds use JSON Schema's `minLength` and `maxLength`. A source maximum of 255 cannot connect to a destination maximum of 64; 64 can connect to 255 or an unbounded destination. A missing source maximum is unbounded, and a missing minimum is zero. Formats do not imply length bounds. Composed `allOf` bounds intersect; every source alternative must fit a destination alternative.

Both optional checks are enabled by default. Configure them per field with an immutable `SchemaCompatibility` value:

```php
use PHPinnacle\Ferry\Forms\SchemaCompatibility;

FieldMapping::make('mapping')
    ->jsonSchema(source: $sourceSchema, dest: $destinationSchema)
    ->compatibility(
        SchemaCompatibility::make()
            ->formats()
            ->stringLengths(false)
    );
```

`formats(false)` disables format checks; `stringLengths(false)` disables both length checks. Type, nullability, and array-item checks remain active. Add application restrictions with `canConnectUsing()`:

```php
use PHPinnacle\Ferry\Forms\JsonSchema;

->canConnectUsing(fn (JsonSchema $source, JsonSchema $target) =>
    ($source->toArray()['x-domain'] ?? null) === ($target->toArray()['x-domain'] ?? null)
)
```

The callback receives effective source and destination property schemas, or the destination item schema for an item connection. It runs only after the configured checks pass and cannot override their rejection. `toArray()` exposes each inline definition, including application metadata. The same server-computed connection modes drive client availability and form validation.

The component projects inline `type`, `format`, `minLength`, `maxLength`, `items`, `required`, `properties`, `allOf`, `anyOf`, and `oneOf` definitions. It does not resolve `$ref`, validate runtime payloads, or prove arbitrary JSON Schema containment. Other constraints such as `enum`, `pattern`, numeric bounds, item counts, and uniqueness are displayed as metadata but are not enforced by connection compatibility; native static editors remain responsible for their value validation.

Use `outputSchema(array|Closure)` for the effective source object schema after applying source settings. It replaces the input schema for source type chips, constraint hints and compatibility checks. Declare only formats and bounds guaranteed by the transformation: a logical string output alone does not preserve an email/UUID format or establish a length limit. The consumer supplies this schema; the field does not execute transforms or infer their guarantees.

### Static values

Static values use entries such as `['contact' => ['kind' => 'value', 'value' => 'Customer']]`. Without a matching `staticForms()` editor, only non-empty strings are accepted. With an editor, its native rules and casts define accepted values. Static values count as mapped without a source or line in either orientation; the consumer owns domain validation.

Use `staticForms()` to assign an inline Filament editor to a JSON Schema type:

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

FieldMapping::make('mapping')
    ->jsonSchema(source: $sourceSchema, dest: $destinationSchema)
    ->staticForms([
        'string' => fn () => TextInput::make('value')->hiddenLabel()->required()->maxLength(120),
        'boolean' => fn () => Toggle::make('value')->label('Enabled')->default(false),
    ]);
```

Each factory returns a fresh field named `value`. It can receive the destination identifier as `$target` and the matched schema type as `$type`. The component selects the non-null type of a nullable property. Every destination with a matching factory shows an editor; unregistered types and unions with several non-null types offer connections only. There is no fallback text editor or static-value checkbox.

An unconnected destination immediately shows its configured editor and becomes a static value initialized with the field's default. Every visible editor counts as mapped, including an empty one. Its native validation, state casts, and dehydration callbacks run on submission; `required()` can reject an empty editor. Values retain their field's type, including `false`, zero, arrays, and `null` when allowed. Configure the factory's rules for the values your consumer accepts; JSON Schema payload constraints are not inferred.

Livewire uses encoded source and destination keys internally so dots and numeric names remain literal identifiers. Fill the form with the public `sources`/`targets` state; submitted `getState()` uses the same shape and preserves typed values. Editor templates are rendered up front; Alpine mounts or removes them when connections change, without a server request. Connecting a source replaces and hides the static editor. Removing the destination's final connection restores it with the configured default. Native live fields and actions still make their usual requests.

### Source settings and transforms

Both `FieldMapping` and `FieldBinding` accept `sourceSettings(array|Closure)`: a native Filament schema displayed inside every source card, including unconnected or unbound sources. A closure can receive the literal source identifier as `$source`. Each source has a separate child schema with its own defaults, validation, relative `Get`/`Set`, callbacks, and dehydration. Use `live()` on fields that must recompute other server-rendered configuration.

```php
->sourceSettings(fn (string $source) => [
    TextInput::make('alias')->default($source)->required(),
    Toggle::make('enabled')->default(true),
])
```

Settings are submitted under `sources[source]` and remain there when a connection or binding is removed. `getSourceSettings()` reads current source-keyed settings for callbacks such as `outputSchema()`, before native child dehydration. The components do not interpret settings or move them into their connection entries.

For the Ferry transformation editor, use the standalone `FieldTransforms` field:

```php
use PHPinnacle\Ferry\Forms\FieldTransforms;

->sourceSettings(fn (string $source) => [
    FieldTransforms::make('transforms')
        ->label($source)
        ->object($object)
        ->inputSchema($sourceSchema['properties'][$source]),
])
```

`object()` accepts the selected `?ConnectionMetadata`, which is passed to transformation forms. `inputSchema(array|Closure)` supplies the original source property definition. The field passes this complete schema through `TransformRegistry::resultSchema()` to determine compatible additions. Mapping consumers use the same result in their `outputSchema()` callbacks for hints and connection checks.

Built-in scalar transforms reject arrays and objects; custom descriptions can accept them through their input schemas. Cast accepts unions of scalar types, including null. The built-in string transforms require a known string type, optionally nullable, and reject logical date/reference formats. `FieldTransforms` owns the ordered `key`/`params` state, native parameter validation, schema compatibility checks, menus, chips and ordering described above. Neither mapping component interprets this format or requires a transformation registry.

### Presentation and assets

Property `title`, `description`, `x-icon`, and `x-color` provide presentation metadata for mapping cards and binding sources. `x-color` accepts a registered Filament color name or a literal hex/RGB color for the icon and title. Cards display the literal property name, required marker, constraint hints, type chips, description and warnings; omitted icons reserve no space. Formats replace the general type label, nullable types add a `null` chip, and enum tooltips list allowed values. `x-badges` accepts a list such as `[['label' => 'Imported', 'color' => 'info']]`; the type chip appears last. For framework schema builders, pass their inline `toArray()` result.

Array chevrons expand item schemas and their scoped constraints, one row per nested level. Field constraints remain in the heading. Details start collapsed and work on connected or disabled cards without changing selection; lines follow card resizing. Source actions appear below the metadata. Colors use Filament's `primary` palette for connections, `warning` for required destinations and `danger` for conflicts, in light and dark themes.

`extraSourceActions([...])` renders native Filament actions on each source, including disconnected sources and inverse mode. Each receives the literal source name in its `source` argument and respects action visibility. Use `iconButton()` and `tooltip()` for compact controls.

During selection, compatible candidates highlight with a check; incompatible cards and their editors dim with a cross, and existing lines fade. Compatible destinations can replace an existing connection or static value. Unavailable clicks preserve the selection and mappings without an error. Outside selection, connected cards retain full contrast and existing incompatible connections remain visible for correction.

Both components support these presentation options:

| Option | Default | Behavior |
| --- | --- | --- |
| `labels(source: ..., dest: ...)` | Source / Destination or Binding | Set the column headings. |
| `searchable()` | `false` | Show field search inputs. |
| `contained()` | `true` | Show the outer border, background, and toolbar with its filter and counters. |
| `compact()` | `false` | Reduce padding and gaps; use 56 px minimum cards instead of 80 px. |

Labels accept strings or closures; the switches accept booleans or closures. Containment uses Filament's `CanBeContained`, and compact spacing uses `CanBeCompact`. `contained(false)` preserves column headings, optional search, and editors. Neither component has a footer. All combinations preserve form values.

Use `warnings(source: [...], dest: [...])` on `FieldMapping` or `warnings([...])` on `FieldBinding` to highlight fields. Both accept closures and retain warnings for unavailable identifiers. Escape cancels mapping selection; keyboard controls and dark mode are supported.

The fields use package views without registering `FerryPlugin`. Filament includes their shared stylesheet in the page head so cards are styled before the first render; JavaScript loads on demand. Publish assets after changes with `php artisan filament:assets`. JavaScript and CSS are distributed as source files and need no package build. Add the views to your application's Filament theme and rebuild it:

```css
@source '../../../../vendor/phpinnacle/ferry/resources/views/**/*.blade.php';
```

The path above assumes the theme lives at `resources/css/filament/admin/theme.css`.

## Field binding

`FieldBinding` displays configured sources beside their binding editors and supports the presentation options above. Click **Bind** to activate a source and initialize its editor defaults, or **Unbind** to remove it. Unbound sources are omitted from `bindings`; their `sources` settings remain available. Disabled fields disable both actions and their child inputs.

Use `simple()` with a single Filament field to store source identifiers mapped to non-empty strings:

```php
use Filament\Forms\Components\TextInput;
use PHPinnacle\Ferry\Forms\FieldBinding;

FieldBinding::make('bindings')
    ->jsonSchema(['type' => 'object', 'properties' => [
        'name' => ['type' => 'string', 'title' => 'Customer name'],
        'email' => ['type' => 'string', 'title' => 'Email address'],
    ]])
    ->simple(TextInput::make('destination')->required());

// ['sources' => [], 'bindings' => ['name' => 'customer.name', 'email' => 'contact.email']]
```

Use `schema()` to store each binding as an associative array whose keys and values are defined by its child components:

```php
use Filament\Forms\Components\Select;

FieldBinding::make('bindings')
    ->jsonSchema(['properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']]])
    ->schema([
        TextInput::make('destination')->required(),
        Select::make('transform')->options([
            'trim' => 'Trim',
            'lowercase' => 'Lowercase',
        ]),
    ]);

// ['sources' => [], 'bindings' => ['name' => ['destination' => 'customer.name', 'transform' => 'trim']]]
```

Choose `simple()` or `schema()` for a field. Both accept closures and use native Filament child schemas, including defaults, validation, reactive callbacks, relative state reads, and nested `Repeater` or `Builder` components. `destination` is an example field name, not a reserved key. Destination syntax, duplicate destinations, and transformation behavior belong to the consuming application; the component stores the configured data without interpreting paths or executing transformations.

Use `constants()` with `simple()` to add static values: destinations configured without a source field. It takes the child fields (or a closure returning them) of one static value row, which must include a `column` field naming the destination; the remaining fields describe the value. The component lists such rows under the sources with **Add static value** and a remove button, never as fictitious sources, and dehydrates each row as `column => remaining fields` inside `bindings`, next to the source bindings:

```php
FieldBinding::make('bindings')
    ->jsonSchema(['properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']]])
    ->simple(TextInput::make('column')->required())
    ->constants(fn () => [
        TextInput::make('column')->required(),
        TextInput::make('value')->required(),
    ]);

// ['sources' => [], 'bindings' => ['name' => 'customer_name', 'status' => ['value' => 'active']]]
```

Without `constants()` the component rejects such rows on submission, and the bound-sources counter never includes them.

Source bindings and static values share one serialized map. Static `column` names must be unique and must not match a bound source identifier; the component rejects collisions before serialization so no entry is overwritten. Uniqueness of the actual destinations entered in source binding fields remains the consumer's responsibility.

Use `extraSourceActions([...])` to render Filament actions in every source card, including unbound sources; each action receives the source identifier as its `source` argument.

`jsonSchema(array|Closure)` supplies the source object schema and the same property metadata as `FieldMapping`. Its `required` annotations describe source properties; they do not require the user to bind them. The native `schema()` method continues to describe the per-binding Filament editor. Source identifiers retain their literal identity, including numeric identifiers and dots. `labels(source: 'Source', dest: 'Binding')` sets the headings and accepts closures. Configuration is trusted developer code; source identifiers and binding shapes are validated on submission, while the child fields define the value validation.

`getBindingValues()` reads the current source-keyed bindings and column-keyed constants for callbacks before native child dehydration. Submit the parent schema with `getState()` to run validation and return the public `sources`/`bindings` state. Internal Livewire keys are encoded to preserve dots and numeric identifiers; fill forms using the public state shown above. Package views, theme setup and asset publishing are shared with `FieldMapping` as described above.

## Testing

```bash
vendor/bin/pest --no-coverage --configuration packages/ferry/phpunit.xml.dist
node --test packages/ferry/tests/Unit/*Test.js
```

Run these commands from the monorepo root. The PHP suite covers structure publication and stale runs, transactional table changes, destination constraints, connector requests, transformation rules, authorization, and form submission. It uses SQLite and transport doubles; no live 1C database or Kafka cluster is required. The JavaScript suite covers connection selection, cardinality, static values, preservation of source settings, transform ordering, and visible connection geometry using explicit server-provided connection modes. PHP and JavaScript suites run independently; the JavaScript suite needs no PHP application or asset build.

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
