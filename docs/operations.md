# Эксплуатация Proxyma 0.1

PostgreSQL остаётся обязательным authority. Команды используют настроенные
`TenantContextResolver` и `Authorizer`: tenant/actor не берутся из CLI-флагов.
Для нескольких tenants владелец приложения запускает отдельные bounded задачи
в доверенном контексте каждого tenant. Пакет не устанавливает production cron.

## Диагностика без отправки

```shell
php artisan proxyma:doctor
php artisan proxyma:transport-doctor
php artisan proxyma:diagnose PROFILE_UUID TARGET_UUID --subject-type=document --subject-id=opaque-id
php artisan proxyma:explain PROFILE_UUID TARGET_UUID --subject-type=document --subject-id=opaque-id
php artisan proxyma:metrics --limit=32
```

`doctor` проверяет наличие таблиц, ключевых constraints/triggers, mandatory safety,
валидность queue/health/maintenance configuration, наличие HMAC key достаточной
длины и локальные cURL capabilities. Exit 0 означает локальную готовность;
exit 1 — проблему. Это проверка структуры, а не доказательство идентичности
всего DDL или квалификация provider. Секретные значения, DSN и CA paths не выводятся.
Команды doctor/diagnose/explain/metrics не обращаются к HTTP, DNS или secret-store.

`explain` — alias `diagnose`. Команда объединяет policy explain и candidate snapshot
с прежними tenant/profile/target/route permissions. Она не резервирует permit,
не меняет counters, last-authorized, timestamps или audit и не отправляет запрос.
Ответ содержит `advisory`, `as_of` по DB clock, policy hash/revision, profile/target
revisions, health epochs/versions, exclusions и budget readiness. Только встроенные
числовые/boolean policy поля имеют схему вывода; custom values и origins не выводятся.
`--traffic` выбирает policy traffic key; это не выдача authority.

`next_eligible_at` — advisory оценка по текущим GCRA/cooldown bounds;
`null` означает отсутствие доказуемого времени (например, health/inflight hold).
Это несколько read-only запросов, а не атомарный locked snapshot. Результат может
устареть сразу после чтения: фактический send всегда заново проверяет authority
под canonical locks. CLI передаёт один subject; приложение должно пользоваться
`AdmissionDiagnosis::explain()` с полным `SubjectChain`, когда есть ancestors.

## Reconcile и retention

```shell
php artisan proxyma:reconcile --limit=32
php artisan proxyma:prune --limit=32 --retention=2592000
```

Один вызов обрабатывает максимум 1..64 operation roots. Некорректные bounds
возвращают exit 2. Нет бесконечного drain-loop. Reconcile возвращает scanned,
locked, changed, cursor и busy. Durable cursor/60-second lease переживает новый
процесс; после crash lease истекает по времени БД. Root IDs читаются без блокировки,
затем root `FOR UPDATE SKIP LOCKED` предшествует policy/profile/route/health,
budget/window, demand/permit/attempt subset. Два reaper-а освобождают ресурс один
раз. После конца прохода cursor обнуляется; пропущенный locked root вернётся
в следующем проходе. Recovery не выполняет HTTP и не повторяет physical request.

После authorization expiry состояние становится unknown: consumed quota остаётся,
inflight/reserved resources освобождаются один раз. Поздний receipt может завершить
unknown без повторного release. Lease inflight **не является числом физических
сокетов**: освобождение lease не доказывает остановку удалённого запроса.

Prune возвращает `removed` — число удалённых permits + demands, а не roots.
Retention измеряется секундами по DB clock (по умолчанию 30 дней).
Удаляются только terminal preauthorization permits/demands с истёкшей retention,
после повторной проверки под тем же canonical subset. Сохраняются active roots,
неосвобождённые leases, unknown, все authorized attempts и их permits, operation
identity/financial associations, temporary artifacts, active quota windows и
их links, а также links окон до истечения retention после `ends_at`.
Удаление FK-safe и идемпотентно: crash откатывает текущий root, новый вызов продолжает
по оставшимся rows. Внутренний предел recovery — 256 demands/permits на root;
превышение даёт отказ, а не усечение.

В 0.1 facts, observation applications, health projections/holds, quota windows,
audit и command receipts сохраняются консервативно. В частности, история reducer
windows и исходных scopes для позднего receipt не удаляется. Это означает рост
storage для authorized traffic; `prune` не обещает полный GC всех таблиц.
Нельзя обрезать их SQL DELETE/TRUNCATE по возрасту в обход этих правил.

## Явное расписание и probe

В конфигурации до boot/resolution scheduler:

```php
'scheduler' => ['enabled' => true],
'maintenance' => ['batch_size' => 32, 'retention_seconds' => 2592000],
```

По умолчанию `enabled=false`, schedule пуст. Opt-in добавляет reconcile каждую
минуту и prune ежедневно с `withoutOverlapping()`, конечными bounds и доверенным
console tenant context. Включение расписания не регистрирует outbound probes.

```shell
php artisan proxyma:probe PROFILE_UUID ROUTE_UUID TARGET_UUID --seconds=30
php artisan proxyma:health-reset route_health HEALTH_ROW_UUID \
  --reason='Проверено оператором' --expected-revision=4 --idempotency-key=reset-2026-11-10
```

Probe ограничен одним HEAD `/` зарегистрированного target, одним точным profile
member, purpose `probe`, 16..60 секундами и одним physical send без redirect/retry.
Требует `health.probe.execute`, `health.probe.claim`, обычные catalog/operation/
admission permissions и обычные origin/route/egress + явный probe budget.
Claim закрепляет sorted exact scopes/epochs/token/lease; permanent hold не
считается due probe. Transport проходит обычный seal/reserve/claim/activate.
Нет произвольного URL, headers, body или обхода admission. Неудачный/истёкший
probe подчиняется обычному backoff; timeout сохраняет charged unknown.
Exit 0 означает успешное завершение, exit 1 — отказ/defer/unknown, exit 2 — input.

Reset требует trusted actor, permission `health.reset`, непустой reason,
expected state revision и idempotency key. Он переводит row в `unknown`,
увеличивает epoch/revision и сохраняет audit без свободного reason/secret material.
Одинаковый replay возвращает прежний результат; другой command с тем же key или
устаревшая revision возвращают conflict. Reset не является успешным probe.
Другие mutations (hold/rotate/update) выполняются существующими audited APIs;
их actor/permission/revision contracts сохраняются.

## Метрики и redacted logs

Tier 0: библиотека не устанавливает exporter, alert service, внешние SLO или cron.
`OperationsMetrics::snapshot()`/`proxyma:metrics` возвращает конечную выборку:
oldest pending queue age, max admission wait и counts claim/completed/unknown/
lease inflight в последних `limit` permits, reconcile lag по oldest due lease.
Это sampled gauges, не lifetime counters и не полные totals tenant. Sample limit
явно присутствует в ответе; tenant/profile/URL labels отсутствуют.

`OperationsTelemetry` пишет structured PSR-3 события `proxyma.operations` и
`proxyma.metric`. Outbound executor публикует `proxyma_blocked` при blocked/deferred
результате с конечными reason labels `health|quota|budget|configuration|permission|
other` (`none` для gauges). Raw reason/URL/tenant не становятся labels.
Корреляция передаётся UUID в отдельном log event. Произвольный context, exception
message/trace, credentials, headers и body отбрасываются по схеме. Ошибка exporter
не меняет принятое execution решение. CLI ошибки также имеют фиксированную схему
без текста исключений.

При растущем reconcile lag: проверить opt-in расписание, trusted tenant context,
permissions, `doctor`, затем вызвать одну bounded reconcile page. При unknown:
сопоставить receipt/correlation через доверенный API; expiry не разрешает blind retry.
При health hold: выяснить причину, использовать rotation или audited reset с
актуальной revision и затем обычный probe. Полные race/transport checks проводит
изолированный rig; live/paid вызовы для этой проверки не нужны.
