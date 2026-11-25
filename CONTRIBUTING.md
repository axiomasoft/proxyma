# Разработка

Команды выполняются из корня репозитория. PHP 8.4 запускайте через `./bin/php84`,
если системный `php` старше. Скрипт требует `php8.4` в `PATH` или `PHP84_BIN`.

Перед изменением контрактов:

```bash
./bin/php84 composer lint:check
./bin/php84 composer analyse
./bin/php84 composer test
./bin/php84 composer test:pg
```

`composer test` исключает группы `pg` и `docker-rig`. `composer test:pg` гоняет
группу `pg` против PostgreSQL из `phpunit.xml`: хост `127.0.0.1`, порт `54329`,
база и пользователь `proxyma_test`. Другую базу эта группа не доказывает.
SQLite in-memory из того же `phpunit.xml` не является authority.

Полный прогон — внутри изолированного стенда `proxyma_test`, без портов
PostgreSQL на хосте и без живого crawl или платных API:

```bash
make test-up && make test-all
```

Устройство стенда — [docs/testing.md](docs/testing.md). Квалификация пакета —
[docs/verification.md](docs/verification.md).

Dependabot нет. Обновления зависимостей смотрит человек: lock, нижние границы
из [совместимости](docs/compatibility.md) и адвайзори транзитивных пакетов не
подменяются ботом.

Не коммитьте `vendor/`, `build/`, `.claude/`, `.codex/`, `.gemini/`,
`.swissknifeman/`, `integration/`. Это не часть публичного дерева.
