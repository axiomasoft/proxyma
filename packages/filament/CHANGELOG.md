# Changelog

## Unreleased

- Migrated `ProxymaPlugin` onto `Packman\Filament\Contracts\FilamentPanelPlugin`.
- Delegated `BindingEditor` context encryption/tamper checks and field encoding to `Packman\Filament\Forms\BindingEditor`.
- Refactored `ProxymaFilamentServiceProvider` using `RegistersPackageRegistrars`.
