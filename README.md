# Ferry for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/ferry.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/ferry)

Ferry manages 1C upstream databases, source structure snapshots, replica mappings, and CDC origins in Filament. The built-in Kafka origin integrates Debezium and JDBC connectors through Kafka Connect. Its forms use reusable `FieldMapping` and `FieldBinding` fields from Eidos.

## Features

- `Upstream` model with an encrypted password that is never returned to the interface.
- `UpstreamFactory` service that tests current (possibly unsaved) form values, always closes the temporary connection, and reports a sanitized result.
- Queued structure preparation through a replaceable `Adapter`, with a Rosetta adapter for 1C and ready JSON Schemas stored as `StructureItem` records.
- Filament `UpstreamResource` with create/edit/list pages, "Test Upstream" and "Fetch structure" actions, a structure status badge and configuration forms supplied by the selected driver.
- JSON Schema-based mapping and binding forms built on `phpinnacle/eidos`, with Ferry-owned transformation settings and shared JSON Schema compatibility.
- `Replica` model storing the source object, a saved input JSON Schema snapshot, native mapping rows, ordered record transformations and the generated destination table name.
- Static destinations: application-registered tables such as customers or products that replicas fill instead of a generated table.
- Filament `ReplicaResource` that selects an active upstream with a ready structure and an object from the published snapshot, maps source fields to column names and keeps the physical destination table in sync with the record.
- Kafka Connect connector management built on `phpinnacle/franz`: one Debezium source connector per upstream, one JDBC sink connector per replica, with status and applied configuration stored in `Connector` models.
- Policy-backed upstream and replica management (`UpstreamPolicy`, `ReplicaPolicy`), custom database connection and optional tenancy.

## Installation

```bash
composer require phpinnacle/ferry
php artisan vendor:publish --tag="phpinnacle-ferry-migrations"
php artisan migrate
```

The package publishes a single `create_ferry_tables` migration for fresh installations. It creates `connectors`, `upstreams`, `structures`, `structure_items`, and `replicas` on the configured Ferry connection. Rollback drops these service tables and preserves replica destination tables. The required `upstreams.origin` column stores the selected origin configuration key without a database default.

Register `FerryPlugin::make()` in the target Filament panel. Publish `phpinnacle-ferry-config` when using a non-default database connection, tenant model or timeout. All Ferry jobs use the application's default queue connection and the queue selected by `phpinnacle-ferry.queue`. The default `null` uses that connection's default queue; run `php artisan queue:work --timeout=120`. For a dedicated queue, set `queue` to `ferry` and run `php artisan queue:work --queue=ferry --timeout=120`.

Connector management talks to Kafka Connect over PSR-18, so the application binds the transport it prefers. Kafka dependencies are resolved when a connector operation begins; constructing observers or opening a replica form does not initialize them:

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

Upstreams store driver-specific settings in `config`, cast as `encrypted:array` in a `text` column and hidden from model serialization. `Driver::schema()` supplies the Filament fields for the selected database driver; changing the driver during creation resets its configuration and applies its default port. The edit form keeps `driver` and `origin` read-only while allowing connection settings to change. PostgreSQL offers `schema` and an optional `sslmode` selector backed by `Enums\SSLMode`: `disable`, `allow`, `prefer`, `require`, `verify-ca`, and `verify-full`. The selected mode remains a native string in the saved configuration and Laravel credentials; leaving it blank keeps the driver's default behavior. Editing keeps the saved password when its field is left blank. `Upstream::credentials()` combines the configuration with the selected driver for Laravel's connection factory, including driver-specific options.

## Upstream structure

Every upstream owns one `Models\Structure`, available through `$upstream->structure`. It stores the preparation status, revision, run id, progress and adapter context on the Ferry database connection. The nullable `Upstream.adapter` stores the selected structure adapter key. The form offers **1C Rosetta** (`rosetta`) and an empty **Do not read structure** option, with no default selection. Without an adapter, the structure stays `StructureStatus::Pending`, has no run ID, queues no preparation jobs, and hides the preparation action. Connection settings can still be tested independently. The adapter can be selected only during creation and remains read-only on the edit form, including an empty selection, like driver and origin. Changing connection settings while an adapter is selected starts a new preparation run after the database transaction commits. Structure jobs live under `Jobs\Structure` and carry the upstream id, run id and chunk offset; credentials never travel through the queue.

The preparation action in both the table and edit form requires the upstream's `update` policy permission (`update_upstream`). The run reads the source in bounded chunks and writes them into a draft revision. The published snapshot is replaced only once the whole draft is complete, so a failed refresh never damages the previous one. A source without supported objects is a valid empty snapshot.

```php
use PHPinnacle\Ferry\Models\Upstream;

$upstream = Upstream::availableForReplication()->firstOrFail();

foreach ($upstream->structure->publishedItems()->get() as $object) {
    $object->schema;     // PHPinnacle\Eidos\Forms\JsonSchema
    $object->primary_key; // ordered replication key columns
    $object->schema->annotation('x-primary-key'); // ordered physical primary-key columns
    $object->values;     // list<array{id: string, label: string, title: string}>
    $object->children;   // child StructureItem models
}
```

Each `StructureItem` stores a ready object JSON Schema in `schema`. Its `properties` keys are physical source column names, used directly in mappings and connector configuration. Types, bounds, titles and descriptions are prepared by the adapter. `field($source)` returns the logical `JsonSchema` without presentation annotations. Source keys come from the ordered `primary_key` array, rather than a package-wide 1C constant. Optional `code`, `kind`, `reference_id` and `values` preserve source metadata as native scalar values and arrays. See [JSON Schema extensions](#json-schema-extensions) for the complete annotation contract.

The Rosetta adapter reads each root and child table's primary index through Laravel's schema builder and stores its ordered column names in both `primary_key` and the object-level `x-primary-key` annotation, and marks those properties `required` in JSON Schema. A table without a declared primary key gets `[]`; unique indexes are not substituted. For example, a catalog can declare `['_idrref']`, while a tabular section can have a composite key. Source connectors include every key column in `column.include.list` and join them in their declared order for `message.key.columns`. An empty key adds no override for that table. Both static and dynamic destinations support composite source keys. By default, every destination requires an explicit mapping for every source key column; static destinations must bind them all to destination primary-key fields. The replica editor can instead assemble the complete source key into one UUID key.

Built-in field schemas store a full translation key in `x-ferry-trans`. The interface resolves it in the current locale with priority over `title`. Other fields use their stored title. The adapter sets `priority` for source ordering; the interface sorts equal priorities by title. The Rosetta adapter gives catalogs priority over other objects and puts obsolete titles starting with `(` last.

The Rosetta adapter converts properties into independently mappable physical source columns before persistence. Single references use `_fld…rref`; system fields already contain their physical names. Composite properties expand into separate source cards for the discriminator (`_type`), any boolean (`_l`), number (`_n`), date/time (`_t`), string (`_s`), and reference identifier (`_rrref`) columns. A reference type code (`_rtref`) is included when the composite permits multiple reference targets. A composite with one reference target uses `_rrref` without `_rtref`. Nested type definitions are expanded before resolving columns.

Each card has its own type, transforms, and optional destination. Bind any subset of non-key fields: choosing an identifier does not automatically select its discriminator or reference type code. Both destination types require every effective primary-key component to be bound; static mappings must target destination primary-key components. Debezium exposes the one-byte `_type` discriminator as a hexadecimal string of 2 characters. The source converters expose `_rtref`, `_recordertref` and `_keyfield` as integers, and reference identifiers as UUID strings annotated with `x-ferry-reference`. Rosetta prepares these converted types in JSON Schema so mapping checks and destination columns match the captured values. Persisted source identifiers are physical column names, such as `_fld17_rrref`. A renamed source column requires updating its bindings. `StructureItem::mappableFields()` returns JSON Schema definitions keyed by physical column name, with localized titles and preserved descriptions.

`Structure::publishedItems()` returns the root objects of the published revision; `publishedItem($source)` finds one of them by its external id and returns `null` when absent. Use `$upstream->structure->items()` to reach every stored revision. `$upstream->is_active` states whether the upstream may be used, while `$upstream->structure->status` reports whether its local structure is ready.

When a run fails for good, its structure switches to `StructureStatus::Failed`, keeps its previous snapshot and reports the original exception through `report()`. Connection tests return a notification without persisting their result. Structure progress uses `processed` and `estimated`; progress updates advance the structure’s own `updated_at`, which detects interrupted runs independently of upstream edits. `Structure::canPrepare($staleAfter)` and `prepare($staleAfter)` accept the staleness threshold in seconds; the panel action reads it from `phpinnacle-ferry.structure.stale_after`. Saving a new non-null run ID triggers `StructureObserver` to dispatch preparation after the transaction commits.

`Contracts\Adapter` separates source reading from persistence. Adapters declare `getKey(): string` and `getLabel(): string` through Filament's `HasLabel` contract. `Services\StructureAdapterRegistry` registers adapters by `getKey()`, exposes their labels through `options()`, and resolves the saved key through `getOrFail()`. The package registers only `Services\RosettaAdapter` under `rosetta`; there is no automatic fallback. Register additional implementations on the registry from an application service provider:

```php
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

$this->app->make(StructureAdapterRegistry::class)->register(
    $this->app->make(CustomStructureAdapter::class),
);
```

`prepare(Upstream)` returns a `Data\StructurePlan` with an estimated root count and adapter-owned serializable context. `read(Upstream, StructurePlan, $offset, $limit)` returns unsaved `StructureItem` roots with their `children` relation loaded. Jobs resolve the selected adapter for each preparation or chunk. Adapters close their source connections and do not save models, publish revisions or dispatch jobs.

Jobs persist the returned objects through `Structure::importItems()`. The structure owns metadata identities, draft publication and stale-run checks. `StructureObserver` dispatches preparation after commit when a new run ID is saved; saving progress or a plan does not enqueue another preparation job. Rosetta’s storage and type maps are encoded only by `RosettaAdapter`; other adapters can use different context. Rosetta fields and composite expansion stay inside the structure adapter under `Services\Rosetta`. Models, mappings, validation and Kafka configuration consume ready schemas. Other adapters return the same `StructureItem` contract without Rosetta types.

`StructureItem` uses an `upstream_id` foreign key. The one-to-one `Structure` uses the same key as its primary key and exposes its items through `items()`.

## Replicas

The creation form collects general settings only; mapping and transformations are configured on the edit page. Upstream, source and destination use disabled selects when editing; destination type is not an edit field. Destination displays the registered static label or the saved dynamic table name. Changing selections during creation does not initialize mapping or transformation state. The editor reads the source schema and destination from the saved replica and submits only name, mapping and record transformations.

A replica selects an active upstream with a ready structure and one object from its published snapshot, then maps source fields by their physical column names to destination columns. For dynamic destinations, column names must be unique, start with a lowercase letter, and contain only lowercase letters, digits and underscores. Every effective primary-key column must be explicitly bound to a destination column before a mapping can be saved; constants do not satisfy a key binding. Key fields display required markers on the left of the editor. A source without a primary key cannot configure a destination. Creating a pending replica creates no destination table. The first complete mapping save creates `{prefix}{code}` in the Ferry connection, with non-null key columns and a primary key in the effective replica key order. Other mapped columns are nullable and typed from the source, their transformations or static values.

The record transformation editor is a Common `TabbedBuilder` above the mapping. Its catalog contains every registered transform with `TransformScope::Record`. Add, edit, remove or reorder native tabs; their saved order is the record pipeline order. The record and mapping editors stay in place while the source schema follows current record settings. Incomplete settings remain editable and do not produce a generated field until their output can be determined. Saving validates every tab, including inactive tabs, and rejects invalid dependencies or order.

The **Generate UUID key** tab uses the Eidos JSON Schema picker with multiple selection for the complete incoming primary key from `x-primary-key` and an editable output field name, defaulting to `id`. Selections are stored as JSONPath strings. All incoming key parts are required and their order is fixed; the connector receives their field names. Initially these are the physical source columns; after a preceding UUID transform, the incoming key is its generated UUID. The effective schema adds a UUID string of length 36 and replaces its `x-primary-key` with the output field; bind that field to the destination key. The physical input snapshot remains unchanged. The shared source still captures the physical key and never captures the generated field.

The sink runs `org.phpinnacle.toblerone.InsertUuid$Key` before selecting and renaming key fields. JDBC reads the UUID from `record_key` for both upserts and tombstone deletes; no value placeholder is inserted. Install this SMT on the Kafka Connect worker. Its existing default UUID namespace is used.

Generated UUID and slug fields have a `title` derived from their output name (`slug` → `Slug`, `record_id` → `Record Id`). Both mapping editors show this title beside the technical name, using the same heading style as titled source fields.

The **Generate slug** tab uses the Eidos JSON Schema picker with single selection for a string input, followed by a new output field and optional additional separator characters. It appends a `slug` setting to `replica.transforms`, adds a normal mapping row and places the generated string immediately after its source field in the effective schema and both mapping editors. The SMT preserves null values and source optionality; it does not impose uniqueness or a length limit and may produce an empty string. A generated field can have its own subsequent field transforms. Generated output names use lazy state binding: leaving the field updates the mapping sources without saving the replica. Deleting a generated output updates the mapping sources; unrelated mappings and constants are retained. Renamed outputs need to be bound again. Inputs may refer to earlier generated value fields; key fields are separate from record values. Field names cannot replace existing properties.

`Replica::schema` stores the physical input snapshot. `Services\ReplicaSchemaResolver::transformedSchema($replica)` applies `replica.transforms` to that snapshot before field transforms or destination compatibility checks. `TransformRegistry::previewRecordSchema($schema, $settings)` takes typed `TransformSetting` objects and returns the last valid schema before an incomplete or incompatible setting; the form and dependent tabs share this preview. Record transforms declare `sourceFields()` so the shared source captures their physical dependencies even when those fields have no destination mapping. Generated columns are never requested from Debezium.

`ReplicaSchemaResolver` receives `TransformRegistry` through constructor injection and accepts replicas as method arguments. `primaryKeySources($replica, $object)` resolves the effective source key; pass that ordered list to `Replica::primaryKeyMap($sources)` to map it to destination columns. `Replica::columnMap()` and `constantColumns()` read the saved mapping directly. Models own state and business rules that do not require services; schema resolution and SQL table planning belong to services.

String columns use the effective output schema calculated from the saved input schema and transforms: a positive `maxLength` produces `varchar(n)`, while an absent or zero limit produces `varchar` without a length restriction. Untransformed source strings use their saved input schema's length; untransformed static strings create unbounded columns. Transformations such as Mask that discard the source length therefore produce an unbounded column. Changes to string length use `ALTER COLUMN` and preserve stored values, including when the column is renamed. PostgreSQL rejects narrowing a column if existing values exceed the new limit, and the replica save rolls back.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the replica requests cleanup after a data-loss confirmation; the worker removes its sink before dropping the dynamic table. Structure refreshes do not rewrite replica snapshots or pause replicas.

Removing a mapping or changing its SQL column type applies immediately when the form is saved, without an additional confirmation. A type change drops and recreates the column; both operations discard the affected column data.

`ReplicaObserver` delegates dynamic table creation and schema changes to `ReplicaTableManager` whenever a replica is saved, regardless of the caller. Saving a configured mapping also builds the source and sink configurations through `Origin::make()`. The service saves both connectors and their owner associations in the same transaction as the replica. Connector observers dispatch jobs after that transaction commits; rollback discards the state and table changes without queueing connector operations. Deletion retains the owner until the worker removes its connector and drops the dynamic table.

`Replica.schema` stores the complete input JSON Schema copied from the selected source when the model is created, including its metadata and translations. The editor uses this saved snapshot directly; it neither submits the schema nor reloads it from the current upstream structure. Effective output schemas are calculated through the shared transformation definitions for validation, column creation and column changes.

`Replica.mapping` uses the same native list as the Eidos fields:

```php
[
    ['kind' => 'source', 'source' => 'record_id', 'target' => 'id', 'options' => ['transforms' => []]],
    ['kind' => 'static', 'target' => 'active', 'static' => true, 'options' => []],
]
```

A nullable `target` leaves a source unbound. Static rows with `target: null` are editor drafts and do not create columns or connector transforms. `options` preserves arbitrary source settings as a nested array; Ferry reads its ordered `transforms` entries. Rows contain no input or output schema copies. `Data\FieldMapping::fromArray()` converts these rows into typed mappings. `ReplicaSchema::assertValid($mappings, $destination, $sourcePrimaryKey, $sourceSchema)` checks effective output types, required destination fields and primary-key mappings at the form boundary. Native Filament forms validate transform parameters and dynamic column names.

`ReplicaTableManager::mappingChanges($replica)` plans additions, renames, removals, SQL type replacements, and string length alterations from the original and current mappings, input schemas and transforms. Table updates use the same plan and preserve stored values on renames.

The `status` field is the only replica lifecycle state. Administrator-requested pauses use `Pause`. Saving a paused replica preserves that status; resume it with Start once its mapping is valid. A replica remains `Pending` until its connector is created in the origin. The initial empty mapping is a draft and does not create connectors; saving its configured mapping queues deployment. Saving an active or paused replica updates its configuration without changing its requested pause state. Kafka configuration assembly rejects mappings that no longer match their source or destination.

## Static destinations

A replica writes either into a generated `{prefix}{code}` table (dynamic) or into a table the application already owns (static). `DynamicDestination` and `StaticDestination` independently implement the `Destination` interface, which exposes presentation metadata, `getTable()`, `getConnectionName()`, `getPrimaryKey()`, and `getFixedValues()`. Primary keys are always non-empty ordered lists, including single-column keys such as `['id']`; `getPrimaryKey()` returns that list. Origins consume this destination data and own the external replication configuration. Dynamic destinations derive their ordered primary key from the replica's explicit source mappings and use its generated table name; static destinations describe the application's target and its mapping fields. Register static destinations on the plugin:

```php
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Jason\SchemaBuilder;

FerryPlugin::make()->destinations(new StaticDestination(
    key: 'customers',
    label: 'Customers',
    table: 'customers',
    primaryKey: ['id'],
    schema: SchemaBuilder::make()
        ->type('object')
        ->properties([
            'id' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->title('ID')
                ->build(),
            'name' => SchemaBuilder::make()->type('string')->title('Name')->build(),
            'manager_id' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->title('Manager')
                ->build(),
        ])
        ->required(['id', 'name'])
        ->build(),
    connection: 'sales',
    fixedValues: ['type' => 'customer'],
));
```

The readonly `key` identifies the destination in the persisted replica. `schema` accepts a `PHPinnacle\Jason\Schema` or a closure returning one, exposed through `getSchema()`. Use a flat object schema with `string`, `integer`, `number`, or `boolean` fields (optionally nullable): `properties` declares columns, `title` supplies labels, and `required` identifies mandatory mappings.

`table`, `connection`, `primaryKey` and `fixedValues` configure the target independently of its schema. A `null` connection uses the Ferry connection. Fixed values are written into every row through `InsertField` transforms; JSON Schema `default` does not insert values.

`Destination` extends Filament’s `HasLabel`, `HasIcon`, and `HasColor` contracts. Static destinations accept optional `icon` and `color` constructor arguments, and `label` may be a closure. Use label and schema closures for translations and catalogs that must reflect the current request. Dynamic destinations use their table name as the label and have no icon or color by default.

The replica list resolves destinations through `DestinationFactory::resolve()` and displays them as badges using their labels, icons, and colors. Dynamic destinations display their table name. The list has no separate type column. After Upstream, its columns are Destination, Connector, and Status. Both upstream and replica lists label the connector status column **Connector**. Failed connector badges show the saved connector error in a native plain-text tooltip when an error is available. Upstream and Replica edit forms show the same error in a native danger callout above the form; it is hidden when the connector is not Failed or no error is saved. The replica list and edit-page header show the same lifecycle controls: Pause when the replica is Active and its connector is Running, otherwise Start. The edit-page actions use the saved replica without saving form drafts. Start retries a failed connector without rebuilding its configuration; a paused replica is activated first so the retry resumes it. There is no separate Restart action. Both lists show the Refresh statuses header action before their Create action. It checks every saved source and sink connector, independent of filters and pagination. It requires permission to view both the replica and upstream lists. Per-record status checks are absent from both lists. External failures are reported together while checks continue for other connectors; owners without a saved connector are left unchanged. Replicas can be filtered by status, upstream, and destination, separately or together. Destination options use registered labels for static destinations and table names for dynamic destinations.

JSON Schema is the field contract throughout Ferry. References use `type: string`, `format: uuid`, `minLength: 36`, `maxLength: 36`, and `x-ferry-reference: true`. The annotation controls SQL storage and native text-transform availability; compatibility treats references like other UUID strings. Temporal values use the `date`, `time` or `date-time` format and create SQL date, time or datetime columns respectively.

`Replica.type` explicitly selects `DestinationType::Static` or `DestinationType::Dynamic` (the default). `Replica.destination` stores the registered key for static replicas or the generated table name for dynamic ones. `DestinationFactory::get($key)` returns the registered destination or `null`. Use `getOrFail($key)` when a static destination is required. `DestinationFactory::resolve($replica)` returns the registered static destination or a `DynamicDestination` for its generated table. `staticFor($replica)` returns the registered static destination, or `null` for a dynamic or unavailable destination. `Replica` initializes its dynamic destination table name from the configured prefix and code in its creating lifecycle event. The saved name stays unchanged when the prefix or code changes. `DynamicDestination` receives the replica and `ReplicaSchemaResolver`, reading the initialized table name and resolving the effective mapped key; resolve dynamic destinations after the replica has been initialized. Static records retain their registered destination key.

Saving a static replica accepts only declared destination fields compatible with the output JSON Schema. The mapping editor and saving use directional compatibility for types, nullable values, enum subsets, formats and string lengths. An `integer` can feed a `number`; a formatted string can feed plain text. UUID strings and references with the same constraints are compatible. Native static forms validate value formats; saved constants supply their native type, exact string length and enum value for compatibility checks. These schema checks do not validate streamed records. Every required field must be mapped, no destination may be mapped twice, and every destination `primaryKey` field may only map to an effective replica key column; constants and non-key source columns cannot supply destination keys. Every effective key component must be mapped to a destination `primaryKey` component; ordinary destination columns cannot receive effective key components. The edit form rejects partial source keys and sources without a declared primary key. The editor enforces this through Eidos’s public `canConnectUsing()` API and omits static editors for destination key fields. Saving enforces the same primary-key restriction. Static sink connectors use `pk.mode: record_key`, retain the effective replica key, rename its fields to their destination names, and join the configured `primaryKey` columns in their declared order for `pk.fields`; for example, `['tenant_id', 'id']` becomes `tenant_id,id`. Declare every key column in the destination schema and mark it required. There is no automatic `_idrref` mapping for static destinations. Dynamic destinations use their explicitly mapped source key columns with `record_key` mode and list all of them in `pk.fields`. Ferry never creates, alters or drops a static table, and its sink connector upserts rows and propagates source deletions as physical deletes by the mapped primary key. An unavailable registered destination blocks saving and activation until it is registered again.

Replicas are created from the list page in a modal with the replica icon and a description of the next step. Name, Code, Upstream, Source, and Type appear directly in the modal without a section heading or frame. Type selects a dynamic table or a static destination; static replicas select their registered Destination separately. Creation saves an empty mapping in Pending status and redirects immediately to the edit page. Code, upstream, object, and destination are immutable in that form; the destination remains a disabled select. Only the name, record transforms, and mapping can be edited. Required destination mappings are validated when saving the edit form, so static replicas can be created before configuring their fields. The resource has no separate create page. Forms load model attributes directly and return validated attributes from `getState()` for saving. The model casts accept native schema and transform arrays and expose typed values to domain code; no page-level data conversion is required.

The creation form has a Type selector and a separate Destination selector shown for static replicas. A dynamic replica lists every mappable 1C field with its title and physical column name and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections. Static destination mappings use Eidos's built-in static forms for non-key destination fields: unconnected cards show native fields derived from their JSON Schema, including string limits, enums, numeric inputs and Boolean toggles. Values retain their native types without a Cast. Static editors omit null values during native dehydration; Boolean `false` and numeric zero are preserved. Dynamic destinations keep the Add static value workflow for source cards and transforms. Replica constants remain subject to the replica schema's scalar value and destination type requirements.

The Upstream, Source, and Destination selectors use Filament JavaScript selects with unselectable placeholders. Upstream and Destination preload all options without a display limit. Source preloads at most 20 published sources; server-side search matches titles, labels, and physical names and returns at most 20 results. The selected label is loaded separately, so sources outside the initial list remain selectable. Source options are ordered in the database by priority and case-insensitive display title, followed by external ID; numeric parts use database alphabetical order. New replicas default to Dynamic, the first active upstream with a ready structure in name order, and its first object in the displayed order. Changing the upstream selects its first available object and clears the mapping. Empty option lists leave the corresponding selection unset. Both replica mapping editors use `dense()` for reduced spacing and `compact()` to show descriptions in title tooltips. Dense mode reserves one row for transforms so adding the first transform does not increase the card height; wrapped transforms can expand the card.

The Rosetta adapter prepares source constraints once when importing the structure. Numeric source schemas additionally retain exact storage dimensions in `x-ferry-precision` and `x-ferry-scale`; dynamic decimal columns use these values, with the existing defaults of 15 and 2 when absent. Strings with a positive length expose `maxLength`; fixed strings also expose the same `minLength`. Equal lower and upper limits are displayed as one value, such as `9`, instead of `9–9`. Zero or unknown length is unbounded. Non-negative numbers expose `minimum: 0`. Known positive scale becomes `multipleOf: 10^-scale`. When precision and scale are both known, the numeric range uses `exclusiveMaximum: 10^(precision-scale)` and, for signed numbers, its negative as `exclusiveMinimum`. Integer types and the decimal step exclude the endpoints and express the permitted values without rounding a decimal maximum full of nines. The editor shows these as schema constraints rather than generated descriptions. Unknown dimensions do not produce inferred bounds. Rosetta property comments populate `description` for their scalar field or every physical column of a composite field. Missing comments leave the description absent. Compact cards show comments in title tooltips; type metadata remains in schema constraints and is never appended to the comment.

Date/time sources require an explicit Cast before connecting to a plain string destination in the static replica form. The reusable `FieldMapping` component keeps Eidos's more general [directional format compatibility](https://github.com/phpinnacle/eidos#compatibility-and-output-schemas).

## JSON Schema extensions

Ferry uses the following schema annotations. Structure adapters write them
into `StructureItem.schema`, whose property keys are physical column names.
The reference marker can also be used in destination and transformation schemas.
Jason preserves these annotations, and Eidos leaves their meaning to Ferry.

| Annotation | Value | Scope and behavior |
| --- | --- | --- |
| `x-ferry-target` | `"key"` | Generated properties available only in the Kafka record key. Value transforms cannot read these fields. |
| `x-primary-key` | Ordered list of strings | Object-level key columns. In `StructureItem.schema`, Rosetta writes the physical primary index and saves the same order in `StructureItem.primary_key`. In `Replica.schema`, this identifies the input key. Record transforms may replace it in `Services\ReplicaSchemaResolver::transformedSchema($replica)`, including with a generated UUID field. Key properties are listed in `required`. Source capture always uses the physical model key; sink key selection uses the effective record schema. |
| `x-ferry-trans` | String | Full Laravel translation key for a source property label. Resolved in the current interface locale and takes priority over `title`. Used for built-in fields. |
| `x-ferry-reference` | Boolean `true` | Marks native reference strings, including nullable strings. Rosetta references and identifiers use UUID strings with `format: "uuid"` and length 36. Dynamic SQL tables store annotated references as text, and native text transforms reject them. Mapping compatibility uses the UUID schema independently of this marker. The annotation does not convert values. |
| `x-ferry-converter` | String | Source converter key from `phpinnacle-ferry.kafka.converters`. Rosetta infers `uuid` for physical names ending in `rref`, and `integer` for `_keyfield`, `_recordertref` and names ending in `_rtref`, including expanded composite columns and tabular sections. It prepares UUID reference string schemas or integer schemas respectively. Other columns omit it, including `_type` discriminators and ordinary UUID fields such as `_predefinedid`. The Kafka source groups captured columns by this annotation to generate `<key>.columns`; it does not infer converters from column names. |
| `x-ferry-precision` | Integer | Optional total number of digits in a source number. Retains the exact storage dimension alongside standard JSON Schema numeric constraints. Dynamic decimal columns use it as their precision, defaulting to 15 when absent. |
| `x-ferry-scale` | Integer | Optional number of fractional digits in a source number, including zero. Dynamic decimal columns use it as their scale, defaulting to 2 when absent. An unknown source scale is omitted. |

`StructureItem::field($source)` removes `x-ferry-trans`, `title` and
`description` before producing the logical
schema returned to callers. Reference, converter and numeric annotations remain.
Dynamic decimal creation reads precision and scale from the effective output schema, defaulting to 15 and 2 when absent.

Example of a stored `StructureItem.schema`:

```json
{
  "type": "object",
  "x-primary-key": ["_idrref"],
  "required": ["_idrref"],
  "properties": {
    "_idrref": {
      "type": "string",
      "format": "uuid",
      "minLength": 36,
      "maxLength": 36,
      "x-ferry-reference": true,
      "x-ferry-converter": "uuid"
    },
    "_code": {
      "type": "string",
      "title": "Code",
      "x-ferry-trans": "phpinnacle-ferry::resources.replica.system_fields._code"
    },
    "_fld17_rrref": {
      "type": "string",
      "format": "uuid",
      "minLength": 36,
      "maxLength": 36,
      "title": "Owner",
      "x-ferry-reference": true,
      "x-ferry-converter": "uuid"
    },
    "_fld18_n": {
      "type": "number",
      "title": "Amount",
      "minimum": 0,
      "exclusiveMaximum": 100000000,
      "multipleOf": 0.0001,
      "x-ferry-precision": 12,
      "x-ferry-scale": 4
    }
  }
}
```

The item's table name, ordered replication `primary_key` array and `priority` are model attributes.
For reusable presentation annotations such as
`x-icon`, `x-color`, `x-badges` and `x-enum`, see
[Eidos's extension reference](https://github.com/phpinnacle/eidos#json-schema-extensions).

## Transforms

Transform labels, descriptions and form parameters use `phpinnacle-ferry::transforms.*` translation keys from `resources/lang/{locale}/transforms.php`.

Each transformation implements `PHPinnacle\Ferry\Contracts\Transform` and owns its editor block, input compatibility, output JSON Schema and Kafka Connect configuration. Configure its class in `phpinnacle-ferry.kafka.transforms`; `FerryServiceProvider` resolves each class through Laravel's container and registers the resulting objects in the shared `TransformRegistry`. Forms and workers use the same instances, keyed by `getKey()`.

The default list contains Cast, Filter, InsertUuid, Slug, TimestampConverter, BooleanToString, StringMap and Toggle. Add the optional string transforms to this list as needed:

```php
// kafka.transforms in config/phpinnacle-ferry.php
[
    \PHPinnacle\Ferry\Transformations\Cast::class,
    \PHPinnacle\Ferry\Transformations\Filter::class,
    \PHPinnacle\Ferry\Transformations\InsertUuid::class,
    \PHPinnacle\Ferry\Transformations\Slug::class,
    \PHPinnacle\Ferry\Transformations\TimestampConverter::class,
    \PHPinnacle\Ferry\Transformations\BooleanToString::class,
    \PHPinnacle\Ferry\Transformations\StringMap::class,
    \PHPinnacle\Ferry\Transformations\Toggle::class,
    \PHPinnacle\Ferry\Transformations\Trim::class,
    \PHPinnacle\Ferry\Transformations\Substring::class,
    \PHPinnacle\Ferry\Transformations\MaskField::class,
];
```

`Transform::block()` returns `InlineBlock|TabbedBlock`, using aliases for Common's `InlineBuilder\Block` and `TabbedBuilder\Block`. Field transforms return an `InlineBlock`; built-in record transforms return Ferry's `RecordTransformBlock`, a `TabbedBlock` subclass. Each call creates an independent block with its own label, icon and parameter form. A block's name must match the transformation's `getKey()`. Field parameter schema closures receive the preceding `?JsonSchema $input`, the owning `FieldTransforms $component` and optional `?int $index`. The input is null when a preceding transformation has no valid output. Field forms are built when opened, and their components are cloned for each instance. Record tabs use the native Filament builder lifecycle. For dependent record fields, the owning `RecordTransforms::getInputSchema($itemKey)` returns the schema before that item. Blank or partial live state is represented by a null transform output; the preview keeps the preceding valid schema.

`RecordTransformBlock::parameters()` accepts a callback such as `fn (JsonSchema $input) => [...]`. It supplies the schema before the current record transform, including preceding transform settings, without requiring the transform to navigate the component hierarchy. Use `$block->getInputSchema()` to read the current record preview in availability callbacks. Both transformation editors receive schemas without a `StructureItem` model. Native `TabbedBlock` schemas remain supported for record transforms that do not need this context.

### Custom transformations

Implement the same contract for an application transform. This example uses Kafka's native masking transform:

```php
use Filament\Forms\Components\TextInput;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Enums\TransformScope;

final class Redact implements Transform
{
    public function getKey(): string
    {
        return 'redact';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Field;
    }

    public function sourceFields(array $params): array
    {
        return [];
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Value;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $input->singleType() === 'string';
    }

    /** @param array{replacement: string} $params */
    public function output(JsonSchema $input, array $params): JsonSchema
    {
        return JsonSchema::fromArray([
            'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->label('Redact')
            ->schema(fn () => [
                TextInput::make('replacement')->default('***')->rules(['string'])->required(),
            ]);
    }

    /**
     * @param array{field: string, replacement: string} $params
     * @return array<string, string>
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
            'fields' => $params['field'],
            'replacement' => $params['replacement'],
            'predicate' => 'tombstone',
            'negate' => 'true',
        ];
    }
}

// Add Redact::class to kafka.transforms in config/phpinnacle-ferry.php.
```

`accepts()` receives the complete preceding JSON Schema. `output()` returns the resulting `JsonSchema`, or `null` when settings are incomplete and an output cannot yet be determined. Return the input unchanged only when the transform preserves all its constraints. Each transform declares its parameter array shape and validates it through the block's native Filament form. Parameters can contain structured values such as replacement dictionaries; saved settings preserve these arrays. FieldTransforms uses the forms' native dehydration for schema previews and reordering as well as submission. `getScope()` declares `TransformScope::Field` or `TransformScope::Record`; `getTarget()` declares key or value. `sourceFields()` lists physical or previously generated input dependencies for record transforms and returns an empty list for field transforms. `connector()` receives the saved parameters; for field transforms, the factory binds the owning field as the reserved `field` parameter and returns a flat Kafka Connect configuration for one transform. The pipeline factory assigns its alias. Configured transformations are available without booting a Filament panel and must not retain request or form state.

`FieldTransforms` filters its block catalog by field scope and the configured key/value target. `getAvailableBlocks()` then filters by native block availability and the current chain's output schema. Blocks for the configured target remain available for editing when the chain no longer accepts them as additions.

`TransformRegistry::get($key)`, `getOrFail($key)` and `all()` read the catalog. `resultSchema(JsonSchema $input, array $settings, ?TransformTarget $target = TransformTarget::Value, TransformScope $scope = TransformScope::Field)` accepts a list of `Data\TransformSetting` objects, passes the schema through each transformation and returns null for an incompatible scope, target, input or unavailable output. A null target allows both key and value transforms in the replica list. Each step consumes the preceding output. Form and storage boundaries, including `FieldTransforms::getSettings()`, use ordered `type`/`data` arrays; convert those rows with `TransformSetting::fromArray()` before passing them to the registry.

Cast replaces value constraints with the selected scalar type and preserves nullability; repeated casts use that result. Trim, Substring and Mask accept strings, including formatted and nullable strings, but reject `date`, `time`, `date-time` and schemas with `x-ferry-reference: true`. Ordinary UUID strings remain supported. Their outputs retain nullability and discard the source format, enum, pattern and length bounds.

Forms and previews use `accepts()`, `output()` and `block()` without calling `connector()`. `TransformRegistry::mappingSchema($mapping, $inputSchema)` reads the source property from the supplied effective record schema, or derives a schema from the native static value, then applies the mapping's ordered field transforms. `ReplicaSchemaResolver::outputSchema($replica, $mapping)` supplies its effective record schema. `FieldMapping::canMapTo($output, $target)` checks destination compatibility. A mapped output must have a supported scalar type. `ReplicaTableManager` translates it into SQL columns; decimal precision and scale come from the output schema.

### Connector pipelines

`Origins\Kafka\TransformFactory::pipeline($replica, $object, keyMap: [], fixedValues: [])` uses the shared registry's `connector()` implementations and returns the complete flat Kafka Connect configuration: ordered aliases, `transforms.*` properties and required tombstone predicates. It applies `replica.transforms` in list order, then source and constant transforms in mapping-row order, then destination fixed values, and finally key/value renames. Static and fixed fields use temporary names until the final value projection, preventing collisions with physical fields; destination fixed values take precedence. The final projection includes only mapped and fixed fields. Tombstones pass through to the JDBC sink so both static and dynamic destinations propagate deletions. Pass the source-to-destination key map to retain and rename its source key fields using [Apache ReplaceField](https://docs.confluent.io/kafka-connectors/transforms/current/replacefield-ak.html). Both sink modes pass every mapped source key component. The default empty key map omits key selection and renaming. A missing transformation fails assembly through the registry's `transform_missing` error.

All transformations, including Filter, are prohibited on physical source primary-key fields in static and dynamic destinations. The editor hides the Transform add button on those cards and rejects submitted field transforms through native form validation. Non-key fields continue to support value transformations. Generated key fields are produced by record-level key transforms and cannot have field transforms.

Field transformations use Common's `InlineBuilder`; record transformations use its `TabbedBuilder`. Run `php artisan filament:assets` to publish the shared editor assets.

Transform settings are ordered `['type' => 'mask', 'data' => ['replacement' => '***']]` entries; `data` retains the native parameter types and may be omitted when empty. Each mapping row stores its ordered field transforms under `options['transforms']`. Native Filament forms validate parameters and type chains; `ReplicaSchema` checks destination compatibility before saving form state. Connector assembly consumes the saved mapping without repeating form validation. Saving a configured replica refreshes its desired sink configuration; the shared source connector changes only when its generated configuration changes. Kafka compiles the source and sink configurations before saving either one. A missing transformation raises the registry’s `transform_missing` error during activation or update; there is no automatic pause for configuration changes.

`Replica.transforms` exposes an ordered `list<TransformSetting>` for record-level settings stored in a JSON column, defaulting to an empty list. The record editor submits native `type`/`data` rows; `TransformsCast` accepts those rows or typed settings and restores typed settings when reading the model. Model serialization returns the native rows. These settings determine the effective record schema, source capture dependencies, and the first steps of the connector pipeline.

### Built-in transforms

The built-in Cast transformation targets the native [Kafka Connect Cast types](https://kafka.apache.org/40/kafka-connect/user-guide/#org.apache.kafka.connect.transforms.Cast): `string`, `int8`, `int16`, `int32`, `int64`, `float32`, `float64`, and `boolean`. It accepts unions of scalar types, including null, and rejects arrays and objects. Integer targets produce JSON Schema `integer`; floating-point targets produce `number`. The Kafka adapter uses the selected target directly. `Enums\KafkaType` defines these values and their JSON Schema types. Its `HasLabel` implementation supplies translated selector labels with explicit numeric widths; saved parameters and Kafka configurations keep the native string values. The selector disables the current unformatted string or boolean type using the output of preceding transforms, including nullable inputs. Numeric widths remain available because JSON Schema does not track the Kafka width. Formatted strings, references, unknown inputs and unions with several non-null types keep every target available. Casting removes the input format and constraints and preserves nullability. Choose numeric widths for the values being replicated: narrowing integers can overflow and `float32` reduces precision.

**Replace null with default** controls Kafka's `replace.null.with.default`: it defaults to Yes and uses the default declared in the field schema, preserving null when no default exists. Choosing No stores `replace_null_with_default: "false"`; the default Yes remains implicit. The output schema remains nullable when the input is nullable, because a Kafka field default is not guaranteed.

`TimestampConverter` (`timestamp_converter`) uses Apache Kafka's native [TimestampConverter](https://docs.confluent.io/kafka-connectors/transforms/current/timestampconverter.html) on the selected field. It accepts text, integer epoch values and logical date/time inputs, including nullable values, and rejects references, booleans, floating-point numbers, arrays and objects. Settings are `target_type` (`string`, `unix`, `Date`, `Time`, `Timestamp`), optional `format`, `unix_precision` (`seconds`, `milliseconds`, `microseconds`, `nanoseconds`; default `milliseconds`) and optional `replace_null_with_default` (the same Kafka default behavior as Cast). The editor requires a Java `SimpleDateFormat` pattern for plain text input or string output; Kafka Connect validates the pattern and streamed values. Formatted output has a plain string schema because arbitrary Java patterns do not guarantee a JSON Schema date format. Logical Date, Time and Timestamp outputs use `date`, `time` and `date-time`; Unix output is an integer. Every output discards the previous constraints and preserves nullability. Text transforms cannot follow logical date/time outputs without an explicit conversion to string.

Conversion runs before mapping renames and skips tombstones, preserving JDBC deletes. Unix precision describes the units of integer input or output; it is not inferred from Ferry's JSON Schema. Check the actual Connect schema: native logical Date/Time/Timestamp values are handled as logical types, while Debezium integer timestamp representations require the matching Unix precision. Text representations with a temporal JSON Schema format still need an explicit parsing pattern. Kafka's SMT uses UTC for text formatting and loses sub-millisecond precision during conversion. It does not correct source timezone assumptions or 1C-specific date offsets. Ferry emits connector configuration and does not parse timestamps in PHP.

`BooleanToString` (`boolean_to_string`) converts boolean fields to the text configured in `true` and `false`. Its native text inputs preserve empty strings and whitespace. The output is a string enum of the possible replacements with their minimum and maximum lengths; nullable inputs keep null, and input enum restrictions narrow the output. `Toggle` (`toggle`) inverts boolean values with no settings, retains nullability and inverts any effective enum restrictions. Both accept only boolean fields, including nullable booleans.

`StringMap` (`string_map`) uses a native Filament KeyValue field named `mapping`. For example, `['mapping' => ['draft' => 'Draft', 'deleted' => '']]` compiles to `field=<source>`, `mapping.draft=Draft` and `mapping.deleted=`. Matching is exact, case-sensitive and preserves whitespace; replacements are applied once and unmatched values remain unchanged. Empty replacement values are allowed. Native KeyValue omits blank keys, so the editor cannot configure a replacement for an empty source string. At least one mapping is required and every replacement must be a string. StringMap accepts ordinary strings, including formatted strings, but excludes logical date/time fields and references. Its output preserves nullability, remaps finite enums (including unmatched values), and drops obsolete formats and patterns. Finite enums produce exact output length bounds; otherwise, a known source maximum length is widened to include all replacements.

These transforms run on the selected value field before renames, also support replica constants, and preserve tombstones through Toblerone's native behavior. Install [Toblerone v0.0.5 or later](https://github.com/phpinnacle/toblerone/releases/tag/v0.0.5) on every Connect worker. Ferry emits native `org.phpinnacle.toblerone.BooleanToStringTransform$Value`, `StringMapTransform$Value` and `ToggleTransform$Value` configurations; streamed values are transformed on the worker, not in PHP.

Static values use rows with `kind: static`, a required `target`, and a native string, boolean, integer or float in `static`. Their type comes from the native value; `1.0` stays a float through JSON serialization. They compile to `InsertField` plus a cast when the native value requires it. Optional `options['transforms']` run next on the temporary field, before the final projection renames it to `target`; their output JSON Schema is calculated from the native value and the registered definitions. No separate source name is stored in the mapping row.

Register the optional Trim, Substring, and Mask implementations as shown above. The mapping supplies the selected source field; administrators do not enter a second field name.

| Key | Settings form | Kafka Connect class |
| --- | --- | --- |
| `trim` | No settings; trims whitespace from both ends of the selected string. | `org.phpinnacle.toblerone.TrimTransform$Value` |
| `substring` | Required zero-based `start`, optional exclusive `end`. A blank end is omitted; zero is retained. | `org.phpinnacle.toblerone.SubstringTransform$Value` |
| `mask` | Required `replacement`, default `***`, replacing the entire string. | `org.apache.kafka.connect.transforms.MaskField$Value` |

Trim requires the Toblerone plugin and preserves null field values and tombstones. Substring serializes indexes as `fields=source:start[:end]` and requires the Toblerone plugin on the worker. It uses Java UTF-16 indexes without clamping: indexes must fit every input string. Masking uses Apache's `fields` and `replacement` settings and skips tombstones through the built-in predicate. Other MaskField options keep their Kafka defaults. Forms validate the settings; Ferry does not execute the transformations in PHP.

`Filter` keeps (`include`) or drops (`exclude`) entire records using a JSONPath predicate scoped to the owning source field, including sources without a target. Enter the predicate body: `@ == true`, `@ >= 100`, or `@.price > 100`. `@` denotes the selected field; `$` also refers to that field as the local root. Nested `[?()]` filters retain their `@` item scope. Quoted values and regular expressions are preserved. For example, `@ >= 100` on `_amount` becomes `$[?(@['_amount'] >= 100)]`. Numbers remain literal text, preserving decimal precision.

The predicate runs at its position in the ordered chain, so preceding Trim or Cast transforms affect the value it reads. Filter accepts every input type and preserves its schema. The condition placeholder and hint follow its effective input type: string equality, numeric comparison, Boolean equality, nonempty arrays, or an object child comparison. Nullable types use their non-null type; null-only inputs use `@ == null`, and unknown or mixed inputs use `@ != null`. Changing the input type updates these examples without changing the saved predicate.

The form stores `condition`, `mode`, and `missing_or_null_behavior` (`include`, `exclude`, or `fail`; default `include`). These settings configure [Confluent's `Filter$Value`](https://docs.confluent.io/kafka-connectors/transforms/current/filter-confluent.html). Kafka Connect checks JSONPath syntax; Ferry validates the required expression and enum settings. Confluent applies missing/null behavior to paths outside predicates; missing or null references inside `[?()]` are treated as undefined and do not match. Ferry emits a predicate, so those native predicate semantics apply. Filters skip tombstones through the negated `RecordIsTombstone` predicate, preserving Debezium deletions.

The source connector captures fields with a target or transforms; unbound filter fields are excluded from destination writes by the sink whitelist.

### Source settings and transforms

For the Ferry transformation editor, use the standalone `FieldTransforms` field with a source object schema represented by `JsonSchema`:

```php
use PHPinnacle\Ferry\Forms\FieldTransforms;

->sourceSettings(fn (string $source) => [
    FieldTransforms::make('transforms')
        ->label($source)
        ->inputSchema($sourceSchema->properties()[$source]),
])
```

Replica forms use the inherited `addable(false)` and native validation rules to prohibit field transforms on physical primary-key fields and generated key fields while keeping existing items visible and removable. Generated keys are produced by record transforms. `FieldTransforms::inputSchema(JsonSchema|Closure)` takes the source property schema after record transforms. A schema callback must return `JsonSchema` and supports native Filament utility injection. `RecordTransforms::inputSchema(JsonSchema)` takes the fixed record input schema. Configure the input schema before using either editor; arrays belong at serialization boundaries. The field passes this complete schema through `TransformRegistry::resultSchema()` to determine compatible additions. Mapping consumers use the same result in their `outputSchema()` callbacks for hints and connection checks. For `FieldBinding` static source settings, pass `$inputSchema` directly to `FieldTransforms::inputSchema()`; Eidos supplies this closure through the source-settings callback.

An empty editor shows a muted `+ Transform` aligned with the source title, without a frame, shadow, background, or hover tooltip. Hovering the enabled plus button or Transform label, or focusing the editor, emphasizes the text and plus icon together. Empty footer space leaves them muted. In `FieldMapping`, it can start or complete a connection in either direction, including inverse mode. Only `+ Transform` opens the catalog in an empty editor. With transforms, a native Filament input frame contains a persistent `+` button and compact chips; its free space also opens the catalog. One row is reserved so the first chip does not change the input height. Further chips wrap while the add button stays on the first row. The Add transform button stays visible but disabled when no compatible transforms are available.

Transforms can repeat. Those with settings open a native form; others are added immediately. Click a configurable chip label to edit, its separate remove button to delete, and drag the label or press Alt+Left / Alt+Right to reorder. Settings modals contain no deletion action. Ordering blocks positions that make the complete schema chain incompatible, refreshing allowed positions after parameter or input changes. Hovering and rejected moves make no request; accepted orders are checked again on the server before state changes. After an incompatible change, all chips and their settings remain available for editing and removal. Submission identifies the first incompatible transformation, and additions remain unavailable until the chain is corrected. Native parameter forms retain their validation.

`FieldTransforms` actions partially render their editor by default; use `partiallyRenderAfterStateUpdated(false)` when an application needs a full form refresh. Parameter forms are built when opened, rather than while rendering every source menu.

`FieldTransforms` configures Common’s `InlineBuilder` with transformation blocks, checks JSON Schema compatibility and supplies the preceding schema to parameter forms. The shared editor owns ordered `type`/`data` state, native parameter forms, menus, chips and ordering. Neither mapping component interprets this format or requires a transformation registry.

### Editing replica transforms

Replica source cards use the reusable [FieldTransforms editor](#source-settings-and-transforms), including before a source is connected or bound. Physical source primary-key cards prohibit all transformations, including Filter. Other Boolean sources initially offer Cast, Filter, BooleanToString and Toggle. Converting to string enables StringMap and the optional Trim, Substring and Mask transforms when registered.

Submitted mappings and bindings contain every offered source, including its `options` and nullable `target`. The replica editor uses physical source column names such as `_fld17` as JSON Schema property keys. The same names identify source rows in `Replica.mapping`, while `Replica.schema` holds the input definitions. Source/static row kinds and native values stay unchanged. Source settings are validated even without a target. Rows with neither a target nor transforms do not participate in capture or execution. Static values have their own mapping controls. Transforms apply in chip order, and static connections are checked against the transformed source schema, so casting a string to boolean enables a boolean destination.

## CDC origins

With `driver: kafka`, data moves through Kafka Connect. An upstream owns a single Debezium PostgreSQL source connector named `ferry-source-{code}`, and every replica owns a JDBC sink connector named `ferry-sink-{code}`. Adding replicas never creates a second source connector: the shared one captures the union of the tables and columns of the upstream's saved replicas, including pending and paused replicas, through `table.include.list` and `column.include.list`, and its replication slot and publication are namespaced by upstream code so upstreams never collide on a shared Kafka Connect cluster.

Pausing a replica keeps its table in that scope on purpose. The source connector keeps advancing the write-ahead log for the other replicas of the upstream, so a table dropped from the capture scope would silently lose every change made while the sink was paused.

Choose a named entry from [`origins`](#configuration) when creating an upstream. Ferry stores the key in `Upstream::$origin` and keeps it read-only in the edit form. The form requires an explicit selection, and programmatic creation must supply `origin`; neither the model nor the database assigns a default.

`OriginFactory::create($name)` reads the named entry from `origins` and selects its implementation through a `match` on `driver`. The built-in `kafka` value creates `Origins\KafkaOrigin` with its own name and settings. `for($upstreamOrReplica)` resolves the saved origin, and `options()` lists configured names without initializing transports. Each factory call creates an independent origin.

`PHPinnacle\Ferry\Contracts\Origin` builds an unsaved `Connector` through `make(Upstream|Replica)`, reusing the owner's existing connector when present. Connector operations accept only `Connector`: `create`, `update`, `remove`, `start`, `pause`, and `status`. Status returns `ConnectorStatus`; Kafka task diagnostics are placed on the supplied connector without saving it. Origins do not persist models, associate owners or dispatch jobs.

`ConnectorManager` owns association, transactions and lifecycle coordination. Panel actions call this service:

```php
use PHPinnacle\Ferry\Services\ConnectorManager;

$connectors = app(ConnectorManager::class);
$connectors->start($replica);
$connectors->pause($replica);
$connectors->restart($upstream); // Retry the saved source through the queue.
$connectors->checkStatus($connector);

$replica->delete(); // Queue sink removal and source refresh.
$upstream->delete(); // Queue all sinks, then the source, for removal.
```

`Jobs\Connector\SynchronizeConnectorsJob` reads current desired state rather than a serialized configuration. It locks the upstream row for the transaction, so concurrent workers serialize changes for that upstream. It applies the source configuration before sinks, preserves paused sinks, and pauses an existing source when no replicas remain. Jobs processed out of order cannot restore an older configuration. A connector status save does not enqueue configuration changes.

All Ferry jobs, including structure preparation and connector operations, use Laravel's configured default queue connection and `phpinnacle-ferry.queue`. A `null` queue name uses that connection's default queue. With the `database` driver, they use the application's standard `jobs` table, created by its Laravel queue migration. Ferry does not create queue tables or register a queue connection. Connector jobs are dispatched through `afterCommit()`: workers see committed settings, and rolled-back changes enqueue no work, including when Ferry uses a separate database connection. If enqueueing fails after commit, desired state remains saved; retry deployment with Start after restoring the queue. Connector jobs make up to five attempts, with retry delays of 5, 15, 60 and 120 seconds; exhausted jobs use Laravel's configured failed-job storage and can be retried with its queue commands. Configure the queue connection's `retry_after` above the job's 120-second timeout.

Requests may execute again if the worker stops after a remote success but before committing its result. Configuration application uses an idempotent PUT, and removing an absent connector succeeds. Upstream and replica rows are soft-deleted during cleanup and physically removed only after successful external removal. Their unique codes remain reserved until cleanup completes. Dynamic destination tables are dropped after sink removal; static tables are never dropped.

Kafka helpers live under `Origins\Kafka`. `SinkConfigFactory` receives `TransformFactory` and `ReplicaSchemaResolver` in its constructor; `make($destination, $replica, $object, $topic, $targetHost = null)` builds sink settings from destination data. `SourceFactory` receives `TransformRegistry` in its constructor; `source($upstream, $config)` builds capture settings from the upstream's saved replicas, including pending and paused replicas; callers do not supply a replica collection. `newlyScopedTables($previousConfig, $config)` identifies tables requiring snapshots when capture expands. `KafkaConnectFactory` creates clients and signal producers from the supplied origin settings.

Both `Upstream` and `Replica` store a nullable `connector_id`. A new upstream without replicas creates no connector. Checking status does not create one either. `Connector` stores its origin key, upstream id, external name, reported status, error and check timestamp. Its `config` is the desired configuration; `applied_config` is the last configuration successfully applied by the worker. Both configurations use encrypted arrays in TEXT columns and are hidden from serialization. Failed operations leave the desired configuration available for retry and record the connector error.

A source connector failure concerns all replicas of its upstream, while a sink connector failure concerns its replica alone. A connector that reports itself as running while one of its tasks has failed is recorded as failed together with the trace of that task, so a stalled transfer stays visible. Both states are shown as badges in the Filament tables and can be refreshed from there.

Refresh the statuses of all saved source and sink connectors from Artisan:

```bash
php artisan ferry:refresh
```

The command checks connectors synchronously without dispatching jobs or changing their configuration. If checking a connector fails, it reports the connector name and error as a warning, skips that connector and continues checking the remaining connectors. The command returns exit code `0` even when individual checks fail or there are no connectors.

`phpinnacle-ferry.scheduler.refresh` automatically schedules `ConnectorManager::refreshStatuses()` every configured number of seconds (default: `1`). Set it to `0` or a negative integer to disable scheduling. The scheduler calls the service method directly, reports individual failures through Laravel's exception handler and continues with the remaining connectors. Artisan and panel actions use the same service method with their own error presentation.

Run Laravel's scheduler with `php artisan schedule:work` locally or the standard once-per-minute `schedule:run` cron entry in production. The task uses Laravel's sub-minute scheduler with a timestamp filter to support any positive integer interval, including intervals that do not divide a minute. Checks run synchronously; slow external requests can delay subsequent runs.

Source and sink connectors apply the `source` and `sink` settings declared by their named origin. The shipped origin leaves both maps empty, so converter selection uses the worker defaults. JSON converter settings with schemas enabled remain commented examples. The source always unwraps Debezium envelopes and preserves tombstones for deletions. The sink applies the configured transform pipeline and renames fields. Its `fields.whitelist` includes only its own mapped columns, constants and fixed values, so replicas reading the same source table do not write each other's columns.

Widening an existing connector's `table.include.list`/`column.include.list` does not back-fill existing rows. Ferry configures `read.only=true` and `signal.enabled.channels=kafka`; updates compare `applied_config` with the desired captured columns. New columns, including those of a newly captured table or an unbound field filter, trigger an `execute-snapshot` signal for each affected table, keyed by the connector's `topic.prefix`. A new source connector needs no signal because Debezium's initial snapshot covers its capture scope. A retry may repeat a snapshot signal; sinks use upserts, so repeated records update the same destination keys.

## Configuration

`phpinnacle-ferry.connection` selects the database connection for Ferry's models and dynamic destination tables. Static destinations may select their own connection. `phpinnacle-ferry.origins.<name>.target_host` optionally overrides the database host seen by the JDBC worker, leaving Laravel's connection settings unchanged. `KafkaOrigin` rejects non-PostgreSQL upstreams. Destinations must also use PostgreSQL: the JDBC configuration always builds a `jdbc:postgresql` URL and does not validate the destination connection's driver.

`phpinnacle-ferry.structure` tunes the preparation pipeline: `chunk_size` root objects per job, the job `timeout`, the retry `backoff` delays, and `stale_after` seconds before a silent run is considered interrupted. `phpinnacle-ferry.replica.table_prefix` sets the name prefix of replica destination tables.

`phpinnacle-ferry.origins` is a map of named origins. Each entry declares a `driver`; the entry key is displayed in the form. The supported `kafka` driver also declares REST `base_uri`, HTTP `headers`, `source` and `sink` configuration maps, and Kafka `signal` settings. For example, the following fragment defines two named origins:

```php
'origins' => [
    'kafka' => [
        'driver' => 'kafka',
        'base_uri' => env('FERRY_KAFKA_CONNECT_URL'),
        'headers' => [],
        'target_host' => null,
        'source' => [
            // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'key.converter.schemas.enable' => 'true',
            // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'value.converter.schemas.enable' => 'true',
        ],
        'sink' => [
            // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'key.converter.schemas.enable' => 'true',
            // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'value.converter.schemas.enable' => 'true',
        ],
        'signal' => [
            'topic' => env('FERRY_KAFKA_SIGNAL_TOPIC'),
            'bootstrap_servers' => env('FERRY_KAFKA_BOOTSTRAP_SERVERS'),
            'group_id' => 'ferry-signal',
            'flush_timeout' => 5000,
        ],
    ],
    'analytics' => [
        'driver' => 'kafka',
        'base_uri' => env('ANALYTICS_KAFKA_CONNECT_URL'),
        'headers' => [],
        'target_host' => null,
        'source' => [
            // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'key.converter.schemas.enable' => 'true',
            // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'value.converter.schemas.enable' => 'true',
        ],
        'sink' => [
            // 'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'key.converter.schemas.enable' => 'true',
            // 'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            // 'value.converter.schemas.enable' => 'true',
        ],
        'signal' => [
            'topic' => env('ANALYTICS_KAFKA_SIGNAL_TOPIC'),
            'bootstrap_servers' => env('ANALYTICS_KAFKA_BOOTSTRAP_SERVERS'),
            'group_id' => 'ferry-signal',
            'flush_timeout' => 5000,
        ],
    ],
],
```

`target_host` is the destination database host as seen by that origin’s Kafka Connect worker. Set it to `null` to use the destination’s Laravel connection host. It does not change the source database host or destination port.

Ferry applies Debezium's `ExtractNewRecordState` at the source, storing flat row values and deletion tombstones in Kafka. Field mappings and their transforms run in the sink. Every JDBC sink uses `delete.enabled=true` and deletes destination rows by their mapped primary key when a tombstone arrives, for both static and dynamic destinations. Fixed values are inserted into row values and do not add conditions to deletes.

The sink's final value projection excludes effective replica key fields; JDBC writes destination primary keys from the record key alone. A key-only replica produces an empty value object for inserts and updates and preserves null tombstones for deletes. After a UUID key is generated, physical source key fields remain in the value only when mapped to ordinary destination columns. Source capture and shared Kafka topics retain physical key fields in row values for other replicas and earlier transformations.

When replacing an existing connector that stores full Debezium envelopes, coordinate the source and all of its sinks and account for records already stored in the previous format before resuming consumption. Existing topic history is not rewritten.

Each origin declares `source` and `sink` as arrays of native Kafka Connect property names and string values. The corresponding map is merged after Ferry builds a connector's configuration, so supplied values override generated values, including custom converter declarations and column lists. Empty maps add no properties; key/value serialization then inherits the worker defaults. Keep the source's serializers and the sink's deserializers compatible, including their schema settings and Schema Registry URLs. Settings that change topic names, capture scope, keys, or transforms must preserve the contracts used by Ferry's generated pipeline and snapshot handling.

Ferry registers the source-only Debezium custom converters from `phpinnacle-ferry.kafka.converters`, independently of the key/value serializers:

```php
'converters' => [
    'uuid' => 'org.phpinnacle.toblerone.UuidConverter',
    'integer' => 'org.phpinnacle.toblerone.IntegerConverter',
    'mchar' => 'org.phpinnacle.toblerone.MCharConverter',
],
```

Every configured class becomes `<key>.type`, and `converters` lists the keys in configuration order. Captured fields with `x-ferry-converter` supply deduplicated `<key>.columns` lists in `schema.table.column` form. The source combines fields across every tracked replica of the upstream, including paused replicas, source primary keys and unbound fields with transforms. Unused fields do not enter converter lists. Converter declarations also remain present when no fields select them; an empty map emits no declarations. The default classes are provided by Toblerone. Install its JAR in the Debezium connector plugin directory, alongside the Debezium JARs, so the connector class loader can instantiate them. A separate SMT plugin directory does not make custom converters available to Debezium.

For example, the generated source configuration can contain:

```php
'converters' => 'uuid,integer,mchar',
'uuid.type' => 'org.phpinnacle.toblerone.UuidConverter',
'uuid.columns' => 'public._reference259._idrref,public._reference259._fld5830rref',
'integer.type' => 'org.phpinnacle.toblerone.IntegerConverter',
'integer.columns' => 'public._accumrg37272._recordertref',
'mchar.type' => 'org.phpinnacle.toblerone.MCharConverter',
'include.unknown.datatypes' => 'true',
```

`MCharConverter` selects PostgreSQL `mchar` and `mvarchar` types itself and needs no per-column annotation or generated `mchar.columns`. Ferry enables `include.unknown.datatypes=true` at the source for these custom SQL types. Sink configurations do not receive the custom converter declarations. After changing converter class settings, regenerate each affected saved source through `ConnectorManager::refresh($upstream)` and let the queue apply it. Start retries the saved configuration and does not regenerate it. Refresh existing Rosetta structures and update affected mappings to use the converted field schemas. Changing converter settings does not rewrite records already stored in Kafka topics or trigger a new snapshot for existing captured columns.

The shipped `kafka` origin reads the `FERRY_KAFKA_*` environment variables, including `FERRY_KAFKA_SIGNAL_GROUP_ID`. `phpinnacle-ferry.kafka.transforms` lists transformation classes that the service provider resolves and registers in `TransformRegistry` for both forms and Kafka connector compilation.

`base_uri` is required before any connector operation, and `headers` accompany every REST request. Configure HTTP timeouts on the application's PSR-18 client. Each origin's `signal.topic`, `bootstrap_servers`, and `group_id` configure Debezium's Kafka signal channel; `flush_timeout` bounds publishing time in milliseconds. Publishing signals requires the `ext-rdkafka` PHP extension.

## Field mapping

Ferry uses Eidos's `PHPinnacle\Eidos\Forms\FieldMapping` and `FieldBinding` fields. Their state, configuration, static editors, presentation and asset setup are documented in the [Eidos README](https://github.com/phpinnacle/eidos#field-mapping).

Mapping/binding translation overrides belong under `phpinnacle-eidos::schema-forms.*`. Publish assets with `php artisan filament:assets`, scan Eidos views in the application theme, and build the theme.

The replica form supplies source and destination schemas, transformation settings, and effective output schemas. The editor and saving use JSON Schema compatibility directly. Eidos treats application annotations such as `x-ferry-reference` as opaque metadata; Ferry interprets them only at the SQL and native-transform boundaries.

## Testing

```bash
vendor/bin/pest --no-coverage packages/ferry/tests
node --test packages/eidos/tests/*.test.js
node --test packages/common/tests/inline-builder.test.js packages/common/tests/tabbed-builder.test.js
```

Run these commands from the monorepo root. The PHP suite covers structure publication and stale runs, transactional table changes, destination constraints, connector requests, transformation rules, authorization, and form submission. It uses SQLite and transport doubles; no live 1C database or Kafka cluster is required. Eidos’s JavaScript suite covers connection selection, cardinality, static values, preservation of source settings, and visible connection geometry using explicit server-provided connection modes. Common's shared editor suite covers constrained drag and keyboard ordering. PHP and JavaScript suites run independently; the JavaScript suite needs no PHP application or asset build.

Ferry tests cover its form wiring, saved state, connector payloads, and database effects. Shared editor operations and JSON Schema rules are covered in Common and Eidos; Ferry keeps representative integration cases instead of repeating their full matrices. Validate invalid user input through the form that owns it. Persisted replica fixtures must represent valid saved state or an unmapped pending replica.

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
