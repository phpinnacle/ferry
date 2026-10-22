# Ferry for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/ferry.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/ferry)

Ferry manages 1C database connections, source structure snapshots, synchronization mappings, and Kafka Connect connectors in Filament. It also provides reusable `FieldMapping` and `FieldBinding` form fields.

## Features

- `Connection` model with an encrypted password that is never returned to the interface.
- `ConnectionFactory` service that opens a temporary connection with the current (possibly unsaved) form values, runs a lightweight query and reports a sanitized result.
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

A synchronization selects a connection with a ready structure and one object from its published snapshot, then maps source fields (system fields by key, properties by id) to unique snake-case column names. Saving creates a physical destination table named `{prefix}{code}` in the Ferry connection; every table carries a single system column `idrref`, the primary key holding the 1C row identifier, followed by the nullable mapped columns typed from the Rosetta fields.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the synchronization drops the table after a data-loss confirmation. When a structure refresh removes the source object, removes a mapped field or changes a field type, the synchronization is paused automatically; the table and its stored rows stay untouched.

A change of logical column type drops and recreates that column, so it requires the same data-loss confirmation as removing a mapping.

The model owns this lifecycle regardless of the caller: creating a `Sync` creates its destination table, changing its `schema` applies the difference and deleting it drops the table, so a synchronization created from a command, job or seeder behaves exactly like one created from the panel.

`SyncSchema::fromBindings($object, $bindings, $registry, $destination)` converts validated form bindings into typed mappings and checks them against the source metadata and optional static destination. `Sync::brokenColumns($object, $destinationFields)` and `hasValidSchema($object, $destinationFields)` compare the saved mapping with current declarations; `shouldResume($object, $destinationFields)` applies the automatic-resume policy. These methods accept only a list of typed `DestinationField` values, or `null` for a dynamic or unavailable destination; the model distinguishes them by its saved `type`, cast to the `DestinationType` enum. Callers resolve registrations and pass their fields, so the model does not depend on a registry or connector adapter. `schemaChanges()` compares the original and current typed schemas and plans column additions, renames, and removals in mapping order. `droppedColumns()` exposes the removals for confirmation; `SyncTableManager` applies the same plan, preserving stored values on renames. Confirmation fills a copy of the record to preview changes. A single `SyncObserver` coordinates table and connector lifecycle operations.

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

The readonly `key` property identifies the destination in the persisted synchronization. `table` is its target table, and `fields` lists the only columns an administrator may map together with their logical type and whether they are required. `StaticDestination` implements Filament's `HasLabel` contract for its display label. The connection, primary key and fixed values belong only to the static destination (a `null` connection means the Ferry connection). Fixed values are written into every row through `InsertField` transforms. Both implementations compose `JdbcConnectorConfig` to serialize the shared JDBC protocol settings without inheriting behavior or state from each other.

`ColumnType::jsonSchema()` projects a logical type into the inline type and format used by the mapping editor; references remain distinct from strings, and dates use the `date-time` format.

`Sync.type` explicitly selects `DestinationType::Static` or `DestinationType::Dynamic` (the default). `Sync.destination` stores the registered key for static synchronizations or the generated table name for dynamic ones. `DestinationFactory::get($key)` returns the registered destination or `null`. Use `getOrFail($key)` when a static destination is required. `DestinationFactory::resolve($sync)` returns the registered static destination or a `DynamicDestination` for its generated table. `fieldsFor($sync)` returns the registered static destination's fields, or `null` for a dynamic or unavailable destination. `SyncObserver` initializes only a dynamic record's destination table name when created; static records retain their registered destination key.

Saving a static synchronization accepts only declared fields, only source fields of the same logical `ColumnType` (`Reference` covers 1C references and identifiers), requires every required field, forbids mapping one field twice and rejects the source key field, which always feeds the configured primary key. Ferry never creates, alters or drops a static table, and its sink connector upserts rows without propagating source deletions. Removing a destination from the registry while synchronizations still point at it never makes them write elsewhere: the next structure review pauses them automatically, they cannot be activated or saved until the destination is registered again, and their whole mapping is reported as out of date.

The synchronization form has one destination select: Dynamic followed by the registered static destinations. Selecting a different destination clears the field mapping; the saved synchronization keeps its explicit type and destination key. A dynamic synchronization lists every mappable 1C field with its title and technical column and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections.

The static synchronization form adds an exact-format connection condition to preserve its logical `ColumnType` contract: references and dates require an explicit Cast before connecting to a plain string destination. The reusable `FieldMapping` component keeps its more general directional format compatibility described below.

## Transforms

A transform changes data on its way to the destination as a Kafka Connect transform of the sink connector. Editor descriptions and execution handlers share a stable key but are registered separately. `Transformation` is an immutable description with Filament-style `label()`, `icon()`, `color()`, `description()`, and `schema()` methods. `key()` identifies it and `scope()` returns `TransformScope::Record` or `TransformScope::Field`. It implements Filament's `HasLabel`, `HasIcon`, `HasColor`, and `HasDescription` contracts; icons and descriptions are optional.

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
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

Transformation::make('replace', TransformScope::Field)
    ->inputTypes([ColumnType::String])
    ->label('Replace text')
    ->color('warning')
    ->schema(fn () => [TextInput::make('replacement')->required()]);
```

Labels, icons, descriptions, and colors accept values or closures. `color()` defaults to `primary` and accepts a Filament color name, a shade palette such as `Color::Emerald`, or `null`. Source transform chips and Builder block labels use `getColor()`; Builder labels render through the native Filament badge component. `schema()` accepts an array of Filament schema components or a closure; a schema closure can receive the selected `?ConnectionMetadata $object`. `getSchema($object)` returns fresh components for each form. Fluent methods return a new description, leaving the original unchanged. The reusable descriptions in `Transformations` contain the package's forms; they do not construct execution handlers.

Editor type behavior also belongs to `Transformation`. `inputTypes([ColumnType::String])` restricts accepted inputs; without it the description accepts any logical type, including an unknown type represented by null. Output preserves the input type by default. Use `outputType(ColumnType::Boolean)` for a fixed result or a callback for a setting-dependent result:

```php
->outputType(fn (?ColumnType $input, array $params) => ColumnType::from($params['type']))
```

`accepts($input)` and `getOutputType($input, $params)` read these rules. Type callbacks return null when a setting cannot produce a supported output. Cast returns its configured target type; repeated casts consume the preceding result, including numeric results. Forms, menus, and editor type previews use descriptions without resolving execution handlers or requiring their configuration. Parameter validation in `FieldTransforms` runs the description's native Filament schema, including relative field rules.

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

Custom handlers implement `Contracts\TransformHandler`: `normalize()` completes parameters from object metadata, `rules()` validates parameters at the synchronization boundary, `sourceColumns()` declares additional reads, `resultType()` determines the produced logical type given valid settings and returns null for unsupported inputs, and `connector()` builds Kafka Connect settings. Handlers have no Filament labels or forms and resolve through Laravel's container only when needed. They do not declare editor availability.

`TransformFactory::get($key)` and `forScope($scope)` read the editor descriptions registered through the plugin. `resultType($input, $settings)` and `resultTypes($types, $fieldTransforms)` compute editor previews from those descriptions and return an unknown result when a step is incompatible. `handler($key)` and `handlerOrFail($key)` resolve the configured handler independently of that catalog. Saving a synchronization validates its parameters and resulting types through handlers; connector generation, runtime availability checks, and source-column discovery also use configured handlers. Workers can execute saved transforms without registering descriptions or booting a Filament panel. Hiding a transform from the editor does not disable its saved pipelines; remove its handler configuration to make it unavailable at runtime.

Transform settings are stored as `key`/`params` pairs. A synchronization stores ordered record transforms in `Sync::$transforms` and field transforms on each `FieldMapping`, with the logical `result` type they produce. `SyncSchema::fromBindings()` and `SourceFactory` receive the factory explicitly. `SyncSchema::fromBindings()` validates field parameters and compares the produced type with the destination; `SyncSchema::fromTransforms()` validates and normalizes record transforms. `TransformFactory::pipeline()` runs record and field transforms on source column names after `unwrap`, before renames, preserving Debezium keys and deletions. Static values remain separate mappings and are inserted after renames. Saving changes refreshes the synchronization's sink connector; the shared source connector changes only when its capture configuration changes. Structure review pauses a synchronization whose configured handlers or required source columns are no longer available, and connector activation refuses it.

The built-in `CastTransform` casts a field to a `ColumnType`. `ColumnType::castType()` exposes its Kafka Connect type and is `null` for references and dates, which Kafka Connect cannot cast. Static values use the separate mapping mechanism: `Data\StaticValue` stores their type and value in the mapping's `constant` property, outside its transform list. They are validated as static values and compiled to `InsertField` plus a cast when needed, without registering a transform handler. Numeric sources can be cast to any supported target type.

`TrimTransform`, `SubstringTransform`, and `MaskFieldTransform` are optional field transforms, offered in the editor explicitly rather than enabled there for every installation. All accept string inputs and preserve their logical type. The selected source field is supplied by the mapping; administrators do not enter a second field name.

| Key | Settings form | Kafka Connect class |
| --- | --- | --- |
| `trim` | No settings; trims whitespace from both ends of the selected string. | `org.phpinnacle.toblerone.TrimTransform$Value` |
| `substring` | Required zero-based `start`, optional exclusive `end`, shown side by side on large screens. A blank end is omitted; zero is retained. | `org.phpinnacle.toblerone.SubstringTransform$Value` |
| `mask` | Required `replacement`, default `***`, replacing the entire string. | `org.apache.kafka.connect.transforms.MaskField$Value` |

Trim requires the Toblerone plugin and preserves null field values and tombstones. Substring serializes indexes as `fields=source:start[:end]` and requires the Toblerone plugin on the worker. It uses Java UTF-16 indexes without clamping: indexes must fit every input string. Masking uses Apache's `fields` and `replacement` settings and skips tombstones through the built-in predicate. Other MaskField options keep their Kafka defaults. Forms validate the settings; Ferry does not execute the transformations in PHP.

A `Transformation` declares `getSchema($object)`, the Filament components that edit its parameters given the selected 1C object (or `null` before one is chosen), while its handler declares `rules()`, the Laravel rules that validate parameters on save. The synchronization form configures record transforms separately, in a **Record transforms** block above the field mapping: a Filament Builder with an ordered list of transform blocks. Each block displays its icon, label, optional description, and settings. Record transforms can repeat and their blocks can be reordered or removed. The Builder adapts its internal `type`/`data` state to the public `key`/`params` format on fill and submission, including empty settings arrays.

Every scalar source field has an **Add transform** menu with a sparkles icon at the bottom left, provided by `FieldTransforms::make('transforms')` inside `sourceSettings()` on both `FieldBinding` and `FieldMapping`. The menu contains registered transforms accepting the current chain's output type. Boolean sources offer Cast; after casting to string, Trim, Substring, and Mask become available. Compatible transforms remain in the menu after use and can repeat. Arrays and objects offer no scalar transformations. Transforms with settings open an individual form with their icon and optional description. Transforms without settings, such as Trim, are added immediately. Added transforms appear as colored chips in execution order. Each chip has a larger label area and a separate inset remove button. Click a configurable label to edit its settings; labels without settings open no modal. Remove every transform through its chip; settings modals contain no deletion action. Drag the label area to reorder chips; Alt+Left and Alt+Right provide keyboard reordering. Existing chips remain editable and removable after an incompatible edit or reorder; validation rejects unsupported steps in the submitted chain, and no additions are offered until it is corrected. These controls remain available before connecting or binding a source.

`FieldMapping` and `FieldBinding` keep transform settings in `sources[source].transforms`, independently of their connections or bindings. `SyncForm` combines these settings with connected or bound fields on save and restores them on fill; settings for unused sources remain in the editor but are not persisted in the synchronization. Static values have their own mapping controls. Several transforms of one field apply in chip order, and the static mapping checks a connection with the type its source field has after those transforms, so a cast makes a string field connectable to a boolean destination.

The built-in `FilterTransform` is the record transform of the package: it keeps (`include`) or drops (`exclude`) the records whose 1C field equals a value of a string, reference or boolean type (numbers are emitted as decimals, which a JSON Path condition cannot compare), through Confluent's `Filter$Value` with a JSON Path condition on the source column. The field is picked from the fields of the selected object. `TransformHandler::normalize()` runs when the form is saved and lets a transform complete its parameters from the object: the filter takes the type of its column from the object's metadata, never from the form, so the value is validated and written as a literal of the real type. Because the shared source connector captures only mapped columns, a transform declares the extra columns it reads through `TransformHandler::sourceColumns()`, which `SyncSchema::fromTransforms()` requires to exist in the object and `SourceFactory` adds to `column.include.list`; the destination never receives them, since the sink whitelist lists only mapped columns. Records without the field are dropped by `include` and kept by `exclude`, so a missing field never fails the task, and the filter is attached to the `tombstone` predicate negated: Debezium deletions pass untouched. A transform requests this with the `predicate` and `negate` properties, and `JdbcConnectorConfig` then defines the Kafka `RecordIsTombstone` predicate. The form resets the transforms when the connection or the object changes.

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

Because the target Kafka Connect cluster is shared, the connectors pin their own converters instead of relying on worker defaults, and the sink flattens Debezium envelopes with `ExtractNewRecordState` before applying the field renames taken from the synchronization schema. Each sink also restricts itself to its own mapped columns with `fields.whitelist`, so two synchronizations reading the same source table with different field selections never write each other's columns.

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

State separates source settings from destination mappings. Each destination entry has an explicit `kind`:

```php
[
    'sources' => ['name' => ['alias' => 'Customer']],
    'targets' => [
        'contact' => ['kind' => 'source', 'source' => 'name'],
        'enabled' => ['kind' => 'value', 'value' => true],
    ],
]
```

An array destination supports two mutually exclusive forms:

- Whole array: `['phone_numbers' => ['kind' => 'source', 'source' => 'phones']]` connects the source array as one value.
- Individual items: `['phone_numbers' => [['kind' => 'source', 'source' => 'name'], ['kind' => 'source', 'source' => 'email']]]` collects one value from each source, checked against `items`. Settings remain associated with their source, independently of the item collection.

Connecting a whole array replaces the individual items; connecting an item replaces a whole array. Arrays are never flattened. An array source can itself become an item when `items` describes a compatible array. Each source has one connection. Click a line to remove that connection. Focus a line and press Enter or Space to remove it with the keyboard.

`inverse()` only swaps the columns: destinations appear on the left and sources on the right. State, static editors, cardinality, and source settings stay the same, so switching orientation requires no data conversion.

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

Mapping cards on both sides and binding source cards share a schema-oriented metadata layout: an optional leading icon, a title with the literal property name beside it, a required asterisk, compact constraint hints, and monospace type chips, followed by description and warnings. Cards without an icon reserve no icon space. Source actions sit below the metadata at the bottom left; their open menus appear above neighboring cards. Static and binding editors keep their own controls. Array type chips have a chevron that expands their item schemas below the heading, with a row for each nested array level. Item constraints and enum choices appear beside the corresponding inner type; field-level constraints stay in the heading. Item details start collapsed and remain available on connected or disabled sources. The disclosure button does not change connection selection; connection geometry follows card resizing automatically.

Property `title`, `description`, `x-icon`, and `x-color` provide presentation metadata. `x-color` accepts a registered Filament color name or a literal hex/RGB color for the icon and title. Formats such as `uuid`, `email`, and `date-time` replace the general string label; nullable values show a separate `null` chip. Enum chips expose their allowed values in a tooltip. Length and item-count limits use ruler and list icons; numeric bounds, multiples, patterns, and uniqueness appear as compact hints with accessible labels. Composition constraints retain their scope. Array item constraints appear only in the expanded item-type rows. These visual conventions follow Eidos while Ferry remains independent of that package. `x-badges` accepts a list of `['label' => 'Imported', 'color' => 'info']` badges; the type badge appears last. For framework schema builders, pass their inline `toArray()` result.

Use `outputSchema(array|Closure)` for the effective source schema after applying source settings, including resulting formats and length bounds. Only declare bounds guaranteed by the transformation; changing a type alone does not establish a length limit. Source type chips and constraint hints show only the effective output schema; both client interaction and server validation use the same server-computed connection modes. The field does not know how transforms execute.

`extraSourceActions([...])` renders native Filament actions on each source, including disconnected sources and inverse mode. Each receives the source property name in its `source` argument, and action visibility is respected. Use `iconButton()` and `tooltip()` for compact controls. Connected fields retain full contrast outside connection selection. Connection lines, borders, ports, and binding arrows follow the panel's Filament `primary` palette; required destinations use `warning` and type conflicts use `danger`, in both light and dark themes. During selection, compatible candidates have a highlighted card and a check on their port. Incompatible candidates dim as a whole, including existing connections and static editors, and show a cross on their port. Existing connection lines fade while selecting. Compatible destinations can replace their existing connection or static value. Clicking an unavailable candidate leaves the selection and mappings unchanged without showing an error; choose a compatible field or cancel the selection.

Every destination accepts static values, stored as `['contact' => ['kind' => 'value', 'value' => 'Customer']]`. Constants count as mapped without a source or line in either orientation. The component preserves existing static entries; the consumer owns domain validation.

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

Both `FieldMapping` and `FieldBinding` accept `sourceSettings(array|Closure)`: a native Filament schema displayed inside every source card, including unconnected or unbound sources. A closure can receive the literal source identifier as `$source`. Each source has a separate child schema with its own defaults, validation, relative `Get`/`Set`, callbacks, and dehydration. Use `live()` on fields that must recompute other server-rendered configuration.

```php
->sourceSettings(fn (string $source) => [
    TextInput::make('alias')->default($source)->required(),
    Toggle::make('enabled')->default(true),
])
```

Settings are submitted under `sources[source]` and remain there when a connection or binding is removed. `getSourceSettings()` reads the current source-keyed settings for callbacks such as `outputSchema()`. The components do not interpret settings or move them into their connection entries.

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

`object()` accepts the selected `?ConnectionMetadata`, which is passed to transformation forms. `inputSchema(array|Closure)` supplies the original inline source definition, before applying transformations. The field projects a single logical scalar type, including a nullable type and the formats supplied by `ColumnType::jsonSchema()`; arrays, objects, and unions of several non-null types offer no scalar transforms. An unknown input permits only descriptions accepting a null type, such as Cast. `FieldTransforms` owns the ordered `key`/`params` state, parameter and input-type validation, add menu, chips, drag ordering, and settings actions. Neither mapping component knows this format or requires a transformation registry.

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

The component uses the package views and the shared mapping stylesheet. Include the package views in your custom theme and publish Filament assets as described above. Form state is hydrated into internal child schema state and dehydrated to `sources` settings and a source-keyed `bindings` map when the parent schema is submitted.

## Runtime responsibilities

Ferry keeps separate services for external boundaries: `ConnectionFactory` opens and tests source database connections and closes test connections on success or failure, `StructureImporter` imports Rosetta metadata, `SyncTableManager` creates, drops and updates destination tables directly from a `Sync` model, and `DestinationFactory` holds application registrations. `SourceFactory` builds source connector configurations; each `Destination` builds its sink configuration through `connector()` and composes `JdbcConnectorConfig` for shared JDBC settings. Remote lifecycle operations and Kafka transport belong to `ConnectorManager` and `RdKafkaSignalProducer`. Persisted state and mapping decisions belong to the models; submitted schema validation belongs to `SyncSchema`. `SyncForm` converts validated form state into model attributes. `Sync` saves its state and table changes in a transaction; `SyncObserver` updates eligible connectors after commit, independently of the caller. `ConfirmSchemaChangesAction` only presents column-loss confirmation. Resource pages wire the form into Filament's lifecycle.

## Testing

```bash
vendor/bin/pest --no-coverage --configuration packages/ferry/phpunit.xml.dist
node --test packages/ferry/tests/Unit/*Test.js
```

Run these commands from the monorepo root. The PHP suite covers structure publication and stale runs, transactional table changes, destination constraints, connector requests, transformation rules, authorization, and form submission. It uses SQLite and transport doubles; no live 1C database or Kafka cluster is required. The JavaScript suite covers connection selection, cardinality, static values, preservation of source settings, transform ordering, and visible connection geometry using explicit server-provided connection modes. PHP and JavaScript suites run independently; the JavaScript suite needs no PHP application or asset build.

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
