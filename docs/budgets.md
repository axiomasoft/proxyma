# Rate, capacity и календарные квоты

Единица учёта — durable authorization одной physical HTTP attempt. Retry,
redirect, probe и diagnostics стоят по одной единице. Stored receipt не создаёт
новый расход. Origin и применимые shared-egress scopes остаются обязательными;
traffic class или рост route pool их не заменяют.

## Fixed UTC windows

Поддерживаются только `hour` и `day`. Identity окна: budget owner + canonical
budget + window kind + UTC start + стабильный quota policy key. Revision лимита
не входит в identity. `available = limit - reserved - spent`; `spent` хранит
consumed authorizations, не число успешных HTTP responses. Zero запрещает;
`null` означает отсутствие дополнительной optional quota. Никаких rolling
windows, локальных timezone/DST или per-proxy умножителей.

`BudgetConstraint` может добавлять `hourLimit`/`dayLimit`; intersection выбирает
меньший заданный лимит. Missing override не удаляет materialized DB constraint.
Все scopes проверяются до первого изменения accounting. Parent budgets берутся
в canonical order, затем bounded window subset, demand и permit. В той же
transaction reserve увеличивает reserved и создаёт immutable quota references;
send CAS проверяет каждое окно с новым `clock_timestamp()`, затем переводит
reserved→spent и ledger reserved→consumed ровно один раз.

Quota ledger отделён от inflight lease ledger, поэтому одна attempt одновременно
занимает hour/day и несколько scopes, но inflight увеличивается один раз на
budget. Before-send cancel освобождает quota reservations и leases однократно.
TAT/send_TAT никогда не отматываются. Unknown, поздний start refusal и отсутствие
response не возвращают consumed quota. Ledger identity и forward transitions
защищены DB constraints/triggers.

## Provisioning и configuration

`QuotaService::configure(tenant, budgetId, hour, day, expectedRevision, actor?)`
изменяет existing budget, сохраняет counters/debts и готовит current + next
UTC windows. Command авторизуется через trusted tenant context и
`catalog.budget.configure`; optional `CatalogMutationActor` задаёт reason и
idempotency key. Без него используется текущий trusted actor и deterministic
command identity. Mutation, revision, audit и receipt атомарны. Повтор exact
command не меняет accounting; другая команда с тем же key отвергается.
Понижение лимита ниже used/reserved сохраняет usage и запрещает новый расход.
Старые unused permits не grandfathered: budget revision перепроверяется.
При включении или повторном включении quota текущий период подхватывает durable
authorizations из lease history, включая расходы при отсутствовавшем лимите.
Ledger dedup добавляет только ещё не учтённые факты; смена optional настройки
не создаёт свежий остаток внутри того же окна.

Existing catalog provisioning также понимает quota fields в `BudgetConstraint`.
`QuotaService::provision(tenant, budgetIds)` — явная owner maintenance transaction
для следующего периода. Deploy/scheduler должен готовить окна заранее и после
длительного простоя. Bootstrap не выполняет SQL. Runtime reserve/claim никогда
не создаёт отсутствующие окна: `quota_materialization_missing` даёт hold.
Provisioning не стирает counters старых окон и не меняет policy identity.

## Rollover и composition

Permit expiry ограничена самым ранним концом всех окон; total timeout + cleanup
должны помещаться до этого конца. Если до send старое окно завершилось либо
оставшегося времени недостаточно, `NeedsReplan` освобождает старый unused permit,
quota reservations и inflight. Нужны новая physical attempt UUID и fresh demand;
старый immutable ticket не продлевается и не списывает сегодняшний send во
вчерашнее окно. Already authorized quota остаётся в исходном окне.

Для standalone claim cleanup завершается до внешнего catch. Для caller-owned
claim typed exception должна выйти из всего owning transaction: сначала rollback
finance/network, затем cleanup в новой transaction на той же Connection. Laravel
rollback event исполняет cleanup после teardown transaction manager; coordinator
`run` покрывает standalone driver без event dispatcher. При ручном caller driver
без events после outer rollback вызывается `flushRollbackCleanup()`; до этого
`NeedsReplan.released` остаётся false. Savepoint rollback не запускает cleanup под
живой caller transaction. Новый transaction здесь освобождает ресурсы и не mint
capability. Cleanup идёт от operation root без повторного owner prefix.

## Named traffic capacity share

Consumer задаёт serializable `proxyma.traffic_shares`, например
`['background' => 30]`. В core нет status/provider enum. `WireRequest.trafficClass`
должен совпадать с resolved `EffectivePolicy.trafficKey` и входит в fingerprint и
immutable demand identity.

`configureTrafficShare(tenant, originBudgetId, origin, class, expectedParentRevision,
actor?)` создаёт дополнительный mandatory class budget с hourly quota:
`floor(ceil(60_000_000 / origin_interval_us) * 60 * percent / 100)`.
Новый class budget подхватывает authorization history этого класса из origin.
Старые unused permits без нового обязательного scope не авторизуются. Удаление
class из config не снимает уже materialized ограничение: admission даёт hold.
При 1/min и 30% это 18/hour. Это сохраняет donor capacity formula; период по D5
фиксирован UTC, а не donor rolling hour. Все физические retries/redirects/probes
расходуют обычную authorization unit. Настраиваемый class применяется вместе с
origin rate/concurrency/hour/day и probe budgets. System и tenant ограничения
одновременны. Для каждого applicable origin owner нужен pre-materialized class
budget. Missing class materialization закрывает admission.

Rate/percentage reconfiguration не сбрасывает usage. Refresh производного лимита
идёт через тот же versioned provisioning command; runtime дополнительно clamp
по текущему parent rate и более узкому configured percent. FIFO использует полный
conflict set: quota-blocked predecessor считается ineligible для bounded skip.
Строгой глобальной FIFO между независимыми scopes нет.

Selector availability advisory: показывает exhausted/missing quota и не меняет
counters. Batch query count остаётся bounded для 1/10/100 routes. Grant всё равно
повторно проверяет полный набор под locks.

## Проверяемое поведение

`QuotaWindowTest` проверяет reserve/consume/cancel, hour+day, zero/null,
limit reduction, immutable identity, audit/idempotency, cooldown retention,
18/hour share, probe combination, origin quota при росте route pool, system+tenant,
UTC rollover и legacy upgrade без потери consumed history.
`QuotaBoundaryTest` запускает независимые PHP processes/PG connections с SQL
barriers: last token, competing classes, duplicate claim, rollover после locked
reads и lower-limit writer. SQL clock для границ подменяется только функцией в
изолированной test schema; production clock hooks или debug bypass отсутствуют.
Реальный HTTP в этом пункте не выполняется.

`AuthorityInterleavingTest` дополнительно проверяет limit writer против reserve,
send и reconcile в обоих порядках locks. Новый лимит может оказаться ниже уже
использованного остатка: это запрещает новый расход и сохраняет прежние
reserved/consumed ledger facts. Inflight должен совпадать с числом ещё не
освобождённых lease references; counters каждого quota window — с суммой его
reserved/consumed references. Проверка выполняется после каждого matrix run,
включая denied prerequisite и retry exhaustion.

PG retry допускается только до физического dispatch. Fault после authorization
или на deferred COMMIT откатывает finance, send debt, quota transfer и attempt
fact вместе. Повтор сохраняет тот же bounded request intent; только финальный
outer commit даёт single-use capability. Non-concurrency SQLSTATE не повторяется.
Worker/barrier evidence и проверенные source hashes находятся в
`build/p43-evidence.json`; исторические outputs не обозначают current suite green.
