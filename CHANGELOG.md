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
- `StaticDestination` contract and a registry exposed through `FerryPlugin::destinations()`, so a synchronization can fill an application-owned table instead of a generated one; only declared fields of a compatible logical type can be mapped, every required field must be mapped, and the table is never created, altered or dropped
- the sink of a static synchronization targets the destination's own database connection and primary key, writes `fixedValues()` through `InsertField` transforms and never propagates source deletions
- the synchronization form offers dynamic and registered static destinations in a single select: a dynamic synchronization binds every mappable 1C field, listed with its human-readable title and technical column, to a column name with `FieldBinding`, while a static one connects source fields to the declared destination fields with `FieldMapping`, which shows their logical types and required marks and accepts only compatible types
- `FieldMapping` texts are translatable
- the source object select lists references first and obsolete objects last, with titles sorted case-insensitively
- `ConnectionMetadata::displayTitle()` and `ConnectionMetadata::mappableFields()` expose the presentation of a 1C object and of its mappable fields
- `ColumnType::Reference` separates 1C references and identifiers from plain strings, and the source connector emits binary keys as hex through `binary.handling.mode`
- a synchronization whose static destination is no longer registered is paused by the next structure review and reported with an out-of-date mapping instead of failing the review

## 1.0.0 - 202X-XX-XX

- initial release
- `Connection` model with encrypted credentials, an activity toggle and connection testing
- queued structure preparation storing the semantic 1C metadata as `ConnectionMetadata` snapshots
- `Sync` model storing the source object, the field mapping schema and the generated destination table name
- Filament `SyncResource` mapping published 1C fields onto a physical destination table named `{prefix}{code}`
- destination tables carry a single technical column `idrref` as the primary key holding the 1C row identifier
- mapping changes add, rename and drop columns, and ask for confirmation whenever stored data would be lost
- synchronizations are paused automatically when a republished structure drops or retypes a mapped field
