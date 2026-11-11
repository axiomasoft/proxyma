# Agelto: forward transfer

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

Эта фаза оставляет authority `legacy`, journal `draining`. Следующая фаза должна
проверить свежие source checksums и policy coverage, persisted pause/generation fence,
then verified→switched→accepted. Ни live transfer, ни authority switch здесь не запускаются.
До первой durable новой authorization допустим проверенный rollback после drain;
после неё — pause/hold и roll-forward. Reverse-state migration не реализована.

Доказательства: `ProxymaDataTransferTest.php` проверяет empty/partial/rerun и отказы.
`ProxymaPopulatedUpgradeTest.php` выполняет все 78 pinned baseline migrations в guarded
rollback fixture `agelto_test`, заселяет старое состояние до новых migrations, затем
проверяет IDs, credential versions, debt, quota usage, holds, reducer window и FK/history.
Оригинальные executed-case provenance и SHA manifests не заменяются новыми именами.
