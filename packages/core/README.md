# Proxyma Core (`axiomasoft/proxyma`)

Governed egress routing, transactional quota admission, health tracking, headless panels, and runtime policy resolution for Laravel on PHP `^8.4`, powered by `axiomasoft/packman`.

## Features

- Strongly typed, closed-schema configuration (`ProxymaOptions` + domain sub-options) with secret masking (`SecretRef`) and table prefix validation (`TablePrefix`).
- Declarative `ProxymaCorePlugin` (`ExtensionPlugin`) composing `WithConfiguration`, `WithSchema`, `WithCapabilities`, `WithSlots`, `WithPanels`, and `WithDiagnostics`.
- PostgreSQL central schema set (`ProxymaSchemaSets`) managed via `axiomasoft/packman-schema`.
- RFC 9457 error taxonomy (`ProxymaErrorCode`, `ProxymaProblemDefinitions`) and runtime diagnostics (`ProxymaDoctorCheck`).
