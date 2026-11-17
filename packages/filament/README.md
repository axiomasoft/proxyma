# Proxyma Filament (`axiomasoft/proxyma-filament`)

Filament v5 administration integration for Proxyma, implementing `Packman\Filament\Contracts\FilamentPanelPlugin` and delegating guarded binding forms to `Packman\Filament\Forms\BindingEditor`.

## Features

- `ProxymaPlugin` implementing `FilamentPanelPlugin` with explicit panel access and ability defaults.
- Catalog browser, policy inspector, settings section, and bindings editor pages backed by headless `axiomasoft/proxyma` services.
