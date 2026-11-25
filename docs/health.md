# Health и receipt completion

Три независимых состояния — route health, route-target health и target
availability — получают только технические факты транспорта. Ошибки parser,
adapter и принятие результата владельцем остаются у consumer.

Pure `HealthReducer` принимает typed scope, signal, snapshot и thresholds.
`proxyma.health` сохраняет исходные инженерные defaults Agelto: route открывается
после 5 последовательных proxy connect failures; pair — после 5 сбоев либо
60% на выборке от 10 за 600 секунд. Target становится suspect после одного
origin failure; unavailable требует двух явных independence groups за 300 секунд.
Backoff ограничен cap; успешная стабильная серия уменьшает recent openings.

Completion берёт operation root, затем совместимый network subset в порядке
admission, без owner/finance prefix. В одной SQL transaction сохраняются receipt,
immutable observation, dedup observation+scope, epoch-valid projection, cooldown
и release capacity. Ошибка любого шага откатывает всё; authorization остаётся
восстановимым. External I/O внутри transaction отсутствует, время берётся из
`clock_timestamp()`.

Неопределённый исход сохраняет отдельный transport failure. Reaper освобождает
lease один раз; поздний verified receipt переводит unknown в completed без
второго списания quota и release. Повтор с другим receipt digest — конфликт.
Факты и приложения защищены от UPDATE/DELETE; history пока сохраняется целиком,
включая окно reducer и unresolved unknown.
Failure и late receipt одной попытки дают один sample окна; известный failure
остаётся консервативным свидетельством до конца окна.

Authorization фиксирует origin, endpoint/version, egress, profile, interpretation
revision, independence group и epochs. 429 продлевает cooldown исходного origin
через max даже при stale epoch. Ротация egress не переносит его на новую область.
`health_cooldown_scopes` позволяет дополнительно закрепить `route-origin` и
`egress-origin`; список budget IDs фиксируется до отправки, origin обязателен.
403 даёт pair hold только при opt-in WAF policy; обычный 403 — transport success.
Ни один глобально здоровый маршрут не отменяет origin cooldown.

Stale epoch проверяется отдельно для каждой области. Success не закрывает open
circuit или credential hold. Half-open требует точного неистёкшего probe token;
выдача compound probe и операторские команды принадлежат следующему этапу.
Receipt после credential rotation сохраняется, но старый 407 не ставит hold
новой версии. Retired target сохраняет receipt, completion не меняет catalog
retirement/enabled. При изменении origin target его health не получает старую
атрибуцию. Принятие результата после потери owner — решение consumer.
