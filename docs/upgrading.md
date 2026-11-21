# Обновление и rollback 0.1

Свежая установка пакета и обновление уже заполненной базы — разные проверки.
JUnit пакета не заменяет проверку install и populated upgrade в приложении.
Смена исходников — [установка](installation.md).

## До переключения authority

Сохраните прежние source SHA, composer.lock приложения, образы, migration
inventory, backup и snapshot/checksums активных данных. Новый checkout
проверяйте по [установке](installation.md). Не меняйте historical migrations
и не удаляйте compatibility shim исторической модели, пока fresh install её
вызывает.

Приложение-хост владеет additive mappings, migration journal и authority
generation. Универсальной package-команды production cutover нет.
Нужны idempotent catalog/binding transfer, сохранение quota used+reserved,
TAT/send TAT, cooldown, epochs, active windows, reducer observation windows и
owner correlations. Сначала проверьте это на populated isolated fixture.

Перед switch остановите новые legacy demands persisted control/generation,
остановите прежних workers и дождитесь bounded drain live permits/operations.
Сверьте fresh inventory/hash/counts при отсутствии writers. Uncertain sends
остаются held/unknown; отсутствие receipt не доказывает отсутствие bytes.
Переключение выполняет одна durable authority generation; старые workers и stale
UI forms обязаны получить отказ. Dual write и одновременные authorities запрещены.

## Rollback и irreversible point

До **первого durable authorization/claim новой authority** допустим возврат к
прежней generation только после drain и доказательства неизменности исходного
состояния. Восстановите прежние source/locks/images как согласованный набор,
запустите совместимых workers и повторите проверки. Не выполняйте `migrate:rollback`
для уничтожения audit/authorization history: часть migrations forward-only.

После первого durable new claim (даже при crash до socket/ответа) automatic rollback
в 0.1 запрещён. Поставьте работу на pause/hold, сохраните unresolved unknown,
receipts и charges; исправляйте пакет и продолжайте roll-forward. Возврат старого
кода без reverse transfer недопустим. Отдельно спроектированный reverse transfer
не входит в 0.1; snapshot/checksums сохраняются для такого разбора.

## Семантика неизвестного результата и retention

Expiry после authorization оставляет `unknown_after_authorization`, consumed
quota и durable историю. Reconcile освобождает конечную inflight lease ровно
один раз, но не доказывает закрытия удалённого socket. Recovery не отправляет
unknown повторно. Late receipt завершает unknown без повторного расхода.

В `proxyma:prune --retention=2592000 --limit=32` retention — **секунды** по DB clock
(2592000 секунд = 30 дней), limit — operation roots, 1..64. Удаляются только
безопасные terminal preauthorization rows. Unknown, authorized history, финансовые
связи, audit, health windows и необходимые quota links не обрезаются по возрасту.
Это conservative retention, а не полный storage GC. Подробности —
[operations](operations.md) и [recovery](recovery.md).
