# Agelto: transfer и drained cutover

Перенос выполняет Agelto, а пакет не знает о таблицах `prc_*`.
Исходная схема: `axiomasoft/agelto@f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`.
Новые migration links и transfer records добавляются вперёд. Исторические migrations,
request history, финансовый ledger, storage FK, adapter и handler holds сохраняются.

`Inventory(Connection, checksumKey)->dryRun()` не записывает данные. Возвращает counts,
schema revisions, unmapped integer proxy/target/profile IDs, orphans и undrained IDs.
Checksums — HMAC-SHA256 с отдельным стабильным приватным ключом минимум 32 bytes.
Отчёт не содержит host, username, secret reference или checksum key. Ключ передаётся
до SQL transaction; мигратор никогда не обращается к DNS, HTTP, secret/object store.

`TransferCatalog(CutoverJournal)->run(cutoverUuid, proxyEndpoints)` принимает явную карту
integer proxy ID → `{endpoint_id: UUID, protocol: http|https|socks5|socks5h}`.
Сопоставление по label/host запрещено. Route, target, profile UUID и credential versions
сохраняются, shared egress не разделяется. Crawler bindings и явные retry/redirect/delay
settings становятся package bindings. Несовместимые policy definitions одного slot
вызывают отказ, а не выбор случайной строки. Старые acquisition snapshots сохраняют
profile ID/version, включая stage overrides; история membership не выдумывается.

`TransferState(CutoverJournal)->run(cutoverUuid)` требует завершённого catalog шага и
отсутствия queued demands, reserved/sent permits, open operations, unfinished attempts
и requests. Expired lease сам по себе не доказывает отсутствие send. Сначала оператор
дренирует старых workers и разрешает unknown facts по отдельному cutover протоколу.

Переносятся `tat`, `send_tat`, cooldown, policy/health epochs, streaks и circuits. Все
legacy permit scopes сохраняются в immutable archive. Terminal state определяется
`authorized_at`/durable `sent_at` и finished request: completed, unknown after authorization,
cancelled/expired before send. Активные reservations не обнуляются: они блокируют перенос.
Исходный baseline не имеет отдельных fixed-window network quota tables. Уже существующие
package quota windows/reservations не переписываются; financial reservations остаются
в исходном ledger. Status capacity переносится в generic named class `status_refresh`:
фиксированное UTC-окно сохраняет использованную capacity, а старые rolling-hour sends
консервативно удерживают class до истечения прежнего часа. DB-время первого успешного
state transfer записывается в checkpoint. Превышение capacity блокирует перенос.
Проверка полного eligibility set обязательна перед переключением в следующей фазе.

Network observation window переносится в immutable `pxm_imported_health_samples` с
physical request ID, application key, epoch, signal, independence group и stale marker.
Package reducer объединяет окно с новыми observations, исключая stale/старые epochs.
Domain/adapter observations остаются в Agelto. `HistoricalRequests->page(after, limit)`
читает историю с сохранением request/demand/operation/parent-attempt correlation, максимум
100 строк. Он не выдаёт archived requests за новые package authorizations.

Каждый шаг атомарен и повторяем. Источник блокируется SHARE locks, selector — FOR UPDATE;
никакой внешней работы внутри transaction. Journal хранит source/target schema revisions,
generation и HMAC checkpoints. Повтор сравнивает импортированные поля, включая debts,
и отказывает при изменившемся source либо newer target. Он никогда не UPDATE-ит target.
Частичный catalog можно повторить после сбоя state. Изменившийся source требует явного
reconciliation; переиспользование UUID для молчаливого overwrite запрещено.

Transfer отдельно оставляет authority `legacy`, journal `draining`. Переключением владеет
Agelto `proxyma:cutover`. Ни live transfer, ни production switch в этой работе не выполняются.

## Drained cutover

Сначала установить новые forward migrations, package schema и adapters, сохраняя legacy
authority. На isolated fixture выполнить `inventory`, затем `run`. HMAC key должен быть
стабильным приватным файлом; JSON map содержит только явные proxy integer IDs, endpoint UUID
и protocol. Не передавать ключ или credentials через argv. Пример operator invocation из
Agelto root (UUID и generation берутся из конкретного journal/selector):

```sh
php artisan proxyma:cutover inventory "$CUTOVER_UUID" --key-file=/private/cutover.key
php artisan proxyma:cutover run "$CUTOVER_UUID" --generation=1 --wait-ms=30000 --key-file=/private/cutover.key --map=/private/endpoint-map.json
```

Команда сохраняет `prepared`, импортирует catalog, устанавливает persisted `paused`,
ожидает drain вне transaction по monotonic clock и выполняет финальный state transfer.
Перед `verified` она под source SHARE locks сравнивает свежие source schema/checksums,
все mappings, импортированные поля и полные route sets профилей. `legacy_frozen` закрывает
legacy network writes; SQL triggers также закрывают выдачу из старого worker binary.
Один commit меняет authority и generation; выдача остаётся paused до `resume`/`accepted`.
Перед продолжением выдачи оператор останавливает старые workers и запускает только binary,
который понимает новый selector generation. Команда не управляет внешним supervisor.

Resume после crash использует тот же UUID, mapping и key. До switch передать текущую старую
generation в `run`; после switch передать новую в `run` или `resume`. Повтор не импортирует
долги заново и не создаёт ещё одну generation. После pre-claim rollback старый UUID повторно
использовать нельзя. Stale UI и worker snapshots отклоняются в SQL transaction.

`--wait-ms` ограничен 0..60000; SQL lock wait ограничен 1 s, отдельный SQL statement — 10 s.
При timeout команда возвращает exit 1 и `drain_timeout_held`, оставляет selector paused и
записывает `held_unresolved_not_proven_unsent`. Queued demands, reserved/sent permits,
unfinished requests/attempts и open operations требуют явного завершения/reconciliation.
Истёкший lease не удаляет send fact, quota или финансовый hold. Команда их не освобождает.
Source drift, target drift, неполный mapping или eligibility mismatch также запрещают switch.
SQL lock conflict может потребовать повторного запуска; state не откатывается молча.

## Rollback boundary

```sh
php artisan proxyma:cutover rollback "$CUTOVER_UUID" --generation=2 --wait-ms=30000 --key-file=/private/cutover.key
```

Rollback сначала сохраняет pause. До первого нового durable claim он требует drain обоих
путей, точное равенство source checkpoint и полного target checksum, counters, epochs,
receipts и imported records. Возвращение к legacy меняет только selector на следующую
generation; quota, health и история не сбрасываются. Даже завершённая package preparation
с изменённым target state может запретить точный rollback.

Необратимая точка — commit первого package permit с `authorized_at`, включая crash до socket.
Consumer SQL trigger записывает `first_authorized_at` в том же transaction, что и permit/finance
claim; transaction rollback не оставляет marker. Marker нельзя стереть или подменить обычным
UPDATE/DELETE. После него rollback возвращает exit 1, `rollback_refused_held`, сохраняет
package authority, pause, counters, receipts, unknown и финансовые reservations. Обычный
`resume` для такого held journal запрещён: нужен проверенный roll-forward fix.
Уже committed transport capability могла начать send; pause не доказывает отсутствие bytes.

До работ оператор сохраняет приватный consistent DB snapshot и journal/checkpoint export
с checksums. Snapshot содержит чувствительные source facts и не публикуется в evidence.
Отдельно спроектированный reverse transfer потребует сохранения новых usages/epochs/receipts
и reconciliation unknown; такой transfer и post-claim code-only rollback здесь не реализованы.

`ProxymaCutoverTest.php` проверяет crash после каждой фазы, bounded drain, SQL fencing,
generation и freshness. `ProxymaRollbackTest.php` проверяет точное равенство до claim и
post-claim refusal до HTTP. Проверки запускаются только через guarded Agelto core rig;
результат и ограничения pinned source qualification записаны в `integration/agelto/cutover-evidence.json`.

Доказательства: `ProxymaDataTransferTest.php` проверяет empty/partial/rerun и отказы.
`ProxymaPopulatedUpgradeTest.php` выполняет все 78 pinned baseline migrations в guarded
rollback fixture `agelto_test`, заселяет старое состояние до новых migrations, затем
проверяет IDs, credential versions, debt, quota usage, holds, reducer window и FK/history.
Оригинальные executed-case provenance и SHA manifests не заменяются новыми именами.
# Связь с итоговой приёмкой

`core-test-install` по-прежнему доказывает только два clean resets. Итоговый native
report дополнительно требует actual regression JUnit для `ProxymaDataTransferTest`,
`ProxymaPopulatedUpgradeTest`, `ProxymaRollbackTest` и concurrent `ProxymaCutoverTest`.
Проверяются полные case inventories, включая отрицательные исходы, а не наличие файлов.
После первого нового durable authorization поддерживается только pause и roll-forward;
финальная qualification не добавляет reverse migration или post-claim rollback.
Все прогоны — isolated fixtures, без production DB и live traffic.
