# Proxyma Core (`axiomasoft/proxyma-core`)

Governed egress routing, transactional quota admission, health tracking, headless panels, and runtime policy resolution for Laravel on PHP `^8.4`, powered by `axiomasoft/packman`.

## Features

- Strongly typed, closed-schema configuration (`ProxymaOptions` + domain sub-options) with secret masking (`SecretRef`) and table prefix validation (`TablePrefix`).
- Declarative `ProxymaCorePlugin` (`ExtensionPlugin`) composing `WithConfiguration`, `WithSchema`, `WithCapabilities`, `WithSlots`, `WithPanels`, and `WithDiagnostics`.
- PostgreSQL central schema set (`ProxymaSchemaSets`) managed via `axiomasoft/packman-schema`.
- RFC 9457 error taxonomy (`ProxymaErrorCode`, `ProxymaProblemDefinitions`) and runtime diagnostics (`ProxymaDoctorCheck`).

## Installing the development snapshot

The plugin requires `axiomasoft/packman-plugins ^0.1.2` for host-bound schema metadata. When installing from source before a tagged release, use Packman commit `e26ecd47ec2eb0e1c422f30c8b3bbea878b1b99a` for the Packman packages. For a Composer path repository over the exported `packages/*` directory, set `options.symlink` to `false` and assign `axiomasoft/packman-plugins` version `0.1.2` in `options.versions`.

Laravel discovery offers the plugin to the host. To disable Proxyma, add `axiomasoft.proxyma` to `packman.disabled_plugins`. An active host can set `proxyma.table_prefix` before boot and can select the central PostgreSQL migration set `axiomasoft/proxyma-core:proxyma` explicitly.
