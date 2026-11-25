# Оркестрация исполнения, повторов и редиректов

`OutboundExecutor` реализует `OutboundClient::execute(OutboundRequest, ExecutionContext)`
и координирует полный цикл `prepare → select → reserve → claim → dispatch → complete`
вне активной SQL-транзакции.

## Логическая операция и физическая попытка

Один вызов `OutboundExecutor::execute` выполняется в рамках единой логической операции
(`operation_id`), но каждая физическая попытка (первичная отправка, повтор или шаг редиректа)
получает:
- собственный `physical_attempt_id`;
- повторное разрешение актуальной `EffectivePolicy`, `DestinationPolicy` и `RouteSelector`;
- новое резервирование и авторизацию (`ScheduledPermit` → `ClaimCandidate` → `AuthorizedWireRequest`);
- заново запечатанный неизменяемый `SealedWire` (`WireSealer::seal`).

Перед локальным DNS и перед созданием новой заявки в очереди допуска проверяются все
ограничения цикла: `maxAttempts`, `maxDistinctRoutes`, `maxRedirects`, `sendLimit`,
воспроизводимость тела (`WireBody::isReplayable`), а также остаток `remainingSlice`,
монотонный дедлайн (`monotonicDeadlineNs`) и `operation.deadlineAt` с учётом
`totalTimeoutMs + cleanupMarginMs` и расчётной паузы перед повтором. Если окно времени
не вмещает следующую попытку, возвращается `ExecutionDeferred(DeferralReason::SliceExhausted)`
без сна, без DNS-запроса и без захвата нового permit.

`maxAttempts` ограничивает все физические отправки логической операции, включая
редиректы. Счётчик не сбрасывается при переходе на следующий URL или повторном
`execute` с тем же `operation_id`; durable CAS дополнительно проверяет лимит operation.
Срез времени уменьшается по монотонному clock на протяжении всего вызова.
Разрешение direct берётся из текущей политики даже для профиля с единственным direct.
Для разрешённых redirect origins бюджеты должны быть подготовлены заранее:
executor не создаёт отсутствующие строки допуска во время отправки.

## Техническая классификация и решения вызывающей стороны

Классификация результата физической попытки разделена на два независимых слоя:
1. `TechnicalOutcomeClassifier` оценивает неизменяемые `AttemptFacts` и формирует
   `TechnicalClassification`: атрибуцию здоровья (`HealthAttribution`), обязательные флаги
   безопасности (`holdsProxyCredential`, `extendsOriginCooldown`, `appliesWafHold`,
   `isAmbiguousAfterAuthorization`) и решение по умолчанию (`OutcomeDecision`).
2. `OutcomePolicyRegistry` разрешает зарегистрированную политику (`OutcomePolicy`) по
   иерархии приоритетов `SecurityOwnership > Project > Panel > Default`. Конфликт двух
   политик одного приоритета для одного ключа отвергается при `freeze()` и `resolve()`.

Обязательные инварианты безопасности не могут быть ослаблены пользовательской `OutcomePolicy`:
- **404 / 401**: доставленные HTTP-ответы (`OutcomeDecision::Accept`, `HealthAttribution::RouteSuccess`);
  не штрафуют здоровье маршрута и не вызывают транспортный повтор.
- **407 Proxy Authentication Required**: классифицируется как `HealthAttribution::CredentialFailure`,
  переводит `pxm_route_health` прокси-маршрута в состояние `open` с причиной
  `credential_hold:<credential_version>`, запрещает повтор на том же прокси с той же версией
  учётных данных и переключает попытку на другой прокси-маршрут профиля (или возвращает
  `ExecutionBlocked(BlockedReason::CredentialHold)`, если альтернативных прокси нет).
  Неявный откат на `direct`-маршрут при отказе прокси запрещён.
- **429 Too Many Requests**: классифицируется как `HealthAttribution::TargetFailure`,
  возвращает `ExecutionDeferred(DeferralReason::RateLimit)` и никогда не ротирует маршруты
  в том же всплеске. `RetryAfterParser` разбирает секунды и HTTP-date с верхним пределом
  (по умолчанию 86 400 с) и безопасным резервным значением (60 с) при некорректном заголовке;
  окно охлаждения продлевает отдельный `cooldown_until` бюджета origin и `open_until`
  исходной цели в `pxm_target_health`. Burst и ротация маршрутов не уменьшают cooldown;
  stale epoch запрещает reducer, но не консервативное продление исходного origin.
- **403 Forbidden**: по умолчанию доставляется вызывающей стороне без открытия circuit breaker;
  для ключей политики с opt-in WAF-защитой записывает `waf_rate_signal` на паре
  `pxm_route_target_health` (`HealthAttribution::RouteTargetFailure`) без отравления глобального
  состояния маршрута.
- **502 / 503 / 504 и транспортные сбои идемпотентных запросов**: для `ReadOnly`,
  `IdempotentWrite` с воспроизводимым телом допускают повтор по правилам
  `RetrySameRoute` / `RetryDifferentRoute`; при нескольких валидированных A/AAAA-адресах
  повтор после ошибки соединения/таймаута/обрыва переключается на следующий проверенный IP.
- **TLS-ошибки**: классифицируются как `HealthAttribution::RouteTargetFailure` и немедленно
  блокируются (`BlockedReason::ConfigurationHold`) без повторов.
- **Неоднозначная запись (`IdempotencyClass::AmbiguousWrite`)**: при обрыве соединения,
  таймауте или усечённом ответе после авторизации попытка остаётся в состоянии
  `unknown_after_authorization`, повтор не выполняется и возвращается
  `ExecutionUnknownAfterAuthorization` для последующей сверки (reconciliation).

## Политика редиректов

Каждый шаг редиректа (`301`, `302`, `303`, `307`, `308`) проходит полную проверку:
- превышение `maxRedirects`, цикл URL (по каноническому представлению `SafeUrl`),
  понижение схемы `HTTPS → HTTP`, пустой или некорректный `Location`, а также редирект
  на приватный/loopback/link-local/metadata IP или неразрешённый внешний origin
  отклоняются с `ExecutionBlocked(BlockedReason::UrlPolicyDenied)` до выдачи нового permit;
- `303 See Other` переводит метод в `GET` (сохраняет `HEAD`), очищает тело и заголовки содержимого
  (`Content-Type`, `Content-Length`, `Content-Encoding`, `Content-Language`,
  `Content-Location`, `Digest`) и переводит класс идемпотентности в `ReadOnly`;
- `307` и `308` сохраняют метод и тело только тогда, когда запрос не является
  `AmbiguousWrite`, а тело отсутствует либо воспроизводимо (`WireBody::isReplayable()`);
  невоспроизводимый поток (`WireBody::fromNonReplayableStream`) блокируется с
  `BlockedReason::PolicyReview`;
- `301` и `302` разрешены только для `GET`/`HEAD` без тела;
- при смене origin (`scheme`, `host` или `port`) `PreparedHeaders::acrossOrigin` удаляет
  чувствительные заголовки (`Authorization`, `Proxy-Authorization`, `Cookie`, `X-Api-Key`,
  `Api-Key`, `X-Auth-Token`), сохраняя безопасные заголовки и `Content-Type` (если сохраняется тело).

Заголовки хранятся в нижнем регистре. `Content-Length` и `Proxy-Authorization`
принадлежат транспорту и отвергаются уже при создании `PreparedHeaders`; они не
могут быть внесены вызывающим кодом. Cross-origin allowlist сохраняет только
`accept`, `user-agent` и, при сохранении тела, `content-type`.

Callback policy получает реальные `AttemptFacts` и техническую классификацию.
Если callback бросает исключение, технический outcome всё равно фиксируется,
включая credential hold или origin cooldown; caller получает `PolicyReview`.
Неизвестный ключ политики блокирует исполнение до DNS и допуска.

Миграция `2026_11_10_000011_add_origin_cooldown.php` добавляет независимый cooldown
к существующим бюджетам. Установленный consumer должен применить её до использования
executor. Authorization snapshot сохраняет origin, egress, credential version,
profile/policy revision и ключ интерпретации outcome для последующего health reducer.

## Явный target для каждого physical operation

Consumer с отдельными mapped targets для разных origin использует
`FiniteOutboundExecutor::execute(OutboundRequest, FiniteExecutionPort)`.
Port выполняет ровно одну попытку через native `OutboundExecutor::executeAttempt`:
создаёт immutable operation для текущего target, передаёт текущие subjects/owner и возвращает
`PhysicalExecutionResult` после admission, dispatch и completion. Следующая попытка получает
новый operation ID; общий Process parent correlation сохраняется consumer adapter.

Port явно разрешает target следующего redirect. Нет mapping, downgrade, cycle или тело запроса —
новой отправки нет. Этот путь следует redirects только для bodyless GET/HEAD и удаляет
чувствительные headers при смене origin. Текущие effective ceilings ограничивают общую цепочку;
resolved retry delays сохраняют приоритет policy, включая отключение caller jitter.
`canStart` проверяет локальный monotonic slice перед send/ожиданием, `now` возвращает SQL authority
clock для persisted retry time. HTTP/DNS/secrets и artifact I/O выполняются вне SQL transaction.

Порт не меняет target существующей operation и не ослабляет claim/owner/schema invariants.
Обычный `OutboundClient::execute` сохраняет существующий контракт одной логической operation.
