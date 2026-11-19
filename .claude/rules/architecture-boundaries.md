# Architecture Boundaries & Layer DAG

- `axiomasoft/packman-monorepo` consists of 8 packages arranged in a strictly acyclic 4-layer DAG (`L1 -> L2 -> L3 -> L4`) declared in `packman.json`:
  - `L1` (`kernel`, profile `php-library`): `axiomasoft/packman` (`packages/core`, `Packman\`)
  - `L2` (`addon`, profile `laravel-package` / `modular-platform`): `axiomasoft/packman-schema` (`packages/schema`), `axiomasoft/packman-plugins` (`packages/plugins`), `axiomasoft/packman-modules` (`packages/modules`), `axiomasoft/packman-panels` (`packages/panels`)
  - `L3` (`integration`, profile `laravel-package`): `axiomasoft/packman-filament` (`packages/filament`)
  - `L4` (`dev`, `dev_only: true`): `axiomasoft/packman-testkit` (`packages/testkit`), `axiomasoft/packman-devtools` (`packages/devtools`)
- Never import upward across layers or depend on `dev_only` packages at runtime. Internal types belong to their declared owner, whether identified by namespace or `#[Internal]`. A product may explicitly group local runtime subpackages through `gates.internal_ownership_groups`; external dependencies and dev-only packages cannot join. Packman itself keeps independent ownership for all eight packages (`PackageGraphGate`, `OwnershipGate`).
- `packages/core` (`php-library`) must never require `laravel/framework` or `illuminate/*` in `composer.json` `require` (`ModuleLayoutGate`). Pure PHP surfaces (`Attributes`, `Context`, `Errors`, `Events`, `Features`, `Settings`, `Extensions`, `Config/{Contracts,Data,Exceptions,Internal}`, `Doctor/{Contracts,Data,Enums,Internal}`) must execute with zero Laravel runtime dependencies.
- Keep runtime mechanisms, schemas and generators independent of downstream product rules. Architecture documentation and interoperability fixtures may name real consumers to explain and verify a shared need; their product models and policies remain outside Packman.
- Before moving a mechanism into Packman, identify multiple real consumers and their common requirement. Packages own domain rules; hosts own activation, configuration sources and migration execution. Composer discovery alone must not open a database connection.
