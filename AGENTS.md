<required-skills>

- You MUST activate the `engineering-discipline` skill for every task that may modify files in this package.

</required-skills>

<shared-phpinnacle-package-guidelines>

# PHPinnacle Package Guidelines

These rules summarize the project-wide expectations for work in this package. Package-specific instructions outside this block may narrow or strengthen them.

## Scope and Compatibility

- Treat the package as an independently releasable Composer unit. Before changing it, inspect its `README.md`, `composer.json`, service provider, Filament plugin, configuration, migrations, assets, tests, and static-analysis setup as applicable.
- Keep changes inside the package and its declared dependency boundaries. Consume another package through its public API; do not create reverse or cyclic dependencies.
- Preserve observable behavior and public contracts unless the task or package-specific instructions explicitly permit a breaking change.
- Do not add dependencies or new top-level directories without approval.
- Keep Laravel package wiring in the service provider and panel wiring in the Filament plugin. These are composition roots, not homes for runtime domain behavior.

## Design and Ownership

- Implement the smallest complete solution for the current requirement. Do not add speculative features, configuration, compatibility layers, or extension points.
- Prefer existing Laravel, Filament, Livewire, and package components over local framework-like abstractions.
- Use arrays for lists, maps, and serialization boundaries. Convert structured domain state into typed value objects before domain behavior begins.
- Use explicit parameter and return types on named methods, constructor property promotion, descriptive names, and PHPDoc array shapes where arrays are intentional.
- Let Eloquent models own their persisted state, relationships, lifecycle defaults, model-scoped reads, and cohesive state transitions.
- Use services for external APIs, provider protocols, registries, reflection or storage adapters, parsing and formatting, authorization orchestration, and cross-boundary transactions.
- Never store request-, user-, or tenant-specific state in static properties or singleton services under Octane.

## Inputs, Invalid Values, and Errors

- Classify each input boundary before handling it: developer configuration, user or form state, persisted data, and external payloads require different failure semantics.
- Validate user-controlled input through Laravel or Filament validation. Custom rules must type-check `mixed` values, call `$fail(...)->translate()`, and stop invalid state from entering the domain.
- Domain value objects must fail fast on violated invariants. Use `InvalidArgumentException` for invalid caller arguments and named domain exceptions for failures callers need to distinguish. Apply the root trusted-state rules to developer-facing fluent APIs; do not add defensive validation of trusted configuration.
- Represent expected business outcomes with result objects or enums. Use `null`, `false`, empty collections, and early returns only when absence, availability checks, or idempotent no-ops are part of the method contract.
- Normalize or fall back only when that behavior has explicit domain meaning. Do not silently repair invalid persisted or external data.
- Catch exceptions only where they can be recovered, translated, enriched, cleaned up, or rolled back. Unexpected transactional failures must be rethrown; swallowed `Throwable` is reserved for explicit best-effort work that must not break the primary request.

## Working Method and Verification

- Inspect the relevant implementation and tests before choosing a design. Surface assumptions only when they materially affect public contracts, persisted data, authorization, security, or external side effects.
- Make surgical changes: every changed line must serve the requested outcome, its verification, or cleanup made necessary by the change. Preserve unrelated user work and report unrelated defects instead of fixing them.
- Remove only imports, code, tests, assets, and configuration made obsolete by the current change.
- Define observable success before implementation. Reproduce bugs with the narrowest practical test, cover invalid inputs for validation changes, and establish a passing baseline before refactors.
- Follow the root verification rules for the changed file types. For PHP changes, run `composer format`, focused Ferry Pest tests, and repository-wide `composer lint` from the monorepo root.
- Finish by inspecting the diff for scope drift, hidden behavior changes, unnecessary complexity, and incomplete cleanup, and run `git diff --check`.
- Update an existing README when the public API or documented user-facing behavior changes. Do not create new documentation files unless explicitly requested.

</shared-phpinnacle-package-guidelines>
