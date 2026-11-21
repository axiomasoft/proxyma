# Changelog

## [Unreleased]

### Added

- Публичная установка двух пакетов из клонов GitHub описана в `docs/installation.md`.
- CI: отдельный быстрый job (lint, PHPStan, unit) и изолированный PostgreSQL rig. Dependabot не подключается.

### Changed

- Репозиторий публичный. Документация описывает установку потребителя, а не приватный candidate.
- DNS lookup отклоняет некорректный host до запуска процесса, не передаёт секреты окружения дочернему резолверу и не кладёт его вывод в текст ошибки.
- Редактор диагностик закрывает userinfo и query у любой схемы и обрезает строку длиннее 64 КиБ.
- `proxyma:transport-doctor` отклоняет значение `--protocol`, которое не является списком, до обхода.
- Политика назначения запрещает устаревший site-local IPv6 `fec0::/10` и anycast 6to4 `192.88.99.0/24`.
- DNS-запрос абсолютный: имя уходит с завершающей точкой, дочерний процесс запускается без унаследованного окружения.
- Панель с `guardPanel()` запрещает вход, если ability из `entry()` не зарегистрирована в Gate.
- Shared diagnostic registration and public doctor construction defer PostgreSQL resources until a diagnostic operation runs; unavailable storage still produces the existing structured finding.

- P10.4 завершён как integration checkpoint с отклонениями по решению владельца;
  оптимизация и незавершённая полная приёмка вынесены в PLAN2:P1.1.

- Policy-head блокировки admission получаются одним SQL-запросом с сохранением
  порядка scope/tenant, проверки revisions и отказа при отсутствующем состоянии.
- Filament требует `self.version` core: явная path version map задаёт обоим
  0.1.0; development workspace сохраняет согласованный dev-main.

### Removed

- Из дерева убраны свидетельства cutover, каталоги IDE-агентов (`.claude`, `.codex`, `.gemini`, `.swissknifeman`) и приватный supply checker (`integration/`, `scripts/package-check.py`, `scripts/agelto*.py`).

## [0.1.0]

### Added

- Независимый core и optional Filament UI; каталог и retirement, layered policy,
  tenant isolation, typed finite extensions, mandatory durable admission,
  multi-scope GCRA/concurrency/fixed UTC quotas, health/probes, verified transport,
  bounded diagnostics и conservative retention.

Запись 0.1.0 фиксирует состав линии на тот момент. Она не является тегом
публичного репозитория и не подтверждает приёмку приложения-хоста. Сохранение
runtime state, populated upgrade и удаление прежней реализации остаются
отдельными проверками. General external outbox, remote owner authority,
stateless safety bypass, rolling/DST windows и adaptive routing в 0.1 не обещаются.
