# Ferry for Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phpinnacle/ferry.svg?style=flat-square)](https://packagist.org/packages/phpinnacle/ferry)

Ferry adds a data source connections section to the admin panel: administrators can create, edit, test, enable/disable and delete connections to 1C databases, and Ferry keeps a local snapshot of each source structure that later drives synchronization setup. Synchronization orchestration remains outside this package. A reusable field mapping form component is included.

## Features

- `Connection` model with an encrypted password that is never returned to the interface.
- `ConnectionTester` service that opens a temporary connection with the current (possibly unsaved) form values, runs a lightweight query and reports a sanitized result.
- Queued structure preparation built on `phpinnacle/rosetta`, storing the semantic 1C metadata as `ConnectionMetadata` records.
- Filament `ConnectionResource` with create/edit/list pages, "Test connection" and "Fetch structure" actions, a structure status badge and driver-based default port suggestion.
- Reusable `FieldMapping` form field with click-to-connect lines, search, type checks, multiple destinations, and inverse mapping.
- `FieldBinding` form field that binds configured sources to strings or custom Filament schemas.
- Policy-backed connection management (`ConnectionPolicy`), custom database connection and optional tenancy.
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

Connector management talks to Kafka Connect over PSR-18, so the application binds the transport it prefers:

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

`publishedMetadata()` returns the root objects of the published revision; `publishedObject($source)` finds one of them by its external id and returns `null` when absent. Use the `metadata()` relation to reach every stored revision. `is_active` states whether the connection may be used at all, while `status` only reports whether its local structure is ready.

When a run fails for good, the connection switches to `StructureStatus::Failed`, keeps its previous snapshot, stores a sanitized description in `last_error` and reports the original exception through `report()`. An interrupted run that stops sending heartbeats is retired on the next preparation attempt.

## Synchronizations

A synchronization selects a connection with a ready structure and one object from its published snapshot, then maps source fields (system fields by key, properties by id) to unique snake-case column names. Saving creates a physical destination table named `{prefix}{code}` in the Ferry connection; every table carries a single system column `idrref`, the primary key holding the 1C row identifier, followed by the nullable mapped columns typed from the Rosetta fields.

Editing the mapping applies the difference to the table: new mappings add nullable columns, renames rename the column and removed mappings drop it. Deleting the synchronization drops the table after a data-loss confirmation. When a structure refresh removes the source object, removes a mapped field or changes a field type, the synchronization is paused automatically; the table and its stored rows stay untouched.

The model owns this lifecycle regardless of the caller: creating a `Sync` creates its destination table, changing its `schema` applies the difference and deleting it drops the table, so a synchronization created from a command, job or seeder behaves exactly like one created from the panel.

A pause requested by an administrator and a pause caused by a broken mapping are distinguished by `is_paused`. Saving the edit form resumes only a synchronization that was paused automatically and whose mapping is valid again; a pause requested through the pause action survives editing and requires the activate action. `ConnectorManager::activate()` refuses a synchronization whose mapping no longer matches its source or destination.

## Static destinations

A synchronization writes either into a generated `{prefix}{code}` table (dynamic) or into a table the application already owns (static). Ferry knows nothing about those tables: the application describes each one with a `StaticDestination` implementation and registers it on the plugin.

```php
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\FerryPlugin;

final class CustomerDestination implements StaticDestination
{
    public function key(): string { return 'customers'; }
    public function getLabel(): string { return 'Customers'; }
    public function table(): string { return 'customers'; }
    public function primaryKey(): string { return 'id'; }
    public function connection(): ?string { return 'sales'; }

    public function fields(): array
    {
        return [
            new DestinationField('name', 'Name', ColumnType::String, required: true),
            new DestinationField('manager_id', 'Manager', ColumnType::Reference, required: false),
        ];
    }

    public function fixedValues(): array { return ['type' => 'customer']; }
}

FerryPlugin::make()->destinations(new CustomerDestination);
```

`key()` identifies the destination in the persisted synchronization, `table()`, `primaryKey()` and `connection()` locate the target (a `null` connection means the Ferry connection), `fields()` lists the only columns an administrator may map together with their logical type and whether they are required, and `fixedValues()` holds constants written into every row through `InsertField` transforms.

Saving a static synchronization accepts only declared fields, only source fields of the same logical `ColumnType` (`Reference` covers 1C references and identifiers), requires every required field, forbids mapping one field twice and rejects the source key field, which always feeds `primaryKey()`. Ferry never creates, alters or drops a static table, and its sink connector upserts rows without propagating source deletions. Removing a destination from the registry while synchronizations still point at it never makes them write elsewhere: the next structure review pauses them automatically, they cannot be activated or saved until the destination is registered again, and their whole mapping is reported as out of date.

The synchronization form selects the destination in a single select. A dynamic synchronization lists every mappable 1C field with its title and technical column and binds it to a column name with `FieldBinding`; a static one connects 1C fields to the fields declared by the destination with `FieldMapping`, which shows the logical types and required marks and refuses incompatible connections.

## Kafka Connect connectors

Data actually moves through Kafka Connect. A connection owns a single Debezium PostgreSQL source connector named `ferry-source-{code}`, and every synchronization owns a JDBC sink connector named `ferry-sink-{code}`. Adding synchronizations never creates a second source connector: the shared one captures the union of the tables and columns of the connection's active and paused synchronizations through `table.include.list` and `column.include.list`, and its replication slot and publication are namespaced by connection code so connections never collide on a shared Kafka Connect cluster.

Pausing a synchronization keeps its table in that scope on purpose. The source connector keeps advancing the write-ahead log for the other synchronizations of the connection, so a table dropped from the capture scope would silently lose every change made while the sink was paused.

`ConnectorManager` is the entry point and every operation is idempotent, since configuration is pushed with `PUT /connectors/{name}/config`.

```php
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;

$connectors = app(ConnectorManager::class);

$connectors->activate($sync);        // push both configs, resume them and mark the synchronization active
$connectors->pause($sync);           // pause the sink connector while the source keeps capturing its table
$connectors->restart($sync);         // restart the failed tasks of both connectors
$connectors->delete($sync);          // delete the sink connector and rescope the source connector
$connectors->refreshSource($record); // push the source configuration after a credential change
$connectors->sinkStatus($sync);      // update the sink Connector record
$connectors->sourceStatus($record);  // update the source Connector record
```

The `connector` relation on `Connection` and `Sync` each return a `Connector` model, or `null` before any configuration has been applied or status checked. The model stores the Kafka Connect `name`, `status`, `error`, `checked_at` and the last successfully applied `config`. Configuration is a JSON object exposed as `array<string, string>`; because it includes database credentials, Laravel encrypts it in a `text` column using `encrypted:array` and excludes it from model serialization. A status check does not change the stored configuration.

A source connector failure concerns all synchronizations of its connection, while a sink connector failure concerns its synchronization alone. A connector that reports itself as running while one of its tasks has failed is recorded as failed together with the trace of that task, so a stalled transfer stays visible. Both states are shown as badges in the Filament tables and can be refreshed from there. Deleting a synchronization removes only its sink connector; the source connector is owned by the connection and is removed with it.

Because the target Kafka Connect cluster is shared, the connectors pin their own converters instead of relying on worker defaults, and the sink flattens Debezium envelopes with `ExtractNewRecordState` before applying the field renames taken from the synchronization schema. Each sink also restricts itself to its own mapped columns with `fields.whitelist`, so two synchronizations reading the same source table with different field selections never write each other's columns.

Debezium only snapshots a source connector's tables once, when its replication slot is first created; widening `table.include.list`/`column.include.list` for an already running connector does not back-fill the rows that existed before the change. The source connector therefore always runs with `read.only=true` and `signal.enabled.channels=kafka`, and `ConnectorManager::activate()` compares the connector's `column.include.list` before and after pushing the new configuration. When the diff exposes a table that was not captured yet, it publishes an `execute-snapshot` signal for that table through `SnapshotSignaler`, keyed by the connector's `topic.prefix` as Debezium's Kafka signal channel requires. The very first synchronization of a connection never needs a signal, since Debezium's own initial snapshot already covers it.

## Configuration

`phpinnacle-ferry.structure` tunes the preparation pipeline: `chunk_size` root objects per job, the job `timeout`, the retry `backoff` delays, and `stale_after` seconds before a silent run is considered interrupted. `phpinnacle-ferry.sync.table_prefix` sets the name prefix of synchronization destination tables. `phpinnacle-ferry.kafka_connect` points at the Kafka Connect REST API: `base_uri` is read from `FERRY_KAFKA_CONNECT_URL` and is required before any connector operation, `headers` are sent with every request and `timeout` bounds them. `phpinnacle-ferry.kafka_connect.signal` configures the Kafka signal channel used for ad hoc incremental snapshots: `topic` and `bootstrap_servers` are read from `FERRY_KAFKA_SIGNAL_TOPIC` and `FERRY_KAFKA_BOOTSTRAP_SERVERS`, `group_id` defaults to `ferry-signal`, and `flush_timeout` bounds how long publishing a signal may block. Publishing signals requires the `ext-rdkafka` PHP extension.

## Field mapping

```php
use PHPinnacle\Ferry\Forms\FieldMapping;

FieldMapping::make('mapping')
    ->options(source: ['title', 'email'], dest: ['name', 'email']);
```

Plain string lists use each string as its identifier. Associative arrays use their keys, so labels can change without changing connections. Object lists use enum values or names when available, otherwise their array indexes; use explicit associative keys for stable identifiers when reordering ordinary objects. Objects may implement Filament's `HasLabel`, `HasIcon`, and `HasDescription` independently. Labels and descriptions also support `Htmlable` values. Configuration methods accept closures, and `labels(source: 'Source', dest: 'Destination')` sets the list headings.

State normally maps destination identifiers to source identifiers, for example `['name' => 'title']`. Each source maps to one destination. Use `multiple(['payload'])` to allow a destination to receive several sources; this method also accepts a closure. A multiple destination always stores a non-empty list, even for one source: `['payload' => ['id', 'name']]`. These destinations display a Multiple badge. An occupied source has a disabled card and port until its connection is removed. Click a line to remove only that source, or the destination's disconnect button to remove all its sources. Partial mappings are allowed, and identifiers, types, value shapes, and cardinality are validated on submission.

Use `inverse()` to display destinations on the left and sources on the right, and store state as source to destination:

```php
FieldMapping::make('mapping')
    ->options(source: ['id', 'name', 'active'], dest: ['payload', 'enabled'])
    ->multiple(['payload'])
    ->inverse();
```

The same connections then produce `['id' => 'payload', 'name' => 'payload', 'active' => 'enabled']`. Inverse values remain strings, including for multiple destinations. Disconnect buttons stay on the right, beside each source, and remove only that source's connection. An occupied single destination on the left cannot start another connection; multiple destinations remain available. `multiple()`, `requiredTargets()`, types, badges, and headings always refer to the original configured source and destination identifiers. `inverse()` accepts a boolean or closure, defaults to true when called, and can be disabled with `inverse(false)`. Fill state in the configured format; changing the mode does not convert existing state.

Use `requiredTargets()` to require particular destinations, `types(source: [], dest: [])` to declare types by identifier, and `badges(source: [], dest: [])` to display extra badges:

```php
FieldMapping::make('mapping')
    ->options(source: ['title', 'active'], dest: ['name', 'is_active'])
    ->requiredTargets(['name'])
    ->types(source: ['title' => 'string', 'active' => 'boolean'], dest: ['name' => 'string', 'is_active' => 'boolean'])
    ->badges(source: ['title' => ['Imported']], dest: ['name' => [['label' => 'Unique', 'color' => 'success']]]);
```

Type names are arbitrary non-empty strings and must match exactly. An undeclared type accepts any type. Restrictions apply to clicks, keyboard selection, and server validation. Required destinations are marked, and missing connections block submission, including an empty mapping. Extra badges accept plain strings (gray) or arrays with a `label` and an optional Filament `color`. Paired configuration methods accept named `source` and `dest` arguments, including closures. An omitted side uses its default value: an empty array for options, types, and badges, or Source / Destination for headings. Each call replaces both sides.

Configuration is trusted developer code. Use unique, non-empty identifiers within each list, and refer to configured destination identifiers in `requiredTargets()` and `multiple()`. Submitted mapping state is validated separately.

Connect fields by clicking a source and a destination in either order, using their cards or ports. Escape cancels the current selection. Both lists support search and an unmapped filter, with keyboard selection and dark mode.

The service provider registers the Blade views and Alpine/CSS assets. The mapping field works without registering `FerryPlugin` or publishing the connection migrations. Run `php artisan filament:assets` after installation or after changing its assets. JavaScript and CSS are distributed as source files and require no package build or npm dependencies. Styles load only when the field is rendered.

Add the package views to your Filament custom theme so Tailwind generates the template utilities, then rebuild the application theme:

```css
@source '../../../../vendor/phpinnacle/ferry/resources/views/**/*.blade.php';
```

The path above assumes the theme lives at `resources/css/filament/admin/theme.css`; adjust it for other locations.

## Field binding

`FieldBinding` displays configured sources beside their binding editors, with source metadata, search, an unbound filter, and dark mode. Click **Bind** to activate a source and initialize its editor defaults, or **Unbind** to remove it. Unbound sources are omitted from the submitted state. Disabled fields disable both actions and their child inputs.

Use `simple()` with a single Filament field to store source identifiers mapped to non-empty strings:

```php
use Filament\Forms\Components\TextInput;
use PHPinnacle\Ferry\Forms\FieldBinding;

FieldBinding::make('bindings')
    ->options(['name' => 'Customer name', 'email' => 'Email address'])
    ->simple(TextInput::make('destination')->required());

// ['name' => 'customer.name', 'email' => 'contact.email']
```

Use `schema()` to store each binding as an associative array whose keys and values are defined by its child components:

```php
use Filament\Forms\Components\Select;

FieldBinding::make('bindings')
    ->options(['name', 'email'])
    ->schema([
        TextInput::make('destination')->required(),
        Select::make('transform')->options([
            'trim' => 'Trim',
            'lowercase' => 'Lowercase',
        ]),
    ]);

// ['name' => ['destination' => 'customer.name', 'transform' => 'trim']]
```

Choose `simple()` or `schema()` for a field. Both accept closures and use native Filament child schemas, including defaults, validation, reactive callbacks, relative state reads, and nested `Repeater` or `Builder` components. `destination` is an example field name, not a reserved key. Destination syntax, duplicate destinations, and transformation behavior belong to the consuming application; the component stores the configured data without interpreting paths or executing transformations.

`options()` supports the same string lists, associative identifiers, and object metadata contracts as `FieldMapping`, including enum cases. It also accepts a closure. Source identifiers retain their literal identity, including numeric identifiers and dots. `labels(source: 'Source', dest: 'Binding')` sets the headings and accepts closures. Configuration is trusted developer code; source identifiers and binding shapes are validated on submission, while the child fields define the value validation.

The component uses the package views and the on-demand mapping stylesheet. Include the package views in your custom theme and publish Filament assets as described above. Form state is hydrated into internal child schema state and dehydrated back to the source-keyed map when the parent schema is submitted.

## Testing

```bash
composer test
```

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
