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

Ferry interprets Rosetta properties as independently mappable physical source columns. Single references use `_fld…rref`; system fields already contain their physical names. Composite properties expand into separate source cards for the discriminator (`_type`), any boolean (`_l`), number (`_n`), date/time (`_t`), string (`_s`), and reference identifier (`_rrref`) columns. A reference type code (`_rtref`) is included when the composite permits multiple reference targets. A composite with one reference target uses `_rrref` without `_rtref`. Nested type definitions are expanded before resolving columns.

Each card has its own type, transforms, and optional destination. Bind any subset: choosing an identifier does not automatically select its discriminator or reference type code. Debezium exposes the binary discriminator and type code as fixed-length hexadecimal strings of 2 and 8 characters. Reference identifiers retain Ferry's reference type. The property metadata remains unchanged; persisted source identifiers use the property id for ordinary fields and `<property-id>:<component>` for composite columns, such as `supplier-id:rrref`. These identifiers survive physical column renames. `ConnectionMetadata::mappableFields()`, `field($source)`, and `physicalColumn($source)` all resolve the same source-column definitions.

`publishedMetadata()` returns the root objects of the published revision; `publishedObject($source)` finds one of them by its external id and returns `null` when absent. Use the `metadata()` relation to reach every stored revision. `is_active` states whether the connection may be used at all, while `status` only reports whether its local structure is ready.

When a run fails for good, the connection switches to `StructureStatus::Failed`, keeps its previous snapshot, stores a sanitized description in `last_error` and reports the original exception through `report()`. An interrupted run that stops sending heartbeats is retired on the next preparation attempt. `Connection::canPrepareStructure($staleAfter)` and `prepareStructure($staleAfter)` accept the staleness threshold in seconds; the panel action reads it from `phpinnacle-ferry.structure.stale_after`.

## Synchronizations

A synchronization selects a connection with a ready structure and one object from its published snapshot, then maps source fields (system fields by key, properties by id) to destination columns. For dynamic destinations, column names must be unique, start with a lowercase letter, contain only lowercase letters, digits and underscores, and differ from the reserved `idrref`. The form requires at least one source binding or static value. Saving creates a physical table named `{prefix}{code}` in the Ferry connection, with `idrref` as its primary key and nullable mapped columns typed from the source, their transformations or static values.

String columns use the saved output schema: a positive `maxLength` produces `varchar(n)`, while an absent or zero limit produces `varchar` without a length restriction. Untransformed source strings use Rosetta's length; static strings have no inferred length limit. Transformations such as Mask that discard the source length therefore produce an unbounded column. Changes to string length use `ALTER COLUMN` and preserve stored values, including when the column is renamed. PostgreSQL rejects narrowing a column if existing values exceed the new limit, and the synchronization save rolls back.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the synchronization drops the table after a data-loss confirmation. When a structure refresh removes the source object, removes a mapped field or changes a field type, the synchronization is paused automatically; the table and its stored rows stay untouched.

A change of logical column type drops and recreates that column, so it requires the same data-loss confirmation as removing a mapping.

The model owns this lifecycle regardless of the caller: creating a `Sync` creates its destination table, changing its `schema` applies the difference and deleting it drops the table, so a synchronization created from a command, job or seeder behaves exactly like one created from the panel. Saving state and table changes is transactional; eligible connectors update after commit.

`SyncSchema::fromBindings($object, $bindings, $registry, $destination, $transforms)` converts source-to-column bindings and optional field transforms and constants into typed mappings, checking source metadata and the optional static destination. Pass the `TransformRegistry` explicitly. Transform parameters must already be validated by their native Filament forms. Dynamic column-name validation belongs to `SyncSchema::validateColumns($columns, $fail)` and is applied by the form before conversion.

`Sync::brokenColumns($object, $destinationFields)` and `hasValidSchema($object, $destinationFields)` compare saved mappings with current declarations; `shouldResume($object, $destinationFields)` applies the automatic-resume policy. Pass a list of typed `DestinationField` values for a static destination, or `null` for a dynamic or unavailable destination. `schemaChanges()` plans additions, renames, removals, and string length alterations from the original and current typed mappings; `droppedColumns()` exposes removals for confirmation. Table updates use the same plan and preserve stored values on renames.

A pause requested by an administrator and a pause caused by a broken mapping are distinguished by `is_paused`. Saving the edit form resumes only a synchronization that was paused automatically and whose mapping is valid again; a pause requested through the pause action survives editing and requires the activate action. `ConnectorManager::activate()` refuses a synchronization whose mapping no longer matches its source or destination.

## Static destinations

A synchronization writes either into a generated `{prefix}{code}` table (dynamic) or into a table the application already owns (static). `DynamicDestination` and `StaticDestination` independently implement the `Destination` interface, which exposes the target table and `connector($sync, $object, $topic, $transforms)`. Dynamic destinations use the synchronization's generated table name; static destinations describe the application's target and its mapping fields. Register static destinations on the plugin:

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

`ColumnType::jsonSchema()` projects a logical type into the inline schema used by the mapping editor. References use `type: string`, `format: uuid`, and `x-ferry-reference: true`; UUID strings without this annotation remain logical strings. Dates use the `date-time` format.

`Sync.type` explicitly selects `DestinationType::Static` or `DestinationType::Dynamic` (the default). `Sync.destination` stores the registered key for static synchronizations or the generated table name for dynamic ones. `DestinationFactory::get($key)` returns the registered destination or `null`. Use `getOrFail($key)` when a static destination is required. `DestinationFactory::resolve($sync)` returns the registered static destination or a `DynamicDestination` for its generated table. `fieldsFor($sync)` returns the registered static destination's fields, or `null` for a dynamic or unavailable destination. `SyncObserver` initializes only a dynamic record's destination table name when created; static records retain their registered destination key.

Saving a static synchronization accepts only declared destination fields whose logical `ColumnType` accepts the transformed source or static value: types must match, except that `Integer` can feed `Decimal` without a Cast (`Reference` covers 1C references and identifiers). Saving and structure review use the same directional compatibility. Every required field must be mapped, no destination may be mapped twice, and the source key cannot be remapped: it always feeds the configured primary key. Ferry never creates, alters or drops a static table, and its sink connector upserts rows without propagating source deletions. Removing a registered destination causes the next structure review to pause its synchronizations and report their mappings as out of date; saving and activation remain blocked until it is registered again.

The synchronization form has one destination select: Dynamic followed by the registered static destinations. Selecting a different destination clears the field mapping; the saved synchronization keeps its explicit type and destination key. A dynamic synchronization lists every mappable 1C field with its title and physical column name and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections. Static destination mappings enable `staticValues()` to add unconnected string source cards with a full-width input showing Value as its placeholder and transforms. Connect each card to a declared destination; use Cast for numeric or Boolean destinations. Existing typed constants retain their native values when reopening the form. Unconnected static cards remain drafts and are excluded from submitted mappings. Synchronization constants remain subject to the synchronization schema's scalar value and destination type requirements.

The connection, object, and destination selectors use native browser selects with unselectable placeholders. New synchronizations default to Dynamic, the first active connection with a ready structure in name order, and its first object in the displayed order. Changing the connection selects its first available object and clears the mapping. Empty option lists leave the corresponding selection unset. Both synchronization mapping editors use `dense()` for reduced spacing and `compact()` to show descriptions in title tooltips. Dense mode reserves one row for transforms so adding the first transform does not increase the card height; wrapped transforms can expand the card.

Synchronization source schemas retain available Rosetta constraints through `ColumnType::jsonSchema($field)`. Strings with a positive length expose `maxLength`; fixed strings also expose the same `minLength`. Equal lower and upper limits are displayed as one value, such as `9`, instead of `9–9`. Zero or unknown length is unbounded. Non-negative numbers expose `minimum: 0`. Numeric descriptions show the available precision and scale, plus an exact textual range when both are known; large decimal bounds are built without floating-point conversion. Compact cards show this information when their title is hovered or focused. These descriptions summarize type metadata. Rosetta property comments are preserved in the source snapshot but are not included in the synchronization mapping cards. Numeric upper bounds remain textual hints rather than rounded JSON Schema numbers.

The static synchronization form checks exact formats and logical reference types to preserve its `ColumnType` contract: references and dates require an explicit Cast before connecting to a plain string destination. The reusable `FieldMapping` component keeps its more general directional format compatibility described below.

## Transforms

A transform changes data on its way to the destination as a Kafka Connect transform of the sink connector. Editor descriptions and execution handlers share a stable key but are registered separately. `Transformation` is an immutable description with Filament-style `label()`, `icon()`, `color()`, `description()`, and `schema()` methods. `key()` identifies it. It implements Filament's `HasLabel`, `HasIcon`, `HasColor`, and `HasDescription` contracts; icons and descriptions are optional.

### Editor descriptions

`FerryPlugin` provides the built-in cast and field filter descriptions. Register optional descriptions on the plugin:

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
use PHPinnacle\Ferry\Transformation;

Transformation::make('replace')
    ->inputSchema(['type' => 'string'])
    ->outputSchema(['type' => 'string'])
    ->label('Replace text')
    ->color('warning')
    ->schema(fn () => [TextInput::make('replacement')->required()]);
```

Labels, icons, descriptions, and colors accept values or closures. `color()` defaults to `primary` and accepts a Filament color name, a shade palette such as `Color::Emerald`, or `null`, styling source chips. The Add transform picker uses plain labels. `schema()` accepts an array of Filament components or a closure receiving the selected `?ConnectionMetadata $object` and the preceding `?JsonSchema $input`; `getSchema($object, $input)` returns fresh components for each form. Fluent methods return a new description, leaving the original unchanged.

`inputSchema()` describes supported values using the same compatibility rules as `FieldMapping`: types, enums, directional formats, string lengths and array items. Alternatively, pass a predicate `fn (JsonSchema $input) => bool` to define custom compatibility checks. Without an input schema, a description accepts any input. `accepts($input)` checks a `Forms\JsonSchema` value object directly, without projecting it to `ColumnType`.

`outputSchema()` declares a fixed JSON Schema array or a callback receiving the full input schema and settings. For example, a string transform that preserves null values can declare:

```php
use PHPinnacle\Ferry\Forms\JsonSchema;

->outputSchema(fn (JsonSchema $input, array $params) => [
    'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
])
```

`getOutputSchema($input, $params)` returns a `JsonSchema` object. Without an output declaration it preserves the complete input schema, which is appropriate only when the transform guarantees that all its constraints remain true. A callback can return null when its settings are incomplete and no output can be determined. An empty schema array means unconstrained values, not an invalid chain.

Each step consumes the preceding output schema. Cast replaces the value constraints with the selected scalar type and preserves nullability; repeated casts use that result. Trim, Substring and Mask accept strings, including formatted and nullable strings, but reject the `date-time` format and schemas with `x-ferry-reference: true`, which represent logical dates and references. Ordinary UUID strings remain supported. Their outputs retain nullability and discard the original format, enum, pattern and length bounds. Custom descriptions can preserve or establish guarantees their execution actually provides.

Forms, menus and previews use descriptions without resolving execution handlers or requiring their configuration. Parameter validation in `FieldTransforms` runs the description's native Filament schema, including relative field rules. Synchronization mappings persist the final output JSON Schema as `result`; `FieldMapping::type()` derives its logical `ColumnType`, and `stringLength()` reads its maximum string length, including composed schemas. The final output of a source with a target must have a supported scalar column type.

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

Custom handlers implement `Contracts\TransformHandler`: `connector(string $name, string $field, array $params)` builds Kafka Connect settings for the owning source field. Handlers resolve through Laravel's container only when needed. Input/output JSON Schemas belong to the editor description; parameter validation belongs to its native Filament form.

`TransformRegistry` stores the UI descriptions registered through the plugin. Its `get($key)`, `getOrFail($key)` and `all()` methods read that catalog. `resultSchema(JsonSchema $input, array $settings)` passes the full schema through the ordered chain and returns null for an incompatible step or an unavailable output. Forms use this registry without resolving handlers.

`TransformFactory::handler($key)` and `handlerOrFail($key)` resolve handlers from configuration independently of the UI registry. `pipeline($sync, $object, keyColumn: Sync::ID_COLUMN, deletes: false, fixedValues: [])` returns the complete flat Kafka Connect transformation configuration: ordered aliases, `transforms.*` properties, and required tombstone predicates. It owns envelope unwrapping, source transforms, key/value renames, constants and their transforms, and destination fixed values, in that order. Input validation in `SyncSchema` uses only `TransformRegistry`. Workers can execute saved transforms without resolving the UI registry or booting a Filament panel. Hiding a transform from the editor does not disable its saved pipelines; remove its handler configuration to make it unavailable at runtime.

The transformation editor uses Common's `InlineBuilder`. Run `php artisan filament:assets` to publish the shared editor assets.

Transform settings are ordered `['type' => 'mask', 'data' => ['replacement' => '***']]` entries; parameter values are strings, and `data` may be omitted when empty. Each source row in `Sync.schema` stores its ordered transforms and output `result` schema, such as `['type' => 'string', 'maxLength' => 100]`. There is one field transform catalog, without scopes. Native Filament forms validate parameters and type chains; `SyncSchema` checks destination compatibility. The sink applies source chains in schema order, after `unwrap` and before renames. Static values are inserted after renames, followed immediately by each constant’s transforms on its target field. Saving refreshes the sink connector; the shared source connector changes only when its capture configuration changes. Structure review pauses synchronizations with unavailable handlers or required source columns, and connector activation refuses them.

`Sync.transforms` separately stores an ordered `list<TransformSetting>` for record-level settings in a JSON column, defaulting to an empty list. This attribute is storage only: the form does not edit it, and source capture, compatibility checks, and connector pipelines do not interpret or execute it.

### Built-in transforms

The built-in `CastTransform` casts a field to a `ColumnType`. `ColumnType::castType()` exposes its Kafka Connect type and is `null` for references and dates, which Kafka Connect cannot cast. The native Cast selector disables the current type using the output of preceding transforms, including nullable scalar inputs. Unknown inputs or unions with several non-null types keep every supported target available. Repeated casts can alternate between types.

**Replace null with default** controls Kafka's `replace.null.with.default`: it defaults to Yes and uses the default declared in the field schema, preserving null when no default exists. Choosing No stores `replace_null_with_default: "false"`; the default Yes remains implicit. The output schema remains nullable when the input is nullable, because a Kafka field default is not guaranteed.

Static values use rows with `kind: static`, a required `target`, and a native string, boolean, integer or float in `static`. Their type comes from the native value; `1.0` stays a float through JSON serialization. They compile to `InsertField` plus a cast when the native value requires it. Optional `transforms` run next on `target`; their complete output JSON Schema is stored in `result`, like source chains. No separate source name or rename is needed.

Register the optional Trim, Substring, and Mask editor descriptions as shown above. The mapping supplies the selected source field; administrators do not enter a second field name.

| Key | Settings form | Kafka Connect class |
| --- | --- | --- |
| `trim` | No settings; trims whitespace from both ends of the selected string. | `org.phpinnacle.toblerone.TrimTransform$Value` |
| `substring` | Required zero-based `start`, optional exclusive `end`. A blank end is omitted; zero is retained. | `org.phpinnacle.toblerone.SubstringTransform$Value` |
| `mask` | Required `replacement`, default `***`, replacing the entire string. | `org.apache.kafka.connect.transforms.MaskField$Value` |

Trim requires the Toblerone plugin and preserves null field values and tombstones. Substring serializes indexes as `fields=source:start[:end]` and requires the Toblerone plugin on the worker. It uses Java UTF-16 indexes without clamping: indexes must fit every input string. Masking uses Apache's `fields` and `replacement` settings and skips tombstones through the built-in predicate. Other MaskField options keep their Kafka defaults. Forms validate the settings; Ferry does not execute the transformations in PHP.

`FilterTransform` keeps (`include`) or drops (`exclude`) entire records using a JSONPath predicate scoped to the owning source field, including sources without a target. Enter the predicate body: `@ == true`, `@ >= 100`, or `@.price > 100`. `@` denotes the selected field; `$` also refers to that field as the local root. Nested `[?()]` filters retain their `@` item scope. Quoted values and regular expressions are preserved. For example, `@ >= 100` on `_amount` becomes `$[?(@['_amount'] >= 100)]`. Numbers remain literal text, preserving decimal precision.

The predicate runs at its position in the ordered chain, so preceding Trim or Cast transforms affect the value it reads. Filter accepts every input type and preserves its schema. The condition placeholder and hint follow its effective input type: string equality, numeric comparison, Boolean equality, nonempty arrays, or an object child comparison. Nullable types use their non-null type; null-only inputs use `@ == null`, and unknown or mixed inputs use `@ != null`. Changing the input type updates these examples without changing the saved predicate.

The form stores `condition`, `mode`, and `missing_or_null_behavior` (`include`, `exclude`, or `fail`; default `include`). These settings configure [Confluent's `Filter$Value`](https://docs.confluent.io/kafka-connectors/transforms/current/filter-confluent.html). Kafka Connect checks JSONPath syntax; Ferry validates the required expression and enum settings. Confluent applies missing/null behavior to paths outside predicates; missing or null references inside `[?()]` are treated as undefined and do not match. Ferry emits a predicate, so those native predicate semantics apply. Filters skip tombstones through the negated `RecordIsTombstone` predicate, preserving Debezium deletions.

The source connector captures fields with a target or transforms; unbound filter fields are excluded from destination writes by the sink whitelist. The form resets source settings when the connection, object or destination changes. Saved filters using the former `type`/`value` comparison parameters must be reconfigured as predicates; there is no automatic conversion of stored settings.

### Editing synchronization transforms

Synchronization source cards use the reusable [FieldTransforms editor](#source-settings-and-transforms), including before a source is connected or bound. Boolean sources initially offer Cast and Filter; casting to string enables the optional Trim, Substring, and Mask descriptions when registered.

Submitted mappings and bindings contain every offered source, including its `transforms` and nullable `target`. The synchronization editor uses physical source column names such as `_fld17` as JSON Schema property keys. At the form boundary, Ferry translates those names to stable metadata IDs for `Sync.schema` and back when loading; saved bindings and transforms therefore survive physical column renames. The source/static row format and native values stay unchanged. Its native source settings also emit the original `field` snapshot and output `result` JSON Schema for structure review and table creation. Source settings are validated even without a target. Rows with neither a target nor transforms do not participate in capture, execution or structure compatibility checks. Static values have their own mapping controls. Transforms apply in chip order, and static connections are checked against the transformed source schema, so casting a string to boolean enables a boolean destination.

## Kafka Connect connectors

Data actually moves through Kafka Connect. A connection owns a single Debezium PostgreSQL source connector named `ferry-source-{code}`, and every synchronization owns a JDBC sink connector named `ferry-sink-{code}`. Adding synchronizations never creates a second source connector: the shared one captures the union of the tables and columns of the connection's active and paused synchronizations through `table.include.list` and `column.include.list`, and its replication slot and publication are namespaced by connection code so connections never collide on a shared Kafka Connect cluster.

Pausing a synchronization keeps its table in that scope on purpose. The source connector keeps advancing the write-ahead log for the other synchronizations of the connection, so a table dropped from the capture scope would silently lose every change made while the sink was paused.

`ConnectorManager` is the entry point and every operation is idempotent, since configuration is pushed with `PUT /connectors/{name}/config`. `SourceFactory` builds source configurations and supplies connector names and topics; each `Destination` builds its sink configuration. Destinations pass a ready destination-column whitelist and the complete `TransformFactory::pipeline()` configuration to `JdbcConnectorConfig`; the JDBC builder does not add, order, or interpret transformations. `ConnectorManager` applies the configurations, refreshes status and publishes snapshot signals through `SignalProducer`.

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

Debezium only snapshots a source connector's tables once, when its replication slot is first created; widening `table.include.list`/`column.include.list` for an already running connector does not back-fill the rows that existed before the change. The source connector therefore always runs with `read.only=true` and `signal.enabled.channels=kafka`, and `ConnectorManager::activate()` compares the connector's `column.include.list` before and after pushing the new configuration. When the diff exposes new columns, including those of a newly captured table or an unbound field filter, it publishes an `execute-snapshot` signal for each affected table through its `SignalProducer`, keyed by the connector's `topic.prefix` as Debezium's Kafka signal channel requires. The very first synchronization of a connection never needs a signal, since Debezium's own initial snapshot already covers it.

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

State is one list of rows, with every source represented exactly once. A source row contains `kind: source`, its literal `source` identifier, a nullable `target`, and its settings:

```php
[
    ['kind' => 'source', 'source' => 'name', 'target' => 'contact'],
    ['kind' => 'source', 'source' => 'email', 'target' => null],
    ['kind' => 'source', 'source' => 'phones', 'target' => 'phone_numbers'],
]
```

An array destination accepts a whole-array source or several item sources. Each item is a separate source row naming the same target; list order preserves item order. The component derives whole-array versus item mode from the effective source and destination schemas. A string `name` and a string `email` can therefore both name `phone_numbers`, while an array `phones` connects to that target as a whole value.

Connecting a whole array replaces the individual items; connecting an item replaces a whole array. Arrays are never flattened. An array source can itself become an item when `items` describes a compatible array. Each source permits at most one connection. Click a line to remove it, or focus it and press Enter or Space.

`inverse()` only swaps the columns: destinations appear on the left and sources on the right. State, static editors, cardinality, and source settings stay the same, so switching orientation requires no data conversion.

### Compatibility and output schemas

Schema compatibility is directional: declared source values must fit the destination's supported constraints. Checks include types, nullability, formats, string lengths, alternatives, and nested array items. An omitted type imposes no type restriction; other supported constraints still apply. An `integer` source can connect to a `number` destination without a Cast; the reverse requires an explicit transformation. This also applies to unions and nested array items, preserving nullability and enum restrictions. Other type names must match. A formatted string can connect to an unrestricted string. A destination with a format requires the same source format: UUID cannot connect to `date` or `date-time`, and `date` cannot connect to `date-time`.

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

The component projects inline `type`, `format`, `minLength`, `maxLength`, `enum`, `items`, `required`, `properties`, `allOf`, `anyOf`, and `oneOf` definitions. An enum destination requires an explicit source enum whose values are a subset of its allowed values; an unrestricted string or date cannot connect to a Status enum. `allOf` intersects enum constraints and types, treating `number` intersected with `integer` as `integer`; array items use the same checks. Enum equality preserves JSON value types, compares numbers by value, and ignores object property order. It does not resolve `$ref`, validate runtime payloads, or prove arbitrary JSON Schema containment. Other constraints such as `pattern`, numeric bounds, item counts, and uniqueness are displayed as metadata but are not enforced by connection compatibility; native static editors remain responsible for their value validation.

Use `outputSchema(array|Closure)` for the effective source object schema after applying source settings. It replaces the input schema for source type chips, constraint hints and compatibility checks. Declare only formats and bounds guaranteed by the transformation: a logical string output alone does not preserve an email/UUID format or establish a length limit. The consumer supplies this schema; the field does not execute transforms or infer their guarantees.

### Static values

Use `FieldMapping::staticValues()` to add static source cards and connect them manually to existing destinations. The default is disabled; `staticValues(false)` disables the mode. **Add static value** creates an unconnected `static_N` card titled `Static N`, with a full-width string input, Value placeholder, and accessible hidden label. Existing scalar values retain their native types. These cards use the usual compatibility checks, support Inverse mode, and accept the configured `sourceSettings()` schema.

Disconnecting retains the card as a draft. While disconnected, a gray trash action on the right of the footer removes it; the action is hidden while connected. It aligns with the first row of source settings, reserving space so transforms do not shift or overlap it. It appears on card hover or keyboard focus and stays visible on devices without hover. Only connected cards appear in submitted rows, so an empty unconnected Value does not require validation. Connected values must be non-empty scalars.

```php
FieldMapping::make('mapping')
    ->jsonSchema(source: $sourceSchema, dest: $destinationSchema)
    ->staticValues();
```

Static source settings receive the generated identifier as `$source` and a `Closure(): JsonSchema` as `$inputSchema`; normal source settings receive `null` for `$inputSchema`. Pass `fn () => $inputSchema()->toArray()` to `FieldTransforms::inputSchema()`. An `outputSchema()` callback should start from `$component->getSourceSchema()`, which includes the static cards, and apply the supplied settings to determine compatibility. The field does not execute or infer transforms. Submitted static rows retain `kind`, `target`, and `static`, plus their validated source settings; there is no extra source identifier or rename in the public row.

The static-source mode replaces destination-side `staticForms()` editors while enabled. Without `staticValues()`, static values use rows such as `['kind' => 'static', 'target' => 'contact', 'static' => 'Customer']`. Without a matching `staticForms()` editor, non-empty scalar values are accepted. With an editor, its native rules and casts define accepted values. These destination-side static values count as mapped without a source or line in either orientation; the consumer owns domain validation.

Use `withBuiltinStaticForms()` to register standard Filament editors:

```php
FieldMapping::make('mapping')
    ->jsonSchema(source: $sourceSchema, dest: $destinationSchema)
    ->withBuiltinStaticForms();
```

| JSON Schema type or format | Editor |
| --- | --- |
| `string` | `TextInput`; string enums use `Select` |
| `uuid` | `TextInput` with UUID validation |
| `phone`, `email` | Telephone or email `TextInput` |
| `date`, `time`, `date-time` | `DatePicker`, `TimePicker`, `DateTimePicker` |
| `integer`, `number` | Integer or numeric `TextInput` |
| `boolean` | `Toggle`; nullable booleans use a three-state `Select` |
| `array` with string, numeric, or Boolean `items` | Simple `Repeater` with the item's string, numeric, enum, date/time, or Boolean editor |
| Other arrays, nullable arrays, `object` | No built-in editor; register a custom `staticForms()` factory |
| `null` | Read-only null value |

The built-in fields follow destination `required` and nullable types, string length limits, and numeric minimum/maximum bounds. Dates serialize as `Y-m-d`, times as `H:i:s`, and date-times as `Y-m-d\TH:i:sP`. Repeaters use `items` to select and validate each field, apply `minItems` and `maxItems`, and use Filament's distinct validation for string and numeric `uniqueItems`. Boolean items use a Select. Empty arrays are allowed unless `minItems` requires entries. Existing custom type factories take precedence when `withBuiltinStaticForms()` is called after `staticForms()`.

There are no built-in JSON editors. Objects, arrays without a supported scalar item type, and nullable arrays offer connections only unless a custom factory is registered. The installed Filament simple repeater cannot hydrate composite items without changing their values or preserve `null` separately from `[]`.

Use `staticForms()` to assign custom inline Filament editors to JSON Schema types:

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

Each factory returns a fresh field named `value`, or `null` when the property is unsupported. It can receive the destination identifier as `$target`, the matched schema type as `$type`, and the `FieldMapping` as `$component`. The component selects the non-null type of a nullable property; a null-only property matches the `null` factory. Every destination whose factory returns a field shows an editor; unavailable factories, unregistered types, and unions with several non-null types offer connections only. There is no fallback text editor or static-value checkbox.

An unconnected destination immediately shows its configured editor and becomes a static value initialized with the field's default. Every visible editor counts as mapped, including an empty one. Its native validation, state casts, and dehydration callbacks run on submission; `required()` can reject an empty editor. Values retain their field's type, including `false`, zero, arrays, and `null` when allowed. Custom factories must declare their own value validation.

Livewire uses encoded source and destination keys internally so dots and numeric names remain literal identifiers. Fill the form with the public row list; submitted `getState()` uses the same shape and preserves typed values. Editor templates are rendered up front; Alpine mounts or removes them when connections change, without a server request. Connecting a source replaces and hides the static editor. Removing the destination's final connection restores it with a fresh copy of the configured default, so edits to nested values do not change future resets. Native live fields and actions still make their usual requests. Native child actions, including Repeater Add, work on their first request after local connection changes.

### Source settings and transforms

Both `FieldMapping` and `FieldBinding` accept `sourceSettings(array|Closure)`: a native Filament schema displayed inside every source card, including unconnected or unbound sources. A closure can receive the literal source identifier as `$source`. Each source has a separate child schema with its own defaults, validation, relative `Get`/`Set`, callbacks, and dehydration. Use `live()` on fields that must recompute other server-rendered configuration.

```php
->sourceSettings(fn (string $source) => [
    TextInput::make('alias')->default($source)->required(),
    Toggle::make('enabled')->default(true),
])
```

Settings are validated and submitted for every source, including sources with `target: null`. Removing a connection preserves its source settings. A configured `FieldTransforms::make('transforms')` produces `transforms` alongside `kind`, `source`, and `target`; reserve those keys for row metadata. `getSourceSettings()` reads all current source-keyed settings for callbacks such as `outputSchema()`, before native child dehydration. The components do not interpret the settings.

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

An empty editor shows a muted `+ Transform` aligned with the source title, without a frame, shadow, background, or hover tooltip. Hovering the enabled plus button or Transform label, or focusing the editor, emphasizes the text and plus icon together. Empty footer space leaves them muted. In `FieldMapping`, it can start or complete a connection in either direction, including inverse mode. Only `+ Transform` opens the catalog in an empty editor. With transforms, a native Filament input frame contains a persistent `+` button and compact chips; its free space also opens the catalog. One row is reserved so the first chip does not change the input height. Further chips wrap while the add button stays on the first row. The Add transform button stays visible but disabled when no compatible transforms are available.

Transforms can repeat. Those with settings open a native form; others are added immediately. Click a configurable chip label to edit, its separate remove button to delete, and drag the label or press Alt+Left / Alt+Right to reorder. Settings modals contain no deletion action. Ordering blocks positions that make the complete schema chain incompatible, refreshing allowed positions after parameter or input changes. Hovering and rejected moves make no request; accepted orders are checked again on the server before state changes. Existing chips remain editable and removable after an incompatible change, but submission rejects the chain and additions remain unavailable until it is corrected. Native parameter forms retain their validation.

`FieldTransforms` actions partially render their editor by default; use `partiallyRenderAfterStateUpdated(false)` when an application needs a full form refresh. `FieldBinding` adds and removes static pairs by rendering only its static section, preserving other editor drafts. With array-defined source and destination schemas, `FieldMapping` updates affected source cards, its static section when needed, and the server-calculated connection matrix. Live source-setting updates can also refresh type headings without rebuilding other editors. Search, visibility filters, mappings and other drafts remain in place. Source or destination schema callbacks retain full component rendering because they can change the set of fields. Parameter forms are built when opened, rather than while rendering every source menu.

Built-in scalar value transforms reject arrays and objects; Filter accepts every input type because its predicate can inspect nested values. Custom descriptions can declare their input schemas. Cast accepts unions of scalar types, including null. The built-in string transforms require a known string type, optionally nullable, and reject logical date/reference formats. `FieldTransforms` configures Common’s `InlineBuilder` with transformation blocks and owns the JSON Schema compatibility checks and string parameter contract. The shared editor owns ordered `type`/`data` state, native parameter forms, menus, chips and ordering. Neither mapping component interprets this format or requires a transformation registry.

### Presentation and assets

Property `title`, `description`, `x-icon`, and `x-color` provide presentation metadata for mapping cards and binding sources. The property key is the technical name shown beside its title and the identifier used in state and connections. Search includes both the title and property key. `x-color` accepts a registered Filament color name or a literal hex/RGB color for the icon and title. Cards display the technical name, required marker, constraint hints, type chips, description and warnings; omitted icons reserve no space. Formats replace the general type label. Reference schemas display their standard `uuid` format; Ferry interprets `x-ferry-reference: true` for logical column types and transformations, while reusable mapping components do not interpret that annotation. Nullable types add a `null` chip, and enum tooltips list allowed values. `x-badges` accepts a list such as `[['label' => 'Imported', 'color' => 'info']]`; the type chip appears last. For framework schema builders, pass their inline `toArray()` result.

Array chevrons expand item schemas and their scoped constraints, one row per nested level. Field constraints remain in the heading. Details start collapsed and work on connected or disabled cards without changing selection; lines follow card resizing. Source actions appear below the metadata. Colors use Filament's `primary` palette for connections, `warning` for required destinations and `danger` for conflicts, in light and dark themes.

Add source actions through `sourceSettings()` with Filament's native `Actions` component. The callback receives the source identifier, including for disconnected sources and inverse mode:

```php
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;

->sourceSettings(fn (string $source) => [
    Actions::make([
        Action::make('inspect')
            ->label('Inspect ' . $source)
            ->action(fn () => Notification::make()->title($source)->send()),
    ]),
])
```

Capture `$source` in your callback or pass it through `arguments()`. Native action visibility, icons and tooltips remain available. Use the same schema for source settings fields and actions; there is no separate source-action registry.

During selection, compatible candidates highlight with a check; incompatible cards and their editors dim with a cross, and existing lines fade. Compatible destinations can replace an existing connection or static value. Unavailable clicks preserve the selection and mappings without an error. Connection lines reveal a cross and use danger colors on hover or keyboard focus, together with both endpoint dots; activate the line to disconnect. This custom focus feedback replaces the browser's rectangular outline. Empty card space, including the source-settings footer, selects the card; native buttons and editors keep their own actions. Mark custom interactive areas with `data-field-interactive` to exclude their descendants from card selection and connection clicks. Leave surrounding empty space unmarked when it should still select the card. Outside selection, connected cards retain full contrast and existing incompatible connections remain visible for correction.

Both components support these presentation options:

| Option | Default | Behavior |
| --- | --- | --- |
| `labels(source: ..., dest: ...)` | Source / Destination or Binding | Set the column headings. |
| `columnSpans(source: ..., target: ..., gap: ...)` | `5, 5, 2` | Set relative widths for sources, targets, and the connection area between them. |
| `searchable()` | `false` | Show field search inputs. |
| `contained()` | `true` | Show the outer border, background, and toolbar with its filter and counters. |
| `dense()` | `false` | Reduce padding and gaps; use 56 px minimum cards instead of 80 px. |
| `compact()` | `false` | Hide field descriptions and show them in title tooltips on hover or focus. |

Labels accept strings or closures; the switches accept booleans or closures. Column spans are positive integers. For example, `->columnSpans(source: 4, target: 7, gap: 1)` gives the target more space while narrowing the connection area. In `FieldMapping::inverse()`, each width follows its source or target side. `FieldBinding` uses the same proportions for headings and rows, and still stacks them on narrow screens. Filament's `columnSpan()` continues to control the whole field's span in its parent form.

`dense()` and `compact()` work independently. Dense source cards use a 4 px gap before source settings. Compact descriptions reserve no layout space. `contained(false)` preserves column headings, optional search, and editors. All combinations preserve form values. The FieldMapping toolbar has an All / Mapped / Unmapped switch, defaulting to All. It filters both sides, combines with search, and preserves all mappings and static values. Static destination values count as mapped. Changing the filter cancels a pending connection and refreshes visible lines; it makes no server request.

Use `warnings(source: [...], dest: [...])` on `FieldMapping` or `warnings([...])` on `FieldBinding` to highlight fields. Both accept closures and retain warnings for unavailable identifiers. Escape cancels mapping selection; keyboard controls and dark mode are supported.

Both fields keep their UI translations in `resources/lang/{locale}/schema-forms.php`, under `phpinnacle-ferry::schema-forms.*`. For published translation overrides, move the `mapping` and `binding` entries from `resources.php` into `schema-forms.php`. The shared static-value caption is `schema-forms.value`. Numeric source descriptions now use `resources.sync.constraints.{precision,scale,range}`; synchronization field captions remain under `resources.sync.fields.*`.

The fields use package views without registering `FerryPlugin`. Filament includes the shared mapping/binding stylesheet and Common’s `InlineBuilder` stylesheet used by `FieldTransforms` in the page head so both render styled from the start; JavaScript loads on demand. The mapping/binding stylesheet contains no transformation editor styles. Publish assets after changes with `php artisan filament:assets`. JavaScript and CSS are distributed as source files and need no package build. Add the views to your application's Filament theme and rebuild it:

```css
@source '../../../../vendor/phpinnacle/ferry/resources/views/**/*.blade.php';
```

The path above assumes the theme lives at `resources/css/filament/admin/theme.css`.

## Field binding

`FieldBinding` displays separate source and destination cards and supports the presentation options above. Destination forms are always visible. Unbound forms are disabled beneath a translucent overlay with a centered **Bind** icon button and excluded from validation and submission. Click **Bind** to enable an editor. Bound cards have a connecting line with endpoint dots styled like `FieldMapping`. Hover or focus the line to reveal a cross and highlight it and both dots in danger; click it or press Enter or Space to unbind. Bind and unbind update locally without a request; the next request validates and submits the selected bindings. Unbind restores the form defaults prepared on page load.

The FieldBinding toolbar has an All / Bound / Unbound switch, defaulting to All. It filters source/destination pairs, combines with source search, and preserves bindings, source settings, and editor drafts. Static pairs appear in All and Bound. Bind and unbind immediately update the visible pairs without a request.

Unbound sources are submitted with `target: null` and retain their validated source settings. Their disabled destination editor drafts are excluded. Disabled fields disable binding controls and child inputs. Inline editors keep their own Livewire update settings. On narrow screens the line runs vertically between stacked cards. Source cards do not show a direction arrow. Connection controls remain visible on devices without hover. Hover and focus feedback starts after 100 ms and fades over 180 ms; leaving has no delay. Reduced-motion preferences disable the fade while retaining the short activation delay.

Binding state is the same flat row list. Each source row contains `kind: source`, its `source` identifier, the editor data in `target` or null when unbound, and any configured source settings. Use `simple()` with a single Filament field to store its native value in `target`. Bound values must be non-null, including after native dehydration; `false`, `0`, and empty arrays are valid. Child fields retain their own validation and conversion rules. Add `required()` only when those stricter rules are wanted: it also rejects empty arrays and strings. An empty string is allowed if the child preserves it; fields that convert it to `null` fail submission while bound.

Without an explicit field `default()`, each editor defaults to its literal source property key. For other field types, provide a suitable native default, for example `simple(Toggle::make('enabled')->default(false))`. Explicit defaults (including `null`), saved bindings, and edited values retain their native Filament behavior. Defaults do not bind sources automatically; an explicit `null` remains an editor draft until replaced with a non-null bound value. Custom `schema()` forms keep their own defaults.

For example:

```php
use Filament\Forms\Components\TextInput;
use PHPinnacle\Ferry\Forms\FieldBinding;

FieldBinding::make('bindings')
    ->jsonSchema(['type' => 'object', 'properties' => [
        'name' => ['type' => 'string', 'title' => 'Customer name'],
        'email' => ['type' => 'string', 'title' => 'Email address'],
    ]])
    ->simple(TextInput::make('destination')->required());

// [['kind' => 'source', 'source' => 'name', 'target' => 'customer.name'],
//  ['kind' => 'source', 'source' => 'email', 'target' => 'contact.email']]
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

// [['kind' => 'source', 'source' => 'name',
//   'target' => ['destination' => 'customer.name', 'transform' => 'trim']],
//  ['kind' => 'source', 'source' => 'email', 'target' => null]]
```

Choose `simple()` or `schema()` for a field. Both accept closures and use native Filament child schemas, including defaults, validation, reactive callbacks, relative state reads, and nested `Repeater` or `Builder` components. `destination` is an example field name, not a reserved key. Destination syntax, duplicate destinations, and transformation behavior belong to the consuming application; the component stores the configured data without interpreting paths or executing transformations.

Use `staticValues()` with `simple()` or `schema()` to enable static pairs. The default is disabled; pass `staticValues(false)` to disable them explicitly. The component supplies a full-width native input with a Value placeholder and an accessible hidden label; it accepts scalar values. **Add static value** creates an immediately connected pair. With `simple()`, it uses the field's explicit default, or the first unused `static_N` destination when no default was configured. The source card shows a readable title (`Static 1`) alongside the technical property (`static_1`), without a separate section heading. Non-string simple targets are displayed as JSON. The right card reuses the configured field or schema without changing child names or rules. Hover or focus the connecting line to reveal a danger trash button, which removes the pair including its value and settings.

```php
FieldBinding::make('bindings')
    ->jsonSchema(['properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']]])
    ->simple(TextInput::make('destination')->required())
    ->staticValues();

// [['kind' => 'source', 'source' => 'name', 'target' => 'customer_name'],
//  ['kind' => 'source', 'source' => 'email', 'target' => null],
//  ['kind' => 'static', 'target' => 'status', 'static' => 'active']]
```

With `schema()`, each static pair receives the whole binding form and its own defaults, validation, nested components, and dehydration callbacks. Its source card is titled **Static value**, independently of the form's contents. The whole form is stored in `target`; no field is treated as the destination identifier, and no generated name is inserted into the form. Empty forms are stored as `[]`.

```php
use Filament\Forms\Components\Toggle;

FieldBinding::make('bindings')
    ->jsonSchema(['properties' => ['name' => ['type' => 'string']]])
    ->schema([
        TextInput::make('channel')->required(),
        Toggle::make('enabled')->default(false),
    ])
    ->staticValues();

// [['kind' => 'source', 'source' => 'name', 'target' => null],
//  ['kind' => 'static', 'target' => ['channel' => 'audit', 'enabled' => false], 'static' => 'active']]
```

`sourceSettings()` also supplies settings after Value in static cards. Its callback may accept `?Closure $inputSchema = null`: static cards receive a closure returning their current input `JsonSchema`, while ordinary sources leave this argument null. For a static card, `$source` is its stable internal row key. Use the supplied closure in a transform editor's `inputSchema()` callback; existing native scalar values retain their types. Native dehydrated settings are included in the static row, alongside `static` and `target`.

Without `staticValues()` the component rejects static rows on submission, and the bound-sources counter never includes them. This replaces the former `constants([...])` schema registration.

Source bindings and static values share one serialized list. With `simple()`, static targets must be unique, compared with their native types. A static target may share a source identifier because their rows have explicit kinds; no value is overwritten. With `schema()`, repeated form values are allowed; the component does not infer uniqueness rules for any child field. Uniqueness of actual destinations across source and static rows remains the consumer's responsibility.

Use `sourceSettings(fn (string $source) => [Actions::make([...])])` for native source actions, as with `FieldMapping`. They remain available on unbound sources.

`jsonSchema(array|Closure)` supplies the source object schema and the same property metadata as `FieldMapping`. Its `required` annotations describe source properties; they do not require the user to bind them. The native `schema()` method continues to describe the per-binding Filament editor. Source identifiers retain their literal identity, including numeric identifiers and dots. `labels(source: 'Source', dest: 'Binding')` sets the headings and accepts closures. Configuration is trusted developer code; source identifiers and binding shapes are validated on submission, while the child fields define the value validation.

`getBindingValues()` reads the current public row list for callbacks before native child dehydration. Submit the parent schema with `getState()` to run validation and return the list with native child values. Internal Livewire keys are encoded to preserve dots and numeric identifiers; fill forms using the public state shown above. Package views, theme setup and asset publishing are shared with `FieldMapping` as described above.

## Testing

```bash
vendor/bin/pest --no-coverage --configuration packages/ferry/phpunit.xml.dist
node --test packages/ferry/tests/Unit/*Test.js
node --test packages/common/tests/inline-builder.test.js
```

Run these commands from the monorepo root. The PHP suite covers structure publication and stale runs, transactional table changes, destination constraints, connector requests, transformation rules, authorization, and form submission. It uses SQLite and transport doubles; no live 1C database or Kafka cluster is required. The JavaScript suite covers connection selection, cardinality, static values, preservation of source settings, and visible connection geometry using explicit server-provided connection modes. Common's shared editor suite covers constrained drag and keyboard ordering. PHP and JavaScript suites run independently; the JavaScript suite needs no PHP application or asset build.

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
