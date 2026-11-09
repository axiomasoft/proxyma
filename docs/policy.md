# Настройки и patch команды

`SettingRegistry` содержит неизменяемые descriptors: key, type, default, nullable,
allowed scopes, merge mode, числовые hard bounds, permission, sensitive,
schema version и optional. Core предоставляет network timeout/retry/route/destination
настройки. Числовые значения и элементы integer lists проходят обязательную проверку
нижней и верхней границы. Identifier допускает только зарегистрированные значения;
identifier lists содержат canonical lowercase identifiers, не свободный текст.
Credential/secret/password/token keys не регистрируются; sensitive descriptor не
принимает значения. Зашифрованные payloads и объекты не являются policy settings.

Core descriptors ограничивают connect/read timeout диапазоном 1–120000 мс,
total timeout — 1–300000 мс, retry delay — 0–300000 мс, attempts — 1–10,
redirects — 0–10. Списки содержат не более 256 элементов. Реестр имеет
детерминированный fingerprint определений для будущего cache key.

Список `proxyma.settings.providers` содержит registry IDs, а
`proxyma.settings.approved_providers` — явно разрешённые IDs и PHP class strings,
реализующие `SettingDefinitionProvider`. Неизвестные IDs, неправильные классы,
повторы descriptors и closures/objects в config отвергаются. Реестр строится один
раз и не изменяется в работающем процессе. Регистрация — доверенный PHP приложения;
DB и patch не содержат PHP class names. Конфигурация совместима с `config:cache`.

`PolicyCommands::update(UpdatePolicyCommand)` принимает trusted tenant, head UUID,
scope и сериализованный документ версии 1:

```json
{"schema_version":1,"operations":{"timeout.connect_ms":{"op":"set","value":2000},"routes.denied":{"op":"set","value":[]},"retry.delays_ms":{"op":"disable"}}}
```

`set(null)` допустим только для nullable descriptor и отличается от пустого списка.
`disable` допустим только для optional descriptor. `inherit` удаляет локальный
override целиком; его отсутствие не подменяется default. Неизвестные поля, операции,
schema versions, duplicate JSON keys, неверные типы, scopes и bounds отвергаются до
SQL lock и до любой записи. Все операции одного patch проходят проверку вместе;
итоговая revision тоже ограничена 65536 байтами и проверяется до записи.

Команда требует authenticated actor, reason, expected revision и idempotency key.
Trusted context и `policy.update`, затем permission каждого descriptor проверяются
до transaction, в том числе при replay. Отдельная ability `policy.scope.<scope>.edit`
получает `PolicyScope::authorizationId(tenant, scopeId)` — стабильный UUID из hash
tenant, типа и идентификатора scope; adapter вычисляет его для доступных ему scopes.
Это позволяет проверить создание head до появления строки в БД. Global scope доступен только tenant `system`;
tenant scope использует собственный tenant key. Неизвестный авторизатор запрещает
операцию. Очередная revision неизменяема; policy head меняется атомарно вместе с
безопасным audit и receipt. Две команды с одной expected revision дают одну успешную
запись и `PolicyConflict`; exact replay возвращает исходную revision. Audit хранит
только имена полей и revisions, а actor ID, reason и idempotency key — как digests.
Ошибка audit откатывает head, revision и receipt. SQL errors скрыты за безопасным
`PolicyStorageFailure` без исходных bindings в цепочке исключений.

В P2.1 merge mode — descriptor metadata, а не готовый resolver. P2.2 реализует
иерархию и обязан пересекать live authority и hard bounds; запись `route.allow_direct`
сама по себе не разрешает direct execution. Tenant-specific scope, trusted actor и
current grant revisions продолжают проверяться независимо от значения patch.

`SettingPatchTest` проверяет null/empty/disable/inherit, unknown keys/operations,
тип, bounds, scoped permissions, duplicate JSON keys, ciphertext, rollback,
config cache и две независимые ожидающие transaction в marked `proxyma_test`.
