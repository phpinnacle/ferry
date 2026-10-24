# Changelog

All notable changes to `ferry` will be documented in this file.

## Unreleased

- Mapping and binding state uses a single list of source and static rows. Every source retains its settings and nullable target, and Ferry preserves the row format in `Sync.schema`; synchronization forms translate physical column names to stable metadata IDs at their boundary.
- Kafka Connect management through `phpinnacle/franz`: one Debezium PostgreSQL source per connection and one JDBC sink per synchronization, encrypted applied configurations, connector/task status, and policy-backed lifecycle actions.
- Shared source capture retains active and paused synchronizations. Each sink whitelists its own columns; activation requests incremental snapshots when capture expands to new tables or columns. Editing preserves administrator-requested pauses.
- Static destinations registered through `FerryPlugin::destinations()`, with declared logical types, required fields, their own connection and primary key, and fixed values. Ferry never changes their tables or propagates source deletions; unavailable destinations block saving and activation and pause affected synchronizations on structure review.
- Transactional dynamic-table updates share schema change planning with data-loss confirmation. Renames preserve values; removals and type changes require confirmation. Structure preparation ignores superseded runs, imports repeated chunks without duplicates, and preserves published snapshots on failure.
- JSON Schema-based `FieldMapping` with source/static rows, whole-array or item connections, custom `staticForms()` and standard `withBuiltinStaticForms()` editors, inverse layout, and shared client/server compatibility checks. Compatibility includes enum subsets and integer-to-number widening; directional formats and string length checks are configurable. `canConnectUsing()` adds application restrictions.
- `FieldBinding` supports simple strings, custom child schemas and connected static card pairs enabled by `staticValues()`, with built-in Value inputs, generated editable targets, and shared source settings. Static targets may share source identifiers. Its forms stay visible while unbound, with local binding controls and clickable lines for unbinding. Both components expose independent native `sourceSettings()` schemas for fields and actions, search, containment, `dense()` spacing, `compact()` description tooltips, configurable `columnSpans()`, metadata and warnings.
- Mapping and binding cards display technical names, required markers, formats, enums, nullability and scoped constraints. Array chevrons expand nested item details. Source hints follow `outputSchema()`; unavailable candidate clicks preserve selection, while existing incompatible connections remain visible.
- Immutable `Transformation` editor descriptions registered through `FerryPlugin::transforms()` live in `TransformRegistry`; `TransformFactory` resolves runtime handlers configured in `phpinnacle-ferry.transforms` and builds connector pipelines independently. Built-in cast and filter descriptions are joined by optional trim, substring and mask descriptions; saved pipelines run without panel registrations.
- One catalog of ordered, repeatable source transforms in the standalone `FieldTransforms` editor, without record/field scopes. Chips support individual settings, removal, drag ordering and keyboard ordering before connecting or binding. Native forms validate parameters; `inputSchema()` and `outputSchema()` drive menus and previews through complete JSON Schema chains. Built-in value transforms preserve nullability and discard unsupported source constraints; custom transforms can accept arrays or objects. The separate `Sync.transforms` list stores record-level settings without an editor or runtime execution.
- Transform parameters are validated by native Filament schemas; input validation uses `TransformRegistry` to check type chains and destination compatibility without resolving handlers. Filters use JSONPath predicates scoped to their owning source, capture unbound sources and preserve tombstones. Former `type`/`value` filter settings require reconfiguration. Static values remain native scalar mappings and are inserted after field renames, followed by their ordered transforms on the same target.
- References remain distinct logical types through `ColumnType::jsonSchema()`. Connection tests always disconnect and return sanitized errors. PHP and JavaScript suites run independently without live Kafka.

## 1.0.0 - 202X-XX-XX

- initial release
- `Connection` model with encrypted credentials, an activity toggle and connection testing
- queued structure preparation storing the semantic 1C metadata as `ConnectionMetadata` snapshots
- `Sync` model storing the source object, the field mapping schema and the generated destination table name
- Filament `SyncResource` mapping published 1C fields onto a physical destination table named `{prefix}{code}`
- destination tables carry a single technical column `idrref` as the primary key holding the 1C row identifier
- mapping changes add, rename and drop columns, and ask for confirmation whenever stored data would be lost
- synchronizations are paused automatically when a republished structure drops or retypes a mapped field
