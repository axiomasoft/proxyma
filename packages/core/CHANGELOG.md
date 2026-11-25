# Changelog

## Unreleased

- Migrated package onto `axiomasoft/packman` (`^0.1`) ecosystem foundation (`packman`, `packman-plugins`, `packman-panels`, `packman-schema`).
- Added immutable typed `ProxymaOptions`, `ProxymaCorePlugin`, `ProxymaSchemaSets`, `ProxymaPanelProvider`, `ProxymaDoctorCheck`, `ProxymaErrorCode`, and `ProxymaProblemDefinitions`.
- Regrouped `Proxyma\Contracts` into semantic domain subfolders with backward-compatible class aliases in `src/aliases.php`.
- Decomposed `ProxymaServiceProvider` into four focused registrars under `Proxyma\Laravel\Registrars`.
