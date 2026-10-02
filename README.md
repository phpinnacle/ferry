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

## Installation

```bash
composer require phpinnacle/ferry
php artisan vendor:publish --tag="phpinnacle-ferry-migrations"
php artisan migrate
```

Register `FerryPlugin::make()` in the target Filament panel. Publish `phpinnacle-ferry-config` when using a non-default database connection, tenant model or timeout. Structure preparation runs on the queue, so a worker must be running.

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

`publishedMetadata()` returns the root objects of the published revision; use the `metadata()` relation to reach every stored revision. `is_active` states whether the connection may be used at all, while `status` only reports whether its local structure is ready.

When a run fails for good, the connection switches to `StructureStatus::Failed`, keeps its previous snapshot, stores a sanitized description in `last_error` and reports the original exception through `report()`. An interrupted run that stops sending heartbeats is retired on the next preparation attempt.

## Configuration

`phpinnacle-ferry.structure` tunes the preparation pipeline: `chunk_size` root objects per job, the job `timeout`, the retry `backoff` delays, and `stale_after` seconds before a silent run is considered interrupted.

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
npm test
```

## Changelog and license

See [CHANGELOG](CHANGELOG.md). Released under the [MIT License](LICENSE.md).
