# Контракт проекта Proxyma

Публичный репозиторий <https://github.com/axiomasoft/proxyma>. Лицензия
proprietary: просмотр не даёт права копировать, изменять или распространять.
Два пакета Composer: `axiomasoft/proxyma-core` (`packages/core`, namespace
`Proxyma\`) и необязательный `axiomasoft/proxyma-filament` (`packages/filament`).
Корень `axiomasoft/proxyma-monorepo` в приложение не устанавливается. Packagist
не используется.

Swissknifeman, launcher `plans/` и приёмка Agelto в этот контракт не входят.
Их каталоги и скрипты не часть дерева и не воссоздаются. Не коммитить `vendor/`,
`build/`, `.claude/`, `.codex/`, `.gemini/`, `.swissknifeman/`, `integration/`.
Dependabot нет: смену зависимостей смотрит человек.

Packman — соседний path dependency (`../packman/packages/*`). CI фиксирует
<https://github.com/axiomasoft/packman> на commit
`a5a5442898cabb2d22e9f6a47a83caa798e3e5a3`. Версии path map: пакеты Packman
`0.1.1`, `axiomasoft/packman-plugins` `0.1.2`. Установка потребителя —
`docs/installation.md`: отдельные path repositories, `symlink: false`, явные
`versions`, одна строка версии у обоих пакетов Proxyma.

Квалифицируемые цели линии: PHP 8.4, Laravel 13, PostgreSQL 17, опционально
Filament 5. Точные installed fixtures и отказ другим authority — `docs/compatibility.md`.

Инварианты, которые нельзя ослаблять:

- PostgreSQL — единственный stateful backend. SQLite не доказывает authority,
  блокировки и квоты.
- Core не зависит от `App\`, Filament, ProcessCore и биллинга LLM и не импортирует
  приложение-хост. Хост владеет активацией, источниками конфигурации и запуском
  миграций.
- Общая авторизация процесса, финансов и сети — одна транзакция и одно соединение
  PostgreSQL. Внутри SQL-транзакции нет внешнего I/O.
- Долгие дедлайны authority читают `clock_timestamp()`. Таймаут после авторизации
  не доказывает, что запрос не был отправлен.
- Ownership, TLS, бюджет, health и изоляция БД не обходятся флагами и тестовыми
  двойниками в production-пути. `verifyPeer=false` и `verifyHost=false` невозможны.
- Стенд использует изолированную базу `proxyma_test`. Не сбрасывать чужие и
  общие базы. Нет живого crawl и вызовов платных провайдеров.
- Конкурентные проверки PostgreSQL идут отдельными соединениями и процессами,
  не одной rollback-транзакцией.

Проверки: `./bin/php84`, `composer lint:check`, `composer analyse`,
`composer test` (без групп `pg` и `docker-rig`), `composer test:pg`,
`make test-up && make test-all` в изолированном стенде. Не выводить интерпретатор
PHP из примеров: брать `./bin/php84`, если он есть в дереве.

Агент, который принимает архитектуру, не поручает ослабление этих границ.
Чужие незакоммиченные изменения в рабочем дереве сохраняются. В отчёте о
проверках пишется то, что реально было запущено, и его результат.
