# Changelog

All notable changes to `ferry` will be documented in this file.

## Unreleased

- Array-card chevrons expand internal item types and their scoped constraints. Field-level limits stay in the heading; item details start collapsed and remain readable independently of connection selection, including on connected sources.

- Schema-style mapping and binding cards with technical property names, required markers, colored icons and titles, format/enum/null type chips, and automatic constraint hints. Source hints follow the effective output schema.

- Repeated casts retain their final output type for menus, validation, and connections. Mapping source badges show only that final type; numeric sources support every available cast target.

- Shared synchronization form composition, direct transform-setting serialization, and indexed mapping ports. Binding validation rejects static keys that would overwrite another entry; mixed string/array schemas retain array item type and length constraints. Static synchronization connection choices preserve the same logical types required on save.

- Kafka Connect management through `phpinnacle/franz`: one Debezium PostgreSQL source per connection and one JDBC sink per synchronization, with encrypted applied configurations and connector/task status.
- Synchronization activation, pause, restart, and status actions; lifecycle actions respect synchronization update permissions.
- Shared source capture includes active and paused synchronizations. Sinks whitelist their own columns, and activation requests incremental snapshots when capture expands to new tables or columns.
- Static destinations registered through `FerryPlugin::destinations()`, with declared fields, required mappings, their own connection and primary key, and fixed values. Ferry never changes their tables or propagates source deletions.
- Model-owned destination table lifecycle and schema change planning, shared by data-loss confirmation and transactional updates. Renames preserve stored values; removals and type changes require confirmation.
- Source structure preparation ignores superseded runs, imports repeated chunks without duplicates, and preserves the published snapshot when a refresh fails.
- Immutable `Transformation` descriptions with Filament labels, icons, colors, descriptions, and settings forms, registered through `FerryPlugin::transforms()`. Runtime `TransformHandler` implementations resolve separately from `phpinnacle-ferry.transforms` by key and can run without editor registrations.
- Shared `sourceSettings()` schemas on `FieldMapping` and `FieldBinding`, with independent native Filament settings for every source. Mapping state separates `sources` from `targets`; binding state separates `sources` from `bindings`. The standalone `FieldTransforms` field owns transformation editing; the mapping components no longer store or validate transformation formats.
- Ordered record transforms in a Filament Builder and per-source add menus with draggable field transform chips. Every chip has a separate remove button; configurable labels open individual settings modals without deletion actions. Transforms without settings are added immediately. Controls are available before connecting or binding; field and record transforms can repeat with independent settings, ordering, and removal.
- Built-in cast and record filter, plus optional trim, substring, and mask editor descriptions. Transform validation uses source metadata and resulting destination types; filters request additional source columns and preserve tombstones.
- Field transform menus use `inputSchema()` and the current chain's output to offer compatible additions. Immutable `Transformation::inputTypes()` and `outputType()` declarations own editor type behavior; native description forms validate parameters without resolving execution handlers. Existing chips remain editable and removable after incompatible edits or reordering; synchronization saving retains handler-based validation.
- All mapping destinations accept static values; inline editors are selected by type through `staticForms()`, without a destination allowlist. Static values stored separately as `FieldMapping::constant` with a typed `StaticValue`, compiled to insertion and cast transforms after field renames.
- JSON Schema-based `FieldMapping` with destination-keyed source/value entries, whole-array or individual-item connections, output type checks, inline Filament static editors, and inverse layout.
- Configurable `SchemaCompatibility` checks for directional formats and string length bounds, including composed schemas and array items. `canConnectUsing()` adds application restrictions shared by candidate availability and submission validation.
- `FieldBinding` supports simple strings or custom child schemas, separate static values, and source actions. Both fields support search, containment, compact spacing, metadata, and warnings.
- Unavailable mapping candidates ignore clicks without changing mappings or showing errors; existing incompatible connections remain visible.
- Source object titles and fields come from `ConnectionMetadata`; references are distinct logical column types and `ColumnType::jsonSchema()` supplies editor types and formats.
- Connection tests always disconnect, including after a failed query, and return sanitized error messages.
- Tests exercise import, persistence, connector, validation, and editing outcomes with shared schema fixtures, independently runnable PHP and JavaScript suites, and no live Kafka dependency.

## 1.0.0 - 202X-XX-XX

- initial release
- `Connection` model with encrypted credentials, an activity toggle and connection testing
- queued structure preparation storing the semantic 1C metadata as `ConnectionMetadata` snapshots
- `Sync` model storing the source object, the field mapping schema and the generated destination table name
- Filament `SyncResource` mapping published 1C fields onto a physical destination table named `{prefix}{code}`
- destination tables carry a single technical column `idrref` as the primary key holding the 1C row identifier
- mapping changes add, rename and drop columns, and ask for confirmation whenever stored data would be lost
- synchronizations are paused automatically when a republished structure drops or retypes a mapped field
