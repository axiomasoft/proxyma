# Agelto: callers и предел физических отправок

`integration/agelto/caller-map.json` перечисляет каждый outbound entry и его composition;
`caller-inventory.log` сохраняет конкретные rg callsites. Directory allowlist отсутствует.
Базовые revisions: Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`, Proxyma
`93fc7c9f184e51022e3d901e61131db59c4df021`. Финальные commits и проверки фиксирует координатор.

Persisted `prc_network_authorities` выбирает engine. Legacy остаётся default до drain.
При package generation общий конечный OutboundExecutor loop использует CallerAttemptGate:
package catalog, budgets, owner validation, sealed capability, transport и completion.
Перед legacy claim selector generation проверяется под SQL share lock; package claim проверяет
тот же selector и атомарно ставит irreversible marker journal. Ошибка package path не запускает legacy.
Каждый retry/redirect имеет отдельный permit; количество sends и distinct routes ограничивает
caller loop. Повтор URL блокируется. Cross-origin сохраняет только безопасные payload headers.

Adapter health, validation receipt, process work budget, terminal HTTP evidence и source TLS
остаются Agelto-owned. Package completion служит provenance для validator и diagnostic counters.
Package physical IDs сохраняются в validation details; nullable legacy request FK заполняется
только для legacy attempts. TLS adapter хеширует выбранный SourceTlsTrust CA bundle вне SQL,
чтобы sealed dispatch мог проверить его неизменность.
Adapter canary с закрытым network path использует diagnostic admission; при network hold получает
отдельный package probe token для physical attempt, сохраняя project adapter owner и его token.
Provider generation/file effects продолжают использовать joint financial gate из P9.3;
generic caller разрешает только объявленный read-only provider probe. Unowned OpenAI запрещён.

DownloadStorageRepository суммирует исторические legacy attempts и package authorization facts.
Package operation хранит process owner/generation и parent attempt, поэтому signed refresh и
дочерний canary расходуют тот же download ceiling. Reservation FK остаётся привязанным к
project outbound operation. Binary artifact передаётся в owned temporary storage вне SQL,
с проверенными length/hash и cleanup. Gzip artifact содержит decompressed bytes; wire counter
учитывает compressed bytes отдельно. Binary profile допускает существующий конечный предел
Agelto 256 MiB; default transport и text buffering сохраняют 64 MiB.

Telegram также перечислен явно. При настроенном bot token catalog sync объявляет notification
target до transfer. После переключения отдельный diagnostic owner отправляет один ambiguous POST;
неуспех оставляет alert без dedup-ключа до следующего тика. Credentials не попадают в catalog.
Перед cutover нужны target/profile mappings и policy binding для notification system subject.

Локальные fixtures: ProxymaCallerParityTest и DownloadSendAccountingTest в Agelto Network;
package BoundedResponseTest проверяет gzip bytes/hash и отрицательные resource bounds.
Executor запускает только целевой файл; V1–V4 и итоговая SHA-bound qualification принадлежат conductor.
