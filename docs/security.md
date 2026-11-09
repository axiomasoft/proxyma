# Tenant isolation, permissions и audit

Граница доверия — application ports `CatalogCommands`, `CatalogQueries`,
`SharedRouteCommands`, `RouteAccessChecks`. Скрытая кнопка, UUID, Eloquent scope
или созданный вручную domain object не подтверждают доступ. Core не импортирует
пользователей приложения или AzGuard. SQL connection и internal storage доступны
доверенному коду приложения; DB credentials не выдаются внешним tenant clients.
Composite FK защищают ownership при raw SQL, но не заменяют database credentials
или permissions приложения. PostgreSQL RLS здесь не заявляется.

## Trusted context

Приложение связывает `TenantContextResolver` с доверенным источником authenticated
request/job identity и `Authorizer` со своей permission системой. Resolver возвращает
`TenantContext(TenantKey, ActorRef)` без аргументов из команды или request body.
HTTP adapter проверяет tenant membership до создания context; worker устанавливает
контекст каждого задания и очищает его после задания. CLI использует явную identity
оператора. `default_tenant=system` — имя tenant, а не fallback authority.
Без настроенного resolver или authorizer вызов получает `AuthorizationDenied`.

Каждый вызов, включая replay, заново получает context и permission adapter через
container. Services не удерживают scoped instances между заданиями. Tenant команды
или query обязан совпадать с context, actor команды — с authenticated actor.
Внешние identifiers вроде `process/42` различны в разных tenants; subject parents
не пересекают tenant. Отказ при подмене происходит до SQL и не раскрывает наличие
чужого UUID. Чтение чужого UUID в собственном scope даёт null/пустой список.

Mutations используют abilities `catalog.endpoint.upsert/retire`,
`catalog.route.upsert/set-enabled/share/revoke-share`, `catalog.profile.upsert`,
`catalog.target.upsert/retire`. Grant create проверяет grant UUID и route UUID;
grant revoke проверяет grant UUID в owner scope. Reads используют
`catalog.endpoint.read`, `catalog.route.read`, `catalog.profile.read`,
`catalog.target.read`; enumeration — `catalog.profile.select` и
`catalog.route.select` для каждого candidate. Profile mutation требует route select
для каждой ссылки, proxy route mutation — endpoint read. Authorizer видит tenant
потребителя при shared read/select, а grant administration — tenant владельца.

## Explicit sharing и подготовленные данные

`shared_grant` в старом route DTO/столбце сохранён для совместимости и не выдаёт прав.
`SharedRouteCommands::shareRoute` создаёт grant UUID с owner, consumer, route и revision.
Owners и consumers различны; право выдаётся ровно одному consumer. Consumers не
читают endpoint/credential reference владельца и не меняют его route или grant.
Shared route по-прежнему требует точного profile membership; direct требует
явного разрешения policy. Empty profile запрещает выбор.

Membership хранит route owner и точный grant UUID. Composite FK связывают owner,
consumer, route и grant; SQL trigger отвергает revoked grant при добавлении ссылки.
Revocation повышает revision, сохраняет историю и повышает revision всех зависимых
subject bindings только consumer tenant. Отозванный grant не возрождается. Новый
grant получает новый UUID и требует повторной явной записи profile membership;
старые profiles не оживают автоматически. Audit failure откатывает и эти изменения.

Прочитанный `Route.accessGrant` — snapshot, а не вечное разрешение. Перед использованием
prepared route приложение вызывает `RouteAccessChecks::assertCurrent` в owning SQL
transaction. Проверка берёт shared locks endpoint → route → grant и сверяет route,
endpoint, credentials и grant identity/revision с текущими данными. Locks удерживаются
до завершения transaction, поэтому revoke/rotation не проходят между проверкой и
SQL claim. Вне transaction проверка отклоняется. Domain `allowsRoute` — чистая проверка
snapshot; она сама не обращается в authority. Эти правила обязательны для будущих
policy/admission services P2/P3; данный пункт не добавляет HTTP executor.

Permission adapters и context resolvers при locked validation выполняют только
локальные проверки; HTTP, DNS, secret-store и object I/O запрещены в SQL transaction.
Catalog administration владеет отдельной transaction; nested вызовы запрещены.
Долговечные timestamps задаёт `clock_timestamp()`, не время PHP worker.

## Audit и replay

Mutation требует actor, непустой reason, expected revision и idempotency key.
Stale revision конфликтует без частичной записи. Audit, catalog, membership,
provisioning и command receipt коммитятся одной transaction. Audit хранит entity
type из закрытого списка, UUID, ability, previous/current revision и только
`before_fields`/`after_fields` patch — без значений, credentials, FQCN и целых моделей.
Before fields пусты для create; для update имена отражают затронутые поля.
Membership и budgets отмечаются именами полей без содержимого.

Свободные actor id, reason и idempotency key сохраняются в журнале как SHA-256,
чтобы пользовательский текст не попадал в export/log. Actor type остаётся canonical
identifier; оператор сопоставляет известный actor/reason вычислением того же digest.
Digest не шифрование и не защита от перебора короткого известного текста.
Нельзя использовать reason или actor id для передачи секретов.

Отдельная внутренняя таблица `pxm_catalog_command_receipts` хранит allowlisted DTO
projection для исходного replay результата, включая opaque credential reference,
но без разрешения secret. Она не является audit UI/export API. Core не принимает
plaintext credentials в catalog командах. Exact replay повторно проверяет context
и permissions, затем возвращает исходную revision; иной payload с тем же key
конфликтует. Grant replay не восстанавливает отозванное право: access всегда сверяется
с текущим grant. Audit и receipts запрещают UPDATE/DELETE SQL triggers.

Разрешённые reads/selects записывают безопасное access event с текущей revision
(0 для отсутствующего объекта). Отказ audit не возвращает прочитанные данные.
Неподтверждённый context/permission отклоняется до SQL без попытки писать от имени
недоверенного tenant. Denial events в perimeter logger должны содержать только
безопасные коды; исключение нельзя логировать вместе с request body.

`CatalogStorageFailure` скрывает SQL bindings и не связывает исходный QueryException
через previous. `AuthorizationDenied`, конфликты и validation exceptions используют
фиксированные безопасные сообщения; пользовательские Authorizer adapters отвечают
за собственные исключения. Ошибка административного audit откатывает administration.
Ошибка read-only observer/audit после уже отправленного HTTP не отменяет transport
fact и не разрешает повторную отправку; future completion сохраняет этот факт отдельно.

Hard delete endpoint/route/target запрещён SQL trigger даже до первого использования;
используются retirement/disable, ссылки и receipts сохраняются. Grants отзываются,
не удаляются. Retired identities не возрождаются. Миграция P1.4 обновляет установленную
P1.3 схему, переносит прежние snapshots в receipts и редактирует старый audit до
имён полей без значений. Down нужен только для isolated reinstall, не для возврата
production к старому приложению: старые свободные audit metadata уже заменены digest.

## Source-to-test mapping

| Invariant | Repository scenario |
|:---|:---|
| Подмена tenant/actor, отсутствующие permissions и default deny | `TenantAuthorizationTest`: query/mutation boundaries, zero SQL, unconfigured adapters |
| Worker reuse, одинаковые external subject IDs | `TenantAuthorizationTest`: retained commands/queries, два tenants и смена actor/permissions |
| Explicit grants, revoke/regrant, stale preparation | `TenantAuthorizationTest`: exact consumer, binding revisions, prepared route, credential rotation |
| Raw SQL ownership и неизменяемая история | `TenantAuthorizationTest`: composite FK/trigger negatives; `CatalogSchemaTest` |
| Safe patch, stable replay, rollback и upgrade | `AdminAuditTest`: secret sentinels, failed journal, grant/binding rollback, P1.3 populated upgrade |

Тесты используют только marked `proxyma_test` и отдельную schema для каждого случая.
Внешний HTTP/DNS, live crawling и production/dev DB для этих сценариев не используются.
