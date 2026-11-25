# Физическая отправка

`PhysicalTransport::sendOnce(AuthorizedWireRequest)` принимает только capability,
активированную после outer commit. `PhysicalHttpTransport` не читает catalog,
secret store или DNS во время отправки. Provider adapter готовит body и все headers
до `WireSealer::seal`; provider SDK и provider-specific headers в core отсутствуют.

До admission caller разрешает destination через `DestinationPolicy`, получает
origin TLS trust и отдельно proxy TLS trust. Для proxy caller разрешает endpoint
через `ProxyResolver::resolve` и передаёт `ProxyDestination` последним аргументом
`WireSealer::seal`. Endpoint ID, revision, credential reference/version, protocol,
hostname, port и выбранный IP входят в keyed seal. Proxy wire без этого descriptor
не допускается к физической отправке. Private endpoint — явно настроенный catalog;
это не исключение destination policy для origin.
Descriptor создаётся только resolver factory и не сериализуется. Перед claim
protocol/host/port сверяются с endpoint под lock: подмена полей с сохранением ID
и revision не даёт authorization. У endpoint без credentials nullable SQL version
отображается в domain version 1 как при чтении catalog, так и при lock-check.

Поддерживаются direct HTTP/HTTPS, HTTP proxy forwarding и CONNECT, HTTPS proxy
forwarding и CONNECT с независимой проверкой proxy TLS, SOCKS5 с локальным DNS.
Для HTTP forwarding абсолютный URI содержит проверенный IP, а Host сохраняет
исходное имя. CONNECT и SOCKS получают проверенный IP; origin Host/SNI и проверка
hostname сохраняются. HTTPS proxy соединяется с закреплённым proxy IP, сохраняя
proxy SNI. SOCKS5h запрещён. Ошибка proxy не переключает маршрут на direct.

`php artisan proxyma:transport-doctor --protocol=https` проверяет feature выбранного
cURL без сетевых вызовов. Catalog и `ProxyResolver` также отказывают HTTPS proxy,
если cURL не имеет `HTTPS_PROXY`. Успешный doctor означает наличие feature;
квалификация протокола требует целевых socket tests на используемом runtime.

У каждой отправки новый cURL handle, fresh connection, запрет reuse и один pinned
адрес. HTTP/2 выключен, чтобы libcurl не повторял stream автоматически.
Redirect, retry, netrc, cookies, environment proxy и environment NO_PROXY не
выбирают маршрут. Переданные headers неизменны; только Host и framing принадлежат
транспорту. Proxy credentials передаются отдельными username/password options,
поэтому разделители не превращаются в userinfo; proxy auth не попадает в origin.

Peer/host verification обязательны. Перед bind проверяется SHA-256 подготовленного
CA bundle. Отсутствующий или изменившийся bundle отклоняется до socket I/O.
Системные roots и per-host bundle поддерживаются отдельно для origin и proxy.
`pinnedCertificateSha256` пока отклоняется до отправки: это certificate hash,
его нельзя подменять cURL SPKI pin или проверять после отправки body.

После bind всех options transport проверяет transactionLevel/PDO, затем consume
проверяет single use и monotonic latestStart непосредственно перед dispatch.
Дополнительные request options у `sendOnce` отсутствуют. Полные connect/total
timeouts берутся из seal; idle read ограничивается monotonic ожиданием.
Control-only cancellation callback конструктора может только остановить попытку.

Физический результат — immutable `AttemptResult`: response status либо connect,
proxy connect/auth, TLS, timeout, truncation, cancellation, protocol/byte-limit
failure; безопасные timing/byte counters. HTTP 401/403/404/429/503 остаются
HTTP response, политика retry/health применяется следующим слоем.
Headers ограничены 64 KiB и allowlist (`content-type`, `content-length`,
`content-encoding`, `location`, `retry-after`). Encoded entity принимается chunks
с пределом 1 MiB по умолчанию (конструктор допускает 1 byte..64 MiB), без
декомпрессии и charset conversion. `RawResponseBody` передаётся следующему слою
декодирования; `decodedBytes=0`. Хранение owned artifacts и расширенная обработка
response относятся к следующему пункту. Completion сохраняет только hashes,
lengths, status и failure facts, без body, signed URL или credentials.

SQL authorization и доставка bytes не атомарны. Отказ после latestStart не
возвращает квоту. SIGKILL после authorization может оставить unknown как с нулём,
так и с уже полученным origin body. Отсутствие application receipt не доказывает
неотправку. Lease expiry освобождает capacity; поздний результат той же попытки
может завершить её, не освобождая capacity повторно и не возвращая quota.
После зависания OS нельзя обещать отсутствие старого socket после lease expiry.
Неидемпотентный unknown требует consumer/provider reconciliation.

`PhysicalTransportTest.php` проверяет матрицу протоколов, фактические request bytes,
CONNECT/SOCKS target, origin Host/SNI, proxy SNI, auth с разделителями, poisoned
environment, TLS failures, отсутствие redirect/retry и response failure facts.
`DispatchDeadlineTest.php` проверяет нулевые socket bytes при позднем старте,
transaction, изменённом body/CA и повторном consume, а также actual socket crash
checkpoints и поздний результат. Fixtures пишут redacted network events в
`build/p53-wire.jsonl`; это наблюдения локального сервера, а не mock receipts.

Источник сценариев: Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`,
`HttpTransportTest.php` (TLS CONNECT, single use, truncation, cancellation).
Hashes четырёх затронутых donor files сверены с существующим source manifest;
исторические файлы и manifest не изменены. Retry/redirect orchestration и
ResponseDecoder parity остаются в следующих пунктах P5.4/P5.5.
