# Changelog

## [Unreleased]

### Added

- Финальный collector installed Agelto: native JUnit/scenario provenance, одинаковые
  source/runtime inputs семи gates, оба 1800-second load-профиля, runtime vendor hashes
  и проверка legacy removal. Offline re-pin обновляет оба пакета вместе.
- Историческая acceptance matrix связывает удалённые network tests с полными
  contract replay inventories, не меняя original-executed-scenarios/scenario-transfers.

- Приватная поставка двух пакетов с внутренней версией 0.1.0, qualification
  provenance и отдельным разрешением install SHA после source commit.
- Checker candidate/accepted proof и runbook чистой установки, эксплуатации
  и безопасного обновления.

### Changed

- Shared diagnostic registration and public doctor construction defer PostgreSQL resources until a diagnostic operation runs; unavailable storage still produces the existing structured finding.

- P10.4 завершён как integration checkpoint с отклонениями по решению владельца;
  оптимизация и незавершённая полная приёмка вынесены в PLAN2:P1.1.

- Policy-head блокировки admission получаются одним SQL-запросом с сохранением
  порядка scope/tenant, проверки revisions и отказа при отсутствующем состоянии.
- Filament требует `self.version` core: явная path version map задаёт обоим
  0.1.0; development workspace сохраняет согласованный dev-main.

## [0.1.0] — private candidate

### Added

- Независимый core и optional Filament UI; каталог и retirement, layered policy,
  tenant isolation, typed finite extensions, mandatory durable admission,
  multi-scope GCRA/concurrency/fixed UTC quotas, health/probes, verified transport,
  bounded diagnostics и conservative retention.

Кандидат не является публичным release или accepted Agelto integration.
Финальная приёмка требует installed consumer, populated upgrade, сохранения
runtime state, удаления заменённой реализации и согласованных source/lock proofs.
General external outbox, remote owner authority, stateless safety bypass,
rolling/DST windows и adaptive routing в 0.1 не обещаются.
