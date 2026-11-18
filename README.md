# Proxyma

Приватный сетевой пакет для Laravel: каталог маршрутов, политики, durable admission,
бюджеты, transport, health и bounded diagnostics. PostgreSQL — обязательный authority;
HTTP, DNS и разрешение секретов выполняются вне SQL transaction.

| Пакет | Subpath | Внутренняя версия |
| --- | --- | --- |
| `axiomasoft/proxyma-core` | `packages/core` | `0.1.0` |
| `axiomasoft/proxyma-filament` (optional UI) | `packages/filament` | `0.1.0` |

Workspace root `axiomasoft/proxyma-monorepo` не устанавливается в consumer.
Оба пакета поставляются из **одного полного commit SHA**, через отдельные Composer
path repositories с `symlink:false` и явной картой versions. Filament требует
`self.version` core. Теги, Packagist, push и публичный release не нужны.

Статус — **private candidate**, до финальной проверки Agelto. Package qualification
не означает accepted integration. Descriptor
[`integration/agelto/candidate.json`](integration/agelto/candidate.json) сохраняет
реальный qualification source commit и hashes. После commit исходников checker
разрешает точный install SHA в отдельный файл, исключая самоссылочный checksum.

Состояние финальной приёмки — [acceptance.json](integration/agelto/acceptance.json).
`pending_checks` сохраняется до реальных installed-consumer gates. Итоговый collector
проверяет native Agelto reports, оба полных fixture load-профиля, populated upgrade,
cutover/rollback, copied vendor и единый source SHA. Порядок подготовки и сбора —
[финальная приёмка Agelto](docs/integrations/agelto-acceptance.md).

- [Приватная установка и проверка candidate](docs/private-installation.md)
- [Совместимость и installed fixtures](docs/compatibility.md)
- [Конфигурация](docs/configuration.md), [API](docs/api.md), [расширения](docs/extensions.md)
- [Filament](docs/filament.md), [операции и расписание](docs/operations.md)
- [Обновление, cutover и rollback](docs/upgrading.md)
- [Security](docs/security.md), [qualification evidence](docs/verification.md)
- [Changelog](CHANGELOG.md)

Квалифицированный runtime: PHP 8.4.27 / Laravel 13 / PostgreSQL 17.11;
optional Filament 5. Точные dependency sets и ограничения перечислены в compatibility.
Core не зависит от `App`, Process, LLM или Filament. Safety stack не отключается.
Authorizer по умолчанию запрещает операции; приложение задаёт trusted tenant/actor
и явные adapters. After-commit observers — local best-effort; durable audit
не означает гарантированную внешнюю доставку событий.

Проверки разработки запускаются из repository root в isolated rig:

```bash
make test-up
make test-env
make test-file TEST_FILE=tests/Installation/PackageInstallTest.php
```

Полную qualification и итоговый `make test-report` запускает coordinator на
финальном дереве. Стенд не использует live crawling, платные API или dev DB.
Исходный Agelto baseline и hashes сохранены в
[`source-manifest.json`](docs/reference/source-manifest.json).
