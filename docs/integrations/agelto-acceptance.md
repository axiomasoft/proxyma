# Финальная приёмка внутренней 0.1.0

Accepted обозначает source SHA двух пакетов и commit Agelto, проверенные на isolated
fixtures. Это не production run, публичный release, tag или разрешение rollout.
`integration/agelto/acceptance.json` до полного сбора содержит `pending_checks` и
наблюдавшиеся revisions; отсутствие digests в pending-record не заменяется placeholder.

## Подготовка coordinator

Сначала coordinator фиксирует финальный Proxyma source. Любое изменение `packages/`,
`tests/`, `scripts/`, `docker/` или root runtime inputs меняет qualification fingerprint.
Старый P8.2 report нельзя переиспользовать по версии или возрасту. Его принимает только
collector при совпадении source/runtime/input digests. Текущая интеграция изменила source
после P8.2: нужна полная package qualification на финальном source, затем `make test-report`.
Executor не запускает эти suites.

```sh
PROXYMA_SOURCE_SHA=$(git rev-parse HEAD)
python3 -B scripts/package-check.py candidate \
  --qualification build/test-report.json \
  --qualification-source-commit "$PROXYMA_SOURCE_SHA" \
  --output integration/agelto/candidate.json
python3 -B scripts/package-check.py resolve \
  --source-commit "$PROXYMA_SOURCE_SHA" --output /tmp/proxyma-pinned.json
python3 -B scripts/agelto-pin.py \
  --consumer-root "$PROXYMA_AGELTO_ROOT" --source-commit "$PROXYMA_SOURCE_SHA"
```

Re-pin использует только локальный Git source и offline Composer, не патчит vendor.
Перед checkout он проверяет все vendors на origin/SHA/dirty state. Composer обновляет
только две объявленные зависимости и переустанавливает их копии; при отсутствующем
archive cache команда отказывает. Обновляются lock и sanitized runtime-inputs, Azguard
остаётся на прежнем pin. При прерывании сначала проверить сообщение и локальное состояние;
не сбрасывать dirty checkout. Повтор на уже согласованных inputs допустим.

Coordinator фиксирует изменения Agelto, сохраняя исходный baseline. Только после этого
обновляет snapshots через остановленный isolated rig (`core-test-down`, затем штатный
refresh `docker/tests/process-core/runtime-inputs` и `core-test-up` из consumer runbook).
Не удалять snapshots под работающими workers. Это disposable test volumes, не dev DB.
`core-test-env` проверяет isolation до mutations. Launcher принимает explicit final
`--expected-head` и проверяет ancestry исторических integration manifests.

## Один финальный consumer checkpoint

Кондуктор выполняет validation, включая quality, install, e2e, faults, WIDTH=10,
WIDTH=20 и regression. Load-профили раздельные: каждый 1800 seconds, 1000 documents,
local fixtures. Нужно дождаться обоих завершений; сокращённый профиль не принимается.
Не запускать suite повторно при неизменных inputs. При source/runtime fix обновить pin,
lock, manifests и snapshots, признать затронутые старые receipts недействительными.

`core-test-report` не выполняет тесты. Он требует все gates и actual case inventories,
включая populated upgrade/rollback. Каждый gate сохраняет image/config/dependency identity
и hashes runtime vendor files. Это связывает проверки с установленным source, а не с
непроверенной копией в соседнем checkout. Named historical replay обязан присутствовать
в actual regression JUnit. Архивные hashes и removal allowlist остаются проверяемыми.

После завершения кондуктор сохраняет путь `summary.json`, выданный `core-test-report`:

```sh
python3 -B scripts/agelto-acceptance.py --descriptor /tmp/proxyma-pinned.json \
  --consumer-root "$PROXYMA_AGELTO_ROOT" --report "$AGELTO_SUMMARY_PATH" \
  --output integration/agelto/acceptance.json
python3 -B scripts/package-check.py --accepted integration/agelto/acceptance.json \
  --consumer-root "$PROXYMA_AGELTO_ROOT"
```

Collector отказывает для dirty Agelto, неполных gates, другого source/runtime,
symlink vendor, изменённых artifacts, оставшегося generic runtime или stale package
qualification. Он пересобирает native summary, сверяет vendor lock, Composer
lock/installed references, copied files и package payloads. Original reports не переписываются.

После финального consumer commit повторно выполнить только `core-test-report` и collector,
если inputs не изменились. Gate receipts могут ссылаться на ancestor HEAD: collector
проверяет их input changes и source/runtime identity; итоговый summary должен ссылаться
на финальный consumer commit. Acceptance artifact лежит вне source fingerprint, поэтому
его commit в Proxyma не требует повторных тестов. Оба коммита создаёт coordinator.
