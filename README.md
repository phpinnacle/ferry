# Ferry for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/ferry.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/ferry)

Ferry manages 1C upstream databases, source structure snapshots, replica mappings, and CDC origins in Filament. The built-in Kafka origin integrates Debezium and JDBC connectors through Kafka Connect. Its forms use reusable `FieldMapping` and `FieldBinding` fields from Eidos.

## Features

- `Upstream` model with an encrypted password that is never returned to the interface.
- `UpstreamFactory` service that tests current (possibly unsaved) form values, always closes the temporary connection, and reports a sanitized result.
- Queued structure preparation through a replaceable `StructureAdapter`, with a Rosetta adapter for 1C and ready JSON Schemas stored as `StructureItem` records.
- Filament `UpstreamResource` with create/edit/list pages, "Test Upstream" and "Fetch structure" actions, a structure status badge and configuration forms supplied by the selected driver.
- JSON Schema-based mapping and binding forms built on `phpinnacle/eidos`, with Ferry-owned transformation settings and shared JSON Schema compatibility.
- `Replica` model storing the source object, the field mapping schema and the generated destination table name.
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

The run reads the source in bounded chunks and writes them into a draft revision. The published snapshot is replaced only once the whole draft is complete, so a failed refresh never damages the previous one. A source without supported objects is a valid empty snapshot.

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

The Rosetta adapter reads each root and child table's primary index through Laravel's schema builder and stores its ordered column names in both `primary_key` and the object-level `x-primary-key` annotation, and marks those properties `required` in JSON Schema. A table without a declared primary key gets `[]`; unique indexes are not substituted. For example, a catalog can declare `['_idrref']`, while a tabular section can have a composite key. Source connectors include every key column in `column.include.list` and join them in their declared order for `message.key.columns`. An empty key adds no override for that table. Both static and dynamic destinations support composite source keys. Every destination requires an explicit mapping for every source key column; static destinations must bind them all to destination primary-key fields.

Built-in field schemas store a full translation key in `x-ferry-trans`. The interface resolves it in the current locale with priority over `title`. Other fields use their stored title. The adapter sets `priority` for source ordering; the interface sorts equal priorities by title. The Rosetta adapter gives catalogs priority over other objects and puts obsolete titles starting with `(` last.

The Rosetta adapter converts properties into independently mappable physical source columns before persistence. Single references use `_fld…rref`; system fields already contain their physical names. Composite properties expand into separate source cards for the discriminator (`_type`), any boolean (`_l`), number (`_n`), date/time (`_t`), string (`_s`), and reference identifier (`_rrref`) columns. A reference type code (`_rtref`) is included when the composite permits multiple reference targets. A composite with one reference target uses `_rrref` without `_rtref`. Nested type definitions are expanded before resolving columns.

Each card has its own type, transforms, and optional destination. Bind any subset of non-key fields: choosing an identifier does not automatically select its discriminator or reference type code. Both destination types require every source primary-key component to be bound; static mappings must target destination primary-key components. Debezium exposes the binary discriminator and type code as fixed-length hexadecimal strings of 2 and 8 characters. Reference identifiers use UUID string schemas annotated with `x-ferry-reference`. Persisted source identifiers are physical column names, such as `_fld17_rrref`. A renamed source column requires updating its bindings. `StructureItem::mappableFields()` returns JSON Schema definitions keyed by physical column name, with localized titles and preserved descriptions.

`Structure::publishedItems()` returns the root objects of the published revision; `publishedItem($source)` finds one of them by its external id and returns `null` when absent. Use `$upstream->structure->items()` to reach every stored revision. `$upstream->is_active` states whether the upstream may be used, while `$upstream->structure->status` reports whether its local structure is ready.

When a run fails for good, its structure switches to `StructureStatus::Failed`, keeps its previous snapshot and reports the original exception through `report()`. Connection tests return a notification without persisting their result. Structure progress uses `processed` and `estimated`; progress updates advance the structure’s own `updated_at`, which detects interrupted runs independently of upstream edits. `Structure::canPrepare($staleAfter)` and `prepare($staleAfter)` accept the staleness threshold in seconds; the panel action reads it from `phpinnacle-ferry.structure.stale_after`.

`Contracts\StructureAdapter` separates source reading from persistence. Adapters declare `getKey(): string` and `getLabel(): string` through Filament's `HasLabel` contract. `Services\StructureAdapterRegistry` registers adapters by `getKey()`, exposes their labels through `options()`, and resolves the saved key through `getOrFail()`. The package registers only `Services\RosettaStructureAdapter` under `rosetta`; there is no automatic fallback. Register additional implementations on the registry from an application service provider:

```php
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

$this->app->make(StructureAdapterRegistry::class)->register(
    $this->app->make(CustomStructureAdapter::class),
);
```

`prepare(Upstream)` returns a `Data\StructurePlan` with an estimated root count and adapter-owned serializable context. `read(Upstream, StructurePlan, $offset, $limit)` returns unsaved `StructureItem` roots with their `children` relation loaded. Jobs resolve the selected adapter for each preparation or chunk. Adapters close their source connections and do not save models, publish revisions or dispatch jobs.

Jobs persist the returned objects through `Structure::importItems()`. The structure owns metadata identities, draft publication and stale-run checks. Rosetta’s storage and type maps are encoded only by `RosettaStructureAdapter`; other adapters can use different context. Rosetta fields and composite expansion stay inside the structure adapter under `Services\Rosetta`. Models, mappings, validation and Kafka configuration consume ready schemas. Other adapters return the same `StructureItem` contract without Rosetta types.

`StructureItem` uses an `upstream_id` foreign key. The one-to-one `Structure` uses the same key as its primary key and exposes its items through `items()`.

## Replicas

A replica selects an active upstream with a ready structure and one object from its published snapshot, then maps source fields by their physical column names to destination columns. For dynamic destinations, column names must be unique, start with a lowercase letter, and contain only lowercase letters, digits and underscores. Every source primary-key column must be explicitly bound to a destination column before a mapping can be saved; constants do not satisfy a key binding. Key fields display required markers on the left of the editor. A source without a primary key cannot configure a destination. Creating a pending replica creates no destination table. The first complete mapping save creates `{prefix}{code}` in the Ferry connection, with non-null key columns and a primary key in the source key order. Other mapped columns are nullable and typed from the source, their transformations or static values.

String columns use the saved output schema: a positive `maxLength` produces `varchar(n)`, while an absent or zero limit produces `varchar` without a length restriction. Untransformed source strings use their saved input schema's length; static strings have no inferred length limit. Transformations such as Mask that discard the source length therefore produce an unbounded column. Changes to string length use `ALTER COLUMN` and preserve stored values, including when the column is renamed. PostgreSQL rejects narrowing a column if existing values exceed the new limit, and the replica save rolls back.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the replica requests cleanup after a data-loss confirmation; the worker removes its sink before dropping the dynamic table. When a structure refresh removes the source object, removes a mapped field or changes its saved schema, the replica is paused automatically; the table and its stored rows stay untouched.

A change of SQL column type drops and recreates that column, so it requires the same data-loss confirmation as removing a mapping.

The model owns dynamic table creation and schema changes regardless of the caller. Saving a configured mapping also builds the source and sink configurations through `Origin::make()`. The service saves both connectors and their owner associations in the same transaction as the replica. Connector observers dispatch jobs after that transaction commits; rollback discards the state and table changes without queueing connector operations. Deletion retains the owner until the worker removes its connector and drops the dynamic table.

The form emits ready mapping rows, which `Data\FieldMapping::fromArray()` converts into typed mappings. `ReplicaSchema::assertValid($mappings, $destination, $sourcePrimaryKey)` checks their output schemas, the optional static destination and its primary-key mappings against the source primary-key column names. Transform parameters are validated by their native Filament forms. Dynamic column-name validation belongs to `ReplicaSchema::validateColumns($columns, $fail)` and is applied by the form.

`Replica::brokenColumns($object, $destination)` and `hasValidSchema($object, $destination)` compare saved mappings with current declarations. Source schema comparison ignores JSON object key order while preserving array element order and scalar types; numbers compare by value. Pass the registered `StaticDestination`, or `null` for a dynamic or unavailable destination. `schemaChanges()` plans additions, renames, removals, and string length alterations from the original and current typed mappings; `droppedColumns()` exposes removals for confirmation. Table updates use the same plan and preserve stored values on renames.

The `status` field is the only replica lifecycle state. Pauses requested by an administrator or caused by a broken mapping both use `Pause`. Saving a paused replica preserves that status; resume it with the activate action once its mapping is valid. A replica remains `Pending` until its connector is created in the origin. The initial empty mapping is a draft and does not create connectors; saving its configured mapping queues deployment. Saving an active or paused replica updates its configuration without changing its requested pause state. Kafka configuration assembly rejects mappings that no longer match their source or destination.

## Static destinations

A replica writes either into a generated `{prefix}{code}` table (dynamic) or into a table the application already owns (static). `DynamicDestination` and `StaticDestination` independently implement the `Destination` interface, which exposes presentation metadata, `getTable()`, `getConnectionName()`, `getPrimaryKey()`, and `getFixedValues()`. Primary keys are always non-empty ordered lists, including single-column keys such as `['id']`; `getPrimaryKey()` returns that list. Origins consume this destination data and own the external replication configuration. Dynamic destinations derive their ordered primary key from the replica's explicit source mappings and use its generated table name; static destinations describe the application's target and its mapping fields. Register static destinations on the plugin:

```php
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Jason\Schema;

FerryPlugin::make()->destinations(new StaticDestination(
    key: 'customers',
    label: 'Customers',
    table: 'customers',
    primaryKey: ['id'],
    schema: Schema::object([
        'id' => Schema::string()->format('uuid')->with('x-ferry-reference', true)->title('ID'),
        'name' => Schema::string()->title('Name'),
        'manager_id' => Schema::string()->format('uuid')->with('x-ferry-reference', true)->title('Manager'),
    ])->required(['id', 'name']),
    connection: 'sales',
    fixedValues: ['type' => 'customer'],
));
```

The readonly `key` identifies the destination in the persisted replica. `schema` accepts a `PHPinnacle\Jason\Schema` or a closure returning one, exposed through `getSchema()`. Use a flat object schema with `string`, `integer`, `number`, or `boolean` fields (optionally nullable): `properties` declares columns, `title` supplies labels, and `required` identifies mandatory mappings.

`table`, `connection`, `primaryKey` and `fixedValues` configure the target independently of its schema. A `null` connection uses the Ferry connection. Fixed values are written into every row through `InsertField` transforms; JSON Schema `default` does not insert values.

`Destination` extends Filament’s `HasLabel`, `HasIcon`, and `HasColor` contracts. Static destinations accept optional `icon` and `color` constructor arguments, and `label` may be a closure. Use label and schema closures for translations and catalogs that must reflect the current request. Dynamic destinations use their table name as the label and have no icon or color by default.

The replica list resolves destinations through `DestinationFactory::resolve()` and displays them as badges using their labels, icons, and colors. Dynamic destinations display their table name. The list has no separate type column.

JSON Schema is the field contract throughout Ferry. References use `type: string`, `format: uuid`, `minLength: 36`, `maxLength: 36`, and `x-ferry-reference: true`. The annotation controls SQL storage and native text-transform availability; compatibility treats references like other UUID strings. Dates use the `date-time` format.

`Replica.type` explicitly selects `DestinationType::Static` or `DestinationType::Dynamic` (the default). `Replica.destination` stores the registered key for static replicas or the generated table name for dynamic ones. `DestinationFactory::get($key)` returns the registered destination or `null`. Use `getOrFail($key)` when a static destination is required. `DestinationFactory::resolve($replica)` returns the registered static destination or a `DynamicDestination` for its generated table. `staticFor($replica)` returns the registered static destination, or `null` for a dynamic or unavailable destination. `Replica` initializes its dynamic destination table name from the configured prefix and code in its creating lifecycle event. The saved name stays unchanged when the prefix or code changes. `DynamicDestination` accepts that initialized table name and does not generate one; resolve dynamic destinations after the replica has been initialized. Static records retain their registered destination key.

Saving a static replica accepts only declared destination fields compatible with the output JSON Schema. The mapping editor, saving and structure review use directional compatibility for types, nullable values, enum subsets, formats and string lengths. An `integer` can feed a `number`; a formatted string can feed plain text. UUID strings and references with the same constraints are compatible. Native static forms validate value formats; saved constants supply their native type, exact string length and enum value for compatibility checks. These schema checks do not validate streamed records. Every required field must be mapped, no destination may be mapped twice, and every destination `primaryKey` field may only map to a column from the source `primary_key`; constants and non-key source columns cannot supply destination keys. Every source key component must be mapped to a destination `primaryKey` component; ordinary destination columns cannot receive source key components. Partial source keys and sources without a declared primary key cannot be saved or assembled into connectors. The editor enforces this through Eidos’s public `canConnectUsing()` API and omits static editors for destination key fields. Saving and structure review enforce the same primary-key restriction. Static sink connectors use `pk.mode: record_key`, retain the complete source key, rename its fields to their destination names, and join the configured `primaryKey` columns in their declared order for `pk.fields`; for example, `['tenant_id', 'id']` becomes `tenant_id,id`. Declare every key column in the destination schema and mark it required. There is no automatic `_idrref` mapping for static destinations. Dynamic destinations use their explicitly mapped source key columns with `record_key` mode and list all of them in `pk.fields`. Ferry never creates, alters or drops a static table, and its sink connector upserts rows without propagating source deletions. Removing a registered destination causes the next structure review to pause its replicas and report their mappings as out of date; saving and activation remain blocked until it is registered again.

Replicas are created from the list page in a modal with the replica icon and a description of the next step. Name, Code, Upstream, Object, and Destination appear directly in the modal without a section heading or frame. Creation saves an empty mapping in Pending status and redirects immediately to the edit page. Code, upstream, object, and destination are immutable in that form; only the name and mapping can be edited. Required destination mappings are validated when saving the edit form, so static replicas can be created before configuring their fields. The resource has no separate create page.

The replica form has one destination select: Dynamic followed by the registered static destinations. Selecting a different destination clears the field mapping; the saved replica keeps its explicit type and destination key. A dynamic replica lists every mappable 1C field with its title and physical column name and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections. Static destination mappings enable `withBuiltinStaticForms()` by default: unconnected destination cards show native fields derived from their JSON Schema, including string limits, enums, numeric inputs and Boolean toggles. Values retain their native types without a Cast. Unset optional fields are omitted when converting form state to replica mappings; Boolean `false` and numeric zero are preserved. Existing replicas with transformed constants retain the static-source editor so editing does not discard their transforms. Dynamic destinations keep the Add static value workflow for source cards and transforms. Replica constants remain subject to the replica schema's scalar value and destination type requirements.

The upstream, object, and destination selectors use native browser selects with unselectable placeholders. New replicas default to Dynamic, the first active upstream with a ready structure in name order, and its first object in the displayed order. Changing the upstream selects its first available object and clears the mapping. Empty option lists leave the corresponding selection unset. Both replica mapping editors use `dense()` for reduced spacing and `compact()` to show descriptions in title tooltips. Dense mode reserves one row for transforms so adding the first transform does not increase the card height; wrapped transforms can expand the card.

The Rosetta adapter prepares source constraints once when importing the structure. Numeric source schemas additionally retain exact storage dimensions in `x-ferry-precision` and `x-ferry-scale`; dynamic decimal columns use these values, with the existing defaults of 15 and 2 when absent. Strings with a positive length expose `maxLength`; fixed strings also expose the same `minLength`. Equal lower and upper limits are displayed as one value, such as `9`, instead of `9–9`. Zero or unknown length is unbounded. Non-negative numbers expose `minimum: 0`. Known positive scale becomes `multipleOf: 10^-scale`. When precision and scale are both known, the numeric range uses `exclusiveMaximum: 10^(precision-scale)` and, for signed numbers, its negative as `exclusiveMinimum`. Integer types and the decimal step exclude the endpoints and express the permitted values without rounding a decimal maximum full of nines. The editor shows these as schema constraints rather than generated descriptions. Unknown dimensions do not produce inferred bounds. Rosetta property comments populate `description` for their scalar field or every physical column of a composite field. Missing comments leave the description absent. Compact cards show comments in title tooltips; type metadata remains in schema constraints and is never appended to the comment.

Date/time sources require an explicit Cast before connecting to a plain string destination in the static replica form. The reusable `FieldMapping` component keeps Eidos's more general [directional format compatibility](https://github.com/phpinnacle/eidos#compatibility-and-output-schemas).

## JSON Schema extensions

Ferry uses the following schema annotations. Structure adapters write them
into `StructureItem.schema`, whose property keys are physical column names.
The reference marker can also be used in destination and transformation schemas.
Jason preserves these annotations, and Eidos leaves their meaning to Ferry.

| Annotation | Value | Scope and behavior |
| --- | --- | --- |
| `x-primary-key` | Ordered list of strings | Object-level physical primary-key columns. Rosetta reads the declared primary index, including composite keys, for roots and children, and also saves the columns in `StructureItem.primary_key`. An empty list means the source table has no declared primary key. Key properties are also listed in JSON Schema `required`; the editor adds these markers when projecting existing or custom adapter objects and preserves other required annotations. Connector key selection reads the model attribute. |
| `x-ferry-trans` | String | Full Laravel translation key for a source property label. Resolved in the current interface locale and takes priority over `title`. Used for built-in fields. |
| `x-ferry-reference` | Boolean `true` | Marks native reference strings, including nullable strings. Rosetta references and identifiers use UUID strings with `format: "uuid"` and length 36. Dynamic SQL tables store annotated references as text, and native text transforms reject them. Mapping compatibility uses the UUID schema independently of this marker. The annotation does not convert values. |
| `x-ferry-precision` | Integer | Optional total number of digits in a source number. Retains the exact storage dimension alongside standard JSON Schema numeric constraints. Dynamic decimal columns use it as their precision, defaulting to 15 when absent. |
| `x-ferry-scale` | Integer | Optional number of fractional digits in a source number, including zero. Dynamic decimal columns use it as their scale, defaulting to 2 when absent. An unknown source scale is omitted. |

`StructureItem::field($source)` removes `x-ferry-trans`, `title` and
`description` before producing the logical
schema saved in `FieldMapping.field`. Reference and numeric annotations remain.
Dynamic decimal creation reads precision and scale from the saved output schema (`result` when present, otherwise the untransformed source schema), defaulting to 15 and 2 when absent.

Example of a stored `StructureItem.schema`:

```json
{
  "type": "object",
  "x-primary-key": ["_idrref"],
  "properties": {
    "_idrref": {
      "type": "string",
      "format": "uuid",
      "minLength": 36,
      "maxLength": 36,
      "x-ferry-reference": true
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
      "x-ferry-reference": true
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

A transform describes an operation on data on its way to the destination. The selected origin supplies its execution; the Kafka origin compiles it into a Kafka Connect transform of the sink connector. Editor descriptions and execution handlers share a stable key but are registered separately. `Transformation` is an immutable description with Filament-style `label()`, `icon()`, `color()`, `description()`, and `schema()` methods. `key()` identifies it. It implements Filament's `HasLabel`, `HasIcon`, `HasColor`, and `HasDescription` contracts; icons and descriptions are optional.

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

Labels, icons, descriptions, and colors accept values or closures. `color()` defaults to `primary` and accepts a Filament color name, a shade palette such as `Color::Emerald`, or `null`, styling source chips. The Add transform picker uses plain labels. `schema()` accepts an array of Filament components or a closure receiving the selected `?StructureItem $object` and the preceding `?JsonSchema $input`; `getSchema($object, $input)` returns fresh components for each form. Fluent methods return a new description, leaving the original unchanged.

`inputSchema()` describes supported values using the same compatibility rules as `FieldMapping`: types, enums, directional formats, string lengths and array items. Alternatively, pass a predicate `fn (JsonSchema $input) => bool` to define custom compatibility checks. Without an input schema, a description accepts any input. `accepts($input)` checks a `PHPinnacle\Eidos\Forms\JsonSchema` value object directly.

`outputSchema()` declares a fixed JSON Schema array or a callback receiving the full input schema and settings. For example, a string transform that preserves null values can declare:

```php
use PHPinnacle\Eidos\Forms\JsonSchema;

->outputSchema(fn (JsonSchema $input, array $params) => [
    'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
])
```

`getOutputSchema($input, $params)` returns a `JsonSchema` object or `null` when its callback cannot determine an output, for example when settings are incomplete. Without an output declaration it preserves the complete input schema, which is appropriate only when the transform guarantees that all its constraints remain true. An empty schema array means unconstrained values, not an invalid chain.

Each step consumes the preceding output schema. Cast replaces the value constraints with the selected scalar type and preserves nullability; repeated casts use that result. Trim, Substring and Mask accept strings, including formatted and nullable strings, but reject the `date-time` format and schemas with `x-ferry-reference: true`, which represent logical dates and references. Ordinary UUID strings remain supported. Their outputs retain nullability and discard the original format, enum, pattern and length bounds. Custom descriptions can preserve or establish guarantees their execution actually provides.

Forms, menus and previews use descriptions without resolving execution handlers or requiring their configuration. Parameter validation in `FieldTransforms` runs the description's native Filament schema, including relative field rules. Replica mappings persist the final output JSON Schema as `result`; `FieldMapping::sourceSchema()` exposes the saved input (or a schema inferred from a native static value), and `outputSchema()` returns the final schema without discarding constraints. `canMapTo($target)` checks destination compatibility; `stringLength()` reads the declared output length, including composed schemas. The final output of a source with a target must have a supported scalar type. `ReplicaTableManager` translates it into SQL columns; decimal precision and scale come from the output schema.

### Execution handlers

Configure execution separately in `config/phpinnacle-ferry.php`. The default configuration includes handlers for all five supplied descriptions, including the optional editor choices:

```php
use PHPinnacle\Ferry\Origins\Kafka\Transforms\CastTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\FilterTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\MaskFieldTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\SubstringTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\TrimTransform;

// phpinnacle-ferry.kafka.transforms
[
    'cast' => CastTransform::class,
    'filter' => FilterTransform::class,
    'trim' => TrimTransform::class,
    'substring' => SubstringTransform::class,
    'mask' => MaskFieldTransform::class,
];
```

Custom handlers implement `Origins\Kafka\Contracts\TransformHandler`: `connector(string $name, string $field, array $params)` builds Kafka Connect settings for the owning source field. Handlers resolve through Laravel's container only when needed. Input/output JSON Schemas belong to the editor description; parameter validation belongs to its native Filament form.

`TransformRegistry` stores the UI descriptions registered through the plugin. Its `get($key)`, `getOrFail($key)` and `all()` methods read that catalog. `resultSchema(JsonSchema $input, array $settings)` passes the full schema through the ordered chain and returns null for an incompatible step or an unavailable output. Forms use this registry without resolving handlers.

`Origins\Kafka\TransformFactory::handler($key)` and `handlerOrFail($key)` resolve handlers from configuration independently of the UI registry. `pipeline($replica, $object, keyMap: [], deletes: false, fixedValues: [], unwrap: true)` returns the complete flat Kafka Connect transformation configuration: ordered aliases, `transforms.*` properties, and required tombstone predicates. It owns optional envelope unwrapping, source transforms, key/value renames, constants and their transforms, and destination fixed values, in that order. `SinkConfigFactory` passes `unwrap: false` when the origin extracts new record state at the source; sinks with deletes disabled then start with a tombstone filter. Pass the source-to-destination key map to retain only its source key fields and rename them using [Apache ReplaceField](https://docs.confluent.io/kafka-connectors/transforms/current/replacefield-ak.html). Both static and dynamic sinks pass every mapped source key component. The default empty key map omits key selection and renaming. Value mappings are always explicit. Workers can execute saved transforms without resolving the UI registry or booting a Filament panel. Hiding a transform from the editor does not disable its saved pipelines; remove its handler configuration to make it unavailable at runtime.

All transformations, including Filter, are prohibited on source primary-key fields in static and dynamic destinations. The editor hides the Transform add button on key cards and saving enforces the same restriction. Existing key transforms remain visible and removable, produce mapping warnings, pause during structure review, and block origin configuration assembly until removed. Structure review enforces this independently of editor descriptions and execution handlers. Non-key fields continue to support transformations.

The transformation editor uses Common's `InlineBuilder`. Run `php artisan filament:assets` to publish the shared editor assets.

Transform settings are ordered `['type' => 'mask', 'data' => ['replacement' => '***']]` entries; parameter values are strings, and `data` may be omitted when empty. Each source row in `Replica.schema` stores its ordered transforms and output `result` schema, such as `['type' => 'string', 'maxLength' => 100]`. There is one field transform catalog, without scopes. Native Filament forms validate parameters and type chains; `ReplicaSchema` checks destination compatibility. The sink applies source chains in schema order to the extracted row, before renames. Static values are inserted after renames, followed immediately by each constant’s transforms on its target field. Saving a configured replica refreshes its desired sink configuration; the shared source connector changes only when its generated configuration changes. Structure review pauses replicas whose required source columns are unavailable or incompatible. Kafka compiles the source and sink configurations before applying either one. A missing runtime handler raises the pipeline compiler’s `transform_missing` error during activation or update; there is no separate handler-availability check or automatic pause for configuration changes.

`Replica.transforms` separately stores an ordered `list<TransformSetting>` for record-level settings in a JSON column, defaulting to an empty list. This attribute is storage only: the form does not edit it, and source capture, compatibility checks, and connector pipelines do not interpret or execute it.

### Built-in transforms

The built-in Cast transformation targets JSON Schema types: `string`, `integer`, `number`, and `boolean`. It accepts unions of scalar types, including null, and rejects arrays and objects. The Kafka adapter maps those targets to native types through `Origins\Kafka\Transforms\CastTransform::type()`. The selector disables the current unformatted type using the output of preceding transforms, including nullable scalar inputs. Formatted strings, references, unknown inputs and unions with several non-null types keep every target available. Casting removes the input format and constraints; repeated casts can alternate between types.

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

The source connector captures fields with a target or transforms; unbound filter fields are excluded from destination writes by the sink whitelist. The form resets source settings when the upstream, object or destination changes.

### Source settings and transforms

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

Replica forms use the inherited `addable(false)` and native validation rules to prohibit key transforms while keeping existing items visible and removable. `object()` accepts the selected `?StructureItem`, which is passed to transformation forms. `inputSchema(array|Closure)` supplies the original source property definition. The field passes this complete schema through `TransformRegistry::resultSchema()` to determine compatible additions. Mapping consumers use the same result in their `outputSchema()` callbacks for hints and connection checks. For static source settings, pass `fn () => $inputSchema()->toArray()` to `FieldTransforms::inputSchema()`; Eidos supplies this closure through the source-settings callback.

An empty editor shows a muted `+ Transform` aligned with the source title, without a frame, shadow, background, or hover tooltip. Hovering the enabled plus button or Transform label, or focusing the editor, emphasizes the text and plus icon together. Empty footer space leaves them muted. In `FieldMapping`, it can start or complete a connection in either direction, including inverse mode. Only `+ Transform` opens the catalog in an empty editor. With transforms, a native Filament input frame contains a persistent `+` button and compact chips; its free space also opens the catalog. One row is reserved so the first chip does not change the input height. Further chips wrap while the add button stays on the first row. The Add transform button stays visible but disabled when no compatible transforms are available.

Transforms can repeat. Those with settings open a native form; others are added immediately. Click a configurable chip label to edit, its separate remove button to delete, and drag the label or press Alt+Left / Alt+Right to reorder. Settings modals contain no deletion action. Ordering blocks positions that make the complete schema chain incompatible, refreshing allowed positions after parameter or input changes. Hovering and rejected moves make no request; accepted orders are checked again on the server before state changes. Existing chips remain editable and removable after an incompatible change, but submission rejects the chain and additions remain unavailable until it is corrected. Native parameter forms retain their validation.

`FieldTransforms` actions partially render their editor by default; use `partiallyRenderAfterStateUpdated(false)` when an application needs a full form refresh. Parameter forms are built when opened, rather than while rendering every source menu.

`FieldTransforms` configures Common’s `InlineBuilder` with transformation blocks and owns the JSON Schema compatibility checks and string parameter contract. The shared editor owns ordered `type`/`data` state, native parameter forms, menus, chips and ordering. Neither mapping component interprets this format or requires a transformation registry.

### Editing replica transforms

Replica source cards use the reusable [FieldTransforms editor](#source-settings-and-transforms), including before a source is connected or bound. Source primary-key cards prohibit all transformations, including Filter. Other Boolean sources initially offer Cast and Filter; casting to string enables the optional Trim, Substring, and Mask descriptions when registered.

Submitted mappings and bindings contain every offered source, including its `transforms` and nullable `target`. The replica editor uses physical source column names such as `_fld17` as JSON Schema property keys. The same names are stored directly in `Replica.schema`; renaming a source column requires updating its bindings and transforms. Source/static row kinds and native values stay unchanged. Its native source settings also emit the original `field` JSON Schema snapshot and output `result` JSON Schema for structure review and table creation. Source settings are validated even without a target. Rows with neither a target nor transforms do not participate in capture, execution or structure compatibility checks. Static values have their own mapping controls. Transforms apply in chip order, and static connections are checked against the transformed source schema, so casting a string to boolean enables a boolean destination.

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
$connectors->activate($replica);
$connectors->pause($replica);
$connectors->restart($replica);
$connectors->checkStatus($replica);

$replica->delete(); // Queue sink removal and source refresh.
$upstream->delete(); // Queue all sinks, then the source, for removal.
```

`Jobs\Connector\SynchronizeConnectorsJob` reads current desired state rather than a serialized configuration. It locks the upstream row for the transaction, so concurrent workers serialize changes for that upstream. It applies the source configuration before sinks, preserves paused sinks, and pauses an existing source when no replicas remain. Jobs processed out of order cannot restore an older configuration. A connector status save does not enqueue configuration changes.

All Ferry jobs, including structure preparation and connector operations, use Laravel's configured default queue connection and `phpinnacle-ferry.queue`. A `null` queue name uses that connection's default queue. With the `database` driver, they use the application's standard `jobs` table, created by its Laravel queue migration. Ferry does not create queue tables or register a queue connection. Connector jobs are dispatched through `afterCommit()`: workers see committed settings, and rolled-back changes enqueue no work, including when Ferry uses a separate database connection. If enqueueing fails after commit, desired state remains saved; retry deployment with the restart action after restoring the queue. Connector jobs make up to five attempts, with retry delays of 5, 15, 60 and 120 seconds; exhausted jobs use Laravel's configured failed-job storage and can be retried with its queue commands. Configure the queue connection's `retry_after` above the job's 120-second timeout.

Requests may execute again if the worker stops after a remote success but before committing its result. Configuration application uses an idempotent PUT, and removing an absent connector succeeds. Upstream and replica rows are soft-deleted during cleanup and physically removed only after successful external removal. Their unique codes remain reserved until cleanup completes. Dynamic destination tables are dropped after sink removal; static tables are never dropped.

Kafka helpers live under `Origins\Kafka`. `SinkConfigFactory` receives `TransformFactory` in its constructor; `make($destination, $replica, $object, $topic, $targetHost = null, $unwrap = false)` builds sink settings from destination data. Pass `unwrap: true` when the source preserves Debezium envelopes. `SourceFactory::source($upstream, $config)` builds capture settings from the upstream's saved replicas, including pending and paused replicas; callers do not supply a replica collection. `newlyScopedTables($previousConfig, $config)` identifies tables requiring snapshots when capture expands. `KafkaConnectFactory` creates clients and signal producers from the supplied origin settings.

Both `Upstream` and `Replica` store a nullable `connector_id`. A new upstream without replicas creates no connector. Checking status does not create one either. `Connector` stores its origin key, upstream id, external name, reported status, error and check timestamp. Its `config` is the desired configuration; `applied_config` is the last configuration successfully applied by the worker. Both configurations use encrypted arrays in TEXT columns and are hidden from serialization. Failed operations leave the desired configuration available for retry and record the connector error.

A source connector failure concerns all replicas of its upstream, while a sink connector failure concerns its replica alone. A connector that reports itself as running while one of its tasks has failed is recorded as failed together with the trace of that task, so a stalled transfer stays visible. Both states are shown as badges in the Filament tables and can be refreshed from there.

Source and sink connectors apply the `source` and `sink` settings declared by their named origin. The shipped origin pins JSON converters with schemas enabled in both maps; empty maps leave converter selection to the worker defaults. The source unwraps Debezium envelopes by default; with `extract_state: sink`, each sink unwraps them instead. The sink applies the configured transform pipeline and renames fields. Its `fields.whitelist` includes only its own mapped columns, constants and fixed values, so replicas reading the same source table do not write each other's columns.

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
        'extract_state' => 'source',
        'source' => [
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'true',
        ],
        'sink' => [
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'true',
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
        'extract_state' => 'sink',
        'source' => [
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'true',
        ],
        'sink' => [
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'true',
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

`extract_state` selects where Ferry applies Debezium's `ExtractNewRecordState`: `source` (the default) stores flat row values in Kafka; `sink` stores the full Debezium envelope and unwraps it separately in each sink. Source extraction preserves tombstones for dynamic destinations; static sinks drop them before applying field transforms and constants. Sink extraction preserves tombstones for dynamic destinations and drops delete events and tombstones for static destinations. Field mappings and their transforms always run in the sink. Static destinations continue to upsert without propagating deletions in either mode.

Changing the extraction stage changes the Kafka message format; existing topic history is not rewritten. Coordinate the source and all of its sinks when applying the change, and account for records already stored in the previous format before resuming consumption.

Each origin declares `source` and `sink` as arrays of native Kafka Connect property names and string values. The corresponding map is merged after Ferry builds a connector's configuration, so supplied values override generated values. Empty maps add no properties; for converters, this inherits the worker defaults. Keep the source's serializers and the sink's deserializers compatible, including their schema settings and Schema Registry URLs. Settings that change topic names, capture scope, keys, or transforms must preserve the contracts used by Ferry's generated pipeline and snapshot handling.

Source-only Debezium custom converters can be configured in `source`, independently of the key/value serializers:

```php
'source' => [
    'converters' => 'mvarchar,uuid,byteint',
    'mvarchar.type' => 'org.alpaka.kafka.MCharConverter',
    'uuid.type' => 'org.alpaka.kafka.UuidConverter',
    'uuid.columns' => 'public._reference259._idrref,public._reference259._fld5830rref',
    'byteint.type' => 'org.alpaka.kafka.IntegerConverter',
    'byteint.columns' => 'public._accumrg37272._recordertref',
    'include.unknown.datatypes' => 'true',
],
```

The example inherits worker key/value converters. Custom converter classes must be installed on the Connect worker. Column lists are supplied by the application; Ferry does not generate them from structure metadata.

The shipped `kafka` origin reads the `FERRY_KAFKA_*` environment variables, including `FERRY_KAFKA_SIGNAL_GROUP_ID`. Kafka-specific runtime transformation handlers are configured separately under `phpinnacle-ferry.kafka.transforms`.

`base_uri` is required before any connector operation, and `headers` accompany every REST request. Configure HTTP timeouts on the application's PSR-18 client. Each origin's `signal.topic`, `bootstrap_servers`, and `group_id` configure Debezium's Kafka signal channel; `flush_timeout` bounds publishing time in milliseconds. Publishing signals requires the `ext-rdkafka` PHP extension.

## Field mapping

Ferry uses Eidos's `PHPinnacle\Eidos\Forms\FieldMapping` and `FieldBinding` fields. Their state, configuration, static editors, presentation and asset setup are documented in the [Eidos README](https://github.com/phpinnacle/eidos#field-mapping).

Mapping/binding translation overrides belong under `phpinnacle-eidos::schema-forms.*`. Publish assets with `php artisan filament:assets`, scan Eidos views in the application theme, and build the theme.

The replica form supplies source and destination schemas, transformation settings, and effective output schemas. The editor, saving and structure review use JSON Schema compatibility directly. Eidos treats application annotations such as `x-ferry-reference` as opaque metadata; Ferry interprets them only at the SQL and native-transform boundaries.

## Testing

```bash
vendor/bin/pest --no-coverage packages/ferry/tests
node --test packages/eidos/tests/*.test.js
node --test packages/common/tests/inline-builder.test.js
```

Run these commands from the monorepo root. The PHP suite covers structure publication and stale runs, transactional table changes, destination constraints, connector requests, transformation rules, authorization, and form submission. It uses SQLite and transport doubles; no live 1C database or Kafka cluster is required. Eidos’s JavaScript suite covers connection selection, cardinality, static values, preservation of source settings, and visible connection geometry using explicit server-provided connection modes. Common's shared editor suite covers constrained drag and keyboard ordering. PHP and JavaScript suites run independently; the JavaScript suite needs no PHP application or asset build.

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
