# Code plugins and compiled platform modules

- Use **`axiomasoft/packman-plugins`** (`Packman\Plugins\`) for stateless intra-package code plugins:
  - Implement `ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`) with zero required constructor arguments, zero I/O, and zero database coupling (`packman-plugins` does not depend on `illuminate/database`).
  - Declare `ContributionKind`, `CapabilityDefinition` (`Cardinality`), and `SlotDefinition` (`SlotKind`) via `PluginRegistrar` or composable `With*` traits.
  - `PluginGraphCompiler` resolves Kahn's topological load order, enforces transitive ancestor ownership, and freezes `FrozenContributions` (`Contributions`, `PluginInspector`, `Capabilities`, `ExtensionRegistry`).
  - The host can bind `PluginActivationGate`. Packages can add gates through `ExtensionRegistrar::addActivationGate()`. Explicit disabled IDs and every applicable gate govern registration. Discovery reads only the manifest. After activation the application may supply a configured instance of the same class with the same manifest.
- Use **`axiomasoft/packman-modules`** (`Packman\Modules\`) for compiled platform modules.
  - Implement `ModuleDefinition` (`manifest(): ModuleManifest`) and configure Composer `type` / `extra` discovery keys via `ModulesOptions` (`modules.*` / `packman.modules.*`).
  - `RegistryCompiler` executes the closed 8-stage pipeline (`PipelineStages::ORDER`: `Entity -> EntityField -> Permissions -> Api -> Filament -> Menu -> Routes -> Workflow`) and `CompiledModuleRegistry` writes `bootstrap/cache/modules.php` atomically.
  - Code-only declarations may omit a table prefix. Bind `ModuleDiscovery` for other discovery sources or `ModuleStateReader` for activation supplied in code or configuration.
  - The default SQL implementation `DatabaseModuleLifecycle` manages tenant-scoped state transitions across `mod_modules`, `mod_records`, and `mod_lifecycle_log`.
- Prefer composing `Packman\Extensions\Contracts\{Configuration,Persistence,Composition,Runtime}\With*` interfaces and default traits (`Configurable`, `InteractsWithDatabaseSettings`, `RegistersInDatabase`, `OwnsSchema`, `ProvidesCapabilities`, `ContributesToSlots`, `ProvidesPanels`, `HasLifecycleHooks`, `ProvidesDiagnostics`, `DeclaresFeatureFlags`), inspected uniformly by `ExtensionInspector::inspect()`.
