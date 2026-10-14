# Changelog

All notable changes to `ferry` will be documented in this file.

## Unreleased

- Kafka Connect connector management over `phpinnacle/franz`: one Debezium source connector per connection, one JDBC sink connector per synchronization
- `ConnectorStatus` of both connectors stored on the connection and the synchronization and surfaced in the Filament tables
- activate, pause, restart and status actions for synchronizations, and a source connector status action for connections
- `phpinnacle-ferry.kafka_connect` configuration block
- connector actions require the `update` permission of `SyncPolicy`
- the shared source connector keeps capturing the table of a paused synchronization, so no change is lost while its sink is stopped
- each sink restricts itself to its own mapped columns with `fields.whitelist`
- a failed Kafka Connect task is reported as a connector failure together with its trace
- `is_paused` distinguishes an administrator pause from an automatic one, and editing a synchronization no longer resumes an administrator pause
- destination tables are created, altered and dropped from the `Sync` model lifecycle, so commands, jobs and seeders behave like the panel
- an ad hoc incremental snapshot is requested through Debezium's Kafka signal channel whenever activation captures a table that the shared source connector was not already tracking, so its existing rows are not silently missed
- `FieldBinding` texts are translatable
- `StaticDestination` definition and a registry exposed through `FerryPlugin::destinations()`, so a synchronization can fill an application-owned table instead of a generated one; only declared fields of a compatible logical type can be mapped, every required field must be mapped, and the table is never created, altered or dropped
- `DestinationFactory::resolve($sync)` selects a registered `StaticDestination` or a generated `DynamicDestination`; both independently implement the `Destination` interface and compose `JdbcConnectorConfig` to build their sink configuration
- the sink of a static synchronization targets the destination's own database connection and primary key, writes configured fixed values through `InsertField` transforms and never propagates source deletions
- the synchronization form offers dynamic and registered static destinations in a single select: a dynamic synchronization binds every mappable 1C field, listed with its human-readable title and technical column, to a column name with `FieldBinding`, while a static one connects source fields to the declared destination fields with `FieldMapping`, which shows their logical types and required marks and accepts only compatible types
- `FieldMapping` texts are translatable
- the source object select lists references first and obsolete objects last, with titles sorted case-insensitively
- `ConnectionMetadata::displayTitle()` and `ConnectionMetadata::mappableFields()` expose the presentation of a 1C object and of its mappable fields
- `ColumnType::Reference` separates 1C references and identifiers from plain strings, and the source connector emits binary keys as hex through `binary.handling.mode`
- a synchronization whose static destination is no longer registered is paused by the next structure review and reported with an out-of-date mapping instead of failing the review
- `Transform` contract with record and field scopes and a registry exposed through `FerryPlugin::transforms()`; the built-in cast and static value transforms are registered through the same `TransformFactory`
- `FieldMapping::constants()` and `FieldBinding::constants()` let a destination receive a static value instead of a source field; the synchronization form offers them for static destination fields of a castable type and, with a column name, type and value row, for dynamic synchronizations, and keeps them when the form is filled again
- record transforms are stored on `syncs.transforms` in the administrator's order and field transforms on `FieldMapping`; a mapping without a source is a static value; `SyncSchema` validates their parameters and checks the resulting type against the destination; `Destination::connector()` receives the `TransformFactory` and `JdbcConnectorConfig` places the transforms around the sink renames
- `Transform::schema($object)` describes the parameter form of a transform for the selected object; the synchronization form offers one ordered Transforms block above the field mapping, where the chosen transform decides whether a 1C field is asked for and which parameters follow, keeps the rows when the form is filled again and checks a static connection with the type a field has after its transforms
- built-in `FilterTransform` keeps or drops records by the value of a 1C field through Confluent's `Filter$Value`; `Transform::sourceColumns()` lets a transform request source columns that no mapping uses, the filter skips tombstone records through the Kafka `RecordIsTombstone` predicate, and `ColumnType::valueRule()` is shared by the value checks of transforms
- `Transform::normalize()` completes the parameters of a record transform from the selected object when a synchronization is saved, so the filter takes the type of its column from the object's metadata; `SyncSchema::fromTransforms()` requires the columns a transform reads to exist in the object, and the form resets the transforms when the connection or the object changes
- `TransformScope::Static` separates the static value, which fills a destination field without a source field, from field transforms, so the Transforms block offers exactly the record and field transforms
- `SyncSchema::fromBindings()` takes the `TransformFactory` as its third argument and `SourceFactory` requires it, so neither falls back to an empty registry
- `ConnectorManager::activate()` always pushes and resumes both connectors, while saving a synchronization calls it with `refreshSource: false`, so the shared source connector is pushed only when its configuration differs from the stored one and only the own sink connector is updated otherwise
- `TransformFactory::appliesTo()` lets the structure review pause, and the activation refuse, a synchronization whose transforms are no longer registered or whose filter column disappeared or changed its type; a transform that is no longer registered no longer breaks the source connector of the other synchronizations
- the filter supports string, reference and boolean fields, and a cast of a 1C number accepts only a string result, because the source connector emits numbers as Kafka Connect decimals
- transform validation errors are reported under the Transforms block with the transform label, and an empty static value is rejected
- record transforms are configured in their own **Record transforms** block and field transforms from a **Transforms (N)** button on every mapped field, provided by the new `extraSourceActions()` of `FieldBinding` and `FieldMapping`; the form keeps field transforms in `field_transforms` and drops those of unmapped fields
- a transform block that has a single registered transform preselects it for a new row and hides its select, so the administrator goes straight to its parameters

## 1.0.0 - 202X-XX-XX

- initial release
- `Connection` model with encrypted credentials, an activity toggle and connection testing
- queued structure preparation storing the semantic 1C metadata as `ConnectionMetadata` snapshots
- `Sync` model storing the source object, the field mapping schema and the generated destination table name
- Filament `SyncResource` mapping published 1C fields onto a physical destination table named `{prefix}{code}`
- destination tables carry a single technical column `idrref` as the primary key holding the 1C row identifier
- mapping changes add, rename and drop columns, and ask for confirmation whenever stored data would be lost
- synchronizations are paused automatically when a republished structure drops or retypes a mapped field
