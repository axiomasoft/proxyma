# Proxyma

Сетевой пакет для Laravel: каталог маршрутов, политики, durable admission,
бюджеты, transport, health и ограниченные diagnostics. PostgreSQL — единственный
authority. HTTP, DNS и разрешение секретов выполняются вне SQL-транзакции.
Authorizer по умолчанию запрещает операции. Таймаут после авторизации не
доказывает, что байты не ушли.

Репозиторий: <https://github.com/axiomasoft/proxyma>. Лицензия proprietary
([LICENSE](LICENSE)): просмотр не даёт права копировать, изменять или
распространять. Пакетов нет в Packagist.

| Пакет | Путь | Namespace |
| --- | --- | --- |
| `axiomasoft/proxyma-core` | `packages/core` | `Proxyma\` |
| `axiomasoft/proxyma-filament` (необязательный UI) | `packages/filament` | `Proxyma\Filament\` |

Корень монорепозитория `axiomasoft/proxyma-monorepo` в приложение не ставится.
Composer не отдаёт два вложенных пакета из корневого VCS-репозитория. Consumer
клонирует этот репозиторий и соседний Packman и подключает каталоги пакетов
отдельными path repositories с `symlink: false` и явной картой `versions`.
Filament требует `self.version` core, поэтому обоим пакетам Proxyma задаётся
одна и та же строка версии. Порядок, версии Packman и обновление —
[установка](docs/installation.md).

Чтобы не регистрировать плагин, добавьте `axiomasoft.proxyma` в
`packman.disabled_plugins`.

Нужны PHP ^8.4, Laravel 13, PostgreSQL 17 и расширения curl, mbstring, openssl,
pdo, pdo_pgsql. Filament 5 — только для UI. Точные границы —
[совместимость](docs/compatibility.md). Safety stack не отключается.

- [Установка и обновление](docs/installation.md)
- [Совместимость](docs/compatibility.md)
- [Конфигурация](docs/configuration.md), [API](docs/api.md), [расширения](docs/extensions.md)
- [Filament](docs/filament.md), [операции](docs/operations.md)
- [Обновление authority и rollback](docs/upgrading.md)
- [Security](docs/security.md), [квалификация](docs/verification.md)
- [Changelog](CHANGELOG.md), [как сообщить об уязвимости](SECURITY.md)

Разработка из корня репозитория:

```bash
make test-up
composer test
```

`composer test` не входит в группы `pg` и `docker-rig`. Полный прогон стенда —
`make test-up && make test-all`. Команды и запрет на коммит `vendor/` и `build/` —
[CONTRIBUTING.md](CONTRIBUTING.md), устройство стенда — [docs/testing.md](docs/testing.md).
Стенд не ходит в живой crawl и не вызывает платные API.
