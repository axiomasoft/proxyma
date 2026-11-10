# Выбор маршрута

`RouteSelector` принимает доверенный tenant, UUID профиля, `EffectivePolicy`,
`WireRequest` и `RouteSelectionContext`. Он возвращает `SelectionResult` с
выбранным route или причиной отказа, snapshot участников профиля, причинами
исключения и причинами сужения из retry preference. Выбор не создаёт permit.

`CandidateSnapshots` проверяет права на профиль и target, получает точные
membership rows и проверяет `catalog.route.select` для каждого участника. Затем
он пакетно загружает route/target health, `authorized_at` и применимые budgets.
При авторизационном отказе объект route не раскрывается. Неизвестный или
отозванный grant, выключенный route, отсутствующий или отозванный endpoint,
неизвестный protocol и незарегистрированная стратегия отклоняются. Пустой
профиль запрещён; direct требует membership и явного разрешения policy. Target
origin должен совпадать с URL запроса.

Profile, target, route и route-target health должны быть материализованы;
отсутствующее, unknown или открытое состояние удерживает выбор. Доступны profile,
target, route, endpoint, credential и grant revisions, health epochs/versions,
budget revisions и момент PostgreSQL `clock_timestamp()`. Результат содержит
только opaque credential version, не credential ref или secret.
В существующей схеме нет признака отключённого секрета: snapshot проверяет
revision endpoint и credential version, но не обращается к secret-store.
Статус секрета требует проверки в transport/admission integration.

Текущая схема `route_target_health` требует route и target одного tenant. Для
маршрута другого tenant с действующим shared grant пара с target consumer не
может быть материализована; такой кандидат удерживается из-за отсутствия health.
Это ограничение схемы не обходится разрешением маршрута без health evidence.

Budget scope включает общий origin, route-origin и egress-origin. Неизвестный
выход задаётся `pool:<provider-or-nat>`, общим для маршрутов пула. Применимые
tenant и system budgets учитываются одновременно; отсутствие обязательного
policy budget или исчерпанная активная quota исключает candidate. Временный
inflight/GCRA hold отражён в `budgetReady`: budget-aware стратегия предпочитает
готовые маршруты; когда их нет, она может вернуть ожидающий маршрут для admission.
Лимиты не резервируются selector-ом.

`uniform_random`, `least_recent_authorized`, `budget_aware_random` — конечные IDs
`StrategyRegistry`. RNG внедряется через `RandomIndex`; кандидаты сортируются по
UUID до обращения к RNG. Least-recent использует authorization из permits; при
равенстве или отсутствии timestamps выбирается меньший UUID. Country, legacy
scopes и independence group не предоставляют admission. Retry сохраняет текущий
route или предпочитает непробованный только внутри доступного profile и не
превышает лимит distinct routes. Route, возвращённый стратегией, повторно
пересекается с candidate set.

Snapshot advisory: состояние может измениться после чтения. Финальная authority
следующей фазы повторно проверяет membership, revisions, health и budget под
своими блокировками. Текущий тест с отдельным PostgreSQL соединением использует
fake guard и показывает отказ после отключения route или удаления membership;
он не доказывает работу ещё не реализованной authority. Batch loader делает
одинаковое ограниченное число SQL запросов для 1/10/100 маршрутов при in-memory
authorizer; реализация `Authorizer` отвечает за стоимость собственных проверок.
Проверка производительности считает SQL, а не делает вывод по latency.

## Provenance сценариев

Baseline Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`:
`tests/Feature/Crawler/ProxyResolverTest.php`, SHA-256
`0d568f651d52851b5273657477a430ebced0fb93889aa617e373344db81cb011`.
Исторические тесты о явной привязке и исключённом узле характеризованы в
`RouteSelectionTest.php` через точный membership; тест об открытом breaker — через
health hold; authorization order — через `SelectionQueryBudgetTest.php`.
Исторические названия country-сценариев не делают country admission policy:
исходный тест удаляет route из profile. Donor source hashes остаются в
`docs/reference/source-manifest.json`.
