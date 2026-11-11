# Agelto: финансовый и сетевой claim

`AnalysisProviderSendGate` выбирает persisted authority. До cutover действует
legacy runtime; выбранная generation повторно проверяется под SHARE после
process prefix. Ветка Proxyma никогда не возвращается к legacy при отказе.

`ProviderNetwork` проверяет provider/target mapping и разрешает binding слота
`agelto.provider/llm` с явно выбранным route profile. Agelto готовит Gemini headers, content type и body;
package получает только generic wire. DNS, proxy secret и SourceTlsTrust разрешаются
до seal и до SQL locks. Content-Length выводится transport из запечатанного body.
Provider key входит в keyed header digest; core не знает Gemini или LLM ledger.
Raw bounded response передаётся прежнему ReceiptStore без model parsing.

Admission создаёт bounded operation с physical call UUID и parent Process attempt.
Reserve сам по себе не разрешает HTTP. Финальный путь выполняет одну
`ProcessTransaction` и присоединяет scoped `TransactionCoordinator::locked` на
том же Connection/PDO: process prefix → selector/journal → finance → package
operation/catalog/health/adapter/budgets → final claim CAS. Финансовый отказ,
duplicate или сетевой отказ выходит исключением и откатывает всю транзакцию.
Ожидание send budget происходит только после rollback, с исходным monotonic bound.

`first_authorized_at` journal записывается той же транзакцией. Отказ откатывает
marker; commit сохраняет его даже при аварии до socket. P10 должен брать selector
до journal и запрещать автоматический rollback после этого marker.

Только successful outer commit позволяет активировать candidate. Подготовленный
transport принимает package capability, не добавляет provider headers после seal
и вызывает PhysicalTransport один раз. Latest-start повторно проверяется package
перед dispatch; поздний отказ сохраняет durable authorization без возврата quota.
Unknown POST не повторяется. ReceiptStore и settlement/acceptance под subject lock
остаются в прежнем финансовом пути.

Completion выбирается по durable physical correlation, без повторного owner prefix:
потеря generation после HTTP не уничтожает receipt. Новый runtime не пишет legacy
network attempts. Исторические сценарии ProviderJointSendTest сохранены; добавлены
реальные package claims, два worker, оба направления отказа, ожидание, header seal,
latest-start, lost generation и SIGKILL. Fixtures разрешены только в agelto_test.

Completion получает исходный package AttemptResult, включая ProxyAuth, partial
response head и metrics. Финансовое преобразование не заменяет эти сетевые факты:
CONNECT 407 удерживает отвергнутую credential version и сохраняет durable failure
observation; финансовый вызов остаётся unknown без provider receipt и без повтора.
Execution-specific callback вызывает throttled context tick перед dispatch и в
transfer loop. Отзыв generation обнаруживается на heartbeat; transfer прерывается,
авторизованный POST остаётся unknown, а зарезервированное финансовое обязательство
сохраняется. Отмена не доказывает отсутствие remote effect.

P9.4/P10 должны подключить active tenant/authorizer и provider policy bindings,
перенести catalog/health/budgets и завершить drain/cutover. Этот пункт не включает
production switch и не разрешает paid calls. Команды и фактические результаты
проверок хранятся в `integration/agelto/llm-changes.json`.
