# Отложенная задача: производительность и полная приёмка

Задача Native Planner: **PLAN2:P1.1**. Не запускать автоматически при закрытии P10.4.
Решение владельца **PLAN1:D19** от 2026-11-14: прекратить текущий поиск оптимизации,
закрыть P10.4 с отклонениями и продолжить оптимизацию отдельной задачей.

P10.4 закрывает checkpoint интеграции. **Полная приёмка приватной 0.1 не выполнена.**
Статус плана не означает готовность production, live-госзакупок или публичного release.

## Закреплённое состояние

- Proxyma source и оба установленных пакета: `d564574e94ab6d854f498596c3a098a28d4e8529`.
- Agelto integration checkpoint: `365a93cdbe4d537c70f5c6e1517e6f7d4491ed85`.
- Agelto pin checkpoint: `8c38a2b165723a47d6e45b6f82b70e61fadd4109`.
- Composer contract, чистый dependency checkout и payload hashes сверены при закрытии.
- Последняя полная package qualification: source `06861ce54fcff36da99d3e56c81facc6627e23cc`,
  53 файла, 666 сценариев, 30 invariants. Она не квалифицирует последующие изменения.
- `candidate.json` сохраняет именно этот ранее квалифицированный source; его нельзя
  считать descriptor текущего установленного diagnostic pin. Пересоздать штатным
  candidate/resolve только после полной qualification финального source.

Core corrections: пакетное получение policy locks, shared locks только read-only
catalog tiers и тот же путь в completion/recovery. Process lane fence, mutable health,
budget/permit/demand/attempt/quota locks и writer fences сохранены. Последние целевые
проверки: TransactionCompositionTest — 23 теста/121 assertion; RecoveryLifecycleTest —
15 тестов/115 assertions. `make quality` текущего source завершился с exit 0.
Это ограниченные доказательства, не полная qualification или installed acceptance.

## Измерение и оставшаяся неопределённость

Последний малый прогон `20261114T063449.818292Z-1982630`: 12 документов,
36 receipts, окно authorization 37.852804 s (~0.95 запроса/s), корректностный тест зелёный.
Полезный ориентир исходного полного профиля — 3000 отправок/1800 s (~1.67/s).
Малый прогон не доказывает sustained throughput; нельзя выдавать его за полный load gate.
Предыдущие правки не подтвердили достижение требуемой скорости.

Дальнейшее исследование начинать с репрезентативности измерения и конкретного bottleneck.
Не повторять те же короткие замеры и не добавлять новые механизмы без проверяемой гипотезы.
Сохранённый фазовый profiler указывал на SQL/блокировки; временные instrumentation
и эксперименты с обходом Process lane locks удалены. Уменьшать защитные проверки нельзя.

## Критерии завершения новой задачи

1. Получить воспроизводимое измерение и исправить подтверждённую причину в owning source.
   Не ослаблять ownership, Process fence, health, TLS, budget, canonical lock order,
   DB clock, fixture isolation или provenance. I/O остаётся вне SQL transactions.
2. Квалифицировать финальный package source полностью, обновить JUnit/invariants/report;
   создать candidate/resolve штатным `scripts/package-check.py` и закрепить оба пакета
   одним source SHA через `scripts/agelto-pin.py`. Не менять vendor вручную.
3. После commit consumer обновить остановленные isolated runtime snapshots. Существующий
   diagnostic snapshot основан на consumer `365a93c`, а не финальном pin commit.
4. Выполнить исходную матрицу P10.4 V1–V17 на одном final source/runtime/input наборе.
   Она целиком сохранена в validation PLAN2:P1.1: quality, install, execution, network,
   concurrency, e2e, faults, load WIDTH=10 и WIDTH=20, platforms, acquisition, documents,
   ai, observability, regression, core-test-report и package-check --accepted.
5. Оба load gate сохраняют исходные **1800 секунд и 1000 документов** каждый.
   Не уменьшать corpus, duration, assertions, caps или пороги ради зелёного отчёта.
6. Collector должен подтвердить оригинальные native reports; затем штатный
   `scripts/agelto-acceptance.py` заменяет preparation record настоящим acceptance.
   Завершить независимым native read-only review. До этого 0.1 остаётся не принятой.

Использовать только изолированные `proxyma_test` и `agelto_core_test`/`agelto_test`.
Для Docker нужен `DOCKER_HOST=unix:///run/user/1000/docker.sock`.
Недоступность live-госзакупок не мешает fixture acceptance; live-доступ ею не проверяется.
Cached PHP image и прежние infrastructure receipts — не доказательство приёмки.
Не повторять rebuild/download без необходимости; не выдавать старую qualification за новую.
