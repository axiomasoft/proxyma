# Expiry, unknown и receipt recovery

`Reconciler::run(trustedTenant, limit)` обрабатывает одну страницу из 1–64 operation
roots. Сначала идёт unlocked candidate scan по due demands/permits, затем каждый
root берётся `FOR UPDATE SKIP LOCKED` в UUID order. Один root имеет максимум 256
demands/permits в recovery subset. После root блокируются исходные policy heads,
profile, endpoints/routes/health, target/health, canonical budgets/windows, demands,
permits и attempts. Permit-first locking и first-send provisioning отсутствуют.

Tenant cursor и конечная sweep lease сохранены отдельно. Каждая operation
обрабатывается своей короткой transaction; продвижение cursor идёт после commit.
Сбой до продвижения повторит idempotent работу. После crash lease истекает;
занятый root пропускается и снова попадает в scan после wrap cursor. Idle roots
не расходуют страницу due work. CLI `proxyma:reconcile --limit=32` использует
trusted tenant resolver и permission `execution.reconcile`; tenant из аргументов
команды не принимается. Внешний scheduler вызывает ограниченные страницы.

## Crash semantics

До reserve нет удержания. После reserve expiry переводит permit в
`expired_before_send`, освобождая unused quota и lease ровно один раз. Shared
reserve/send TAT не отматываются. После durable authorization любой crash до
сохранения receipt означает `unknown_after_authorization`, даже если bytes ещё
не наблюдались. Consumed quota и authorization history остаются неизменными;
конечная lease освобождает inflight при expiry. Это предел lease accounting,
а не доказательство закрытого удалённого socket.

Recovery никогда не вызывает transport и не перепосылает unknown, в том числе
`AmbiguousWrite`. Оператор/provider reconciliation решает неизвестный удалённый
эффект отдельно; автоматического finance settlement здесь нет.

## Completion transaction

`Completion` связан с `CompletionCoordinator`. Descriptor/body/header hashes
вычисляются до transaction; raw body и headers не сохраняются в audit. Trusted
tenant и permission `execution.receipt.record` проверяются до SQL. Operation root
берётся первым, без повторного owner prefix: receipt допустим после owner cancel,
deadline, credential rotation и retirement.

Attempt fact, receipt identity/hash, observation, reducer application marker и
однократный lease release commit вместе. `LockedCompletionReducer` — фиксированный
package-owned SQL contract на том же Connection, без extension callback/config
hook. Текущая реализация сохраняет marker `completion_fact`; health policy/reducer
реализуется отдельной фазой. Stale authorization epochs сохраняют receipt и
observation, но не вызывают reducer. Ошибка reducer откатывает весь completion.

Late receipt проверяет attempt + исходный wire fingerprint и разрешает
unknown→completed. Same receipt ID/hash — idempotent duplicate; другой ID/hash
даёт conflict. Обычный `record` также проверяет digest результата. Replay ничего
не списывает и не освобождает повторно. DB guards запрещают стирать receipt,
удалять authorization history, возвращать attempt назад и отменять lease release.
Transport failure после authorization остаётся conservative unknown.

## Retention и temporary artifacts

`RetentionPruner` принимает положительный retention (по умолчанию 30 дней) и
страницу до 64 roots. Удаляются только terminal unused preauthorization rows с
освобождёнными reservations после retention. Operation identities сохраняются;
authorization/receipt history и unresolved unknown не удаляются. История quota
windows также сохраняется. Это позволяет поздний receipt и внешний financial
association без угадывания результата.

`OwnedTemporaryArtifacts` создаёт только собственные UUID paths в локальном
закрытом root и сохраняет ownership token с permit. File I/O всегда вне SQL;
GC отмечает delete-pending под operation root, после commit проверяет ownership
и удаляет файл, затем завершает registry cleanup. Сбой между unlink и registry
cleanup безопасен для повторного GC. Unknown/active artifacts остаются held.
Foreign files, symlinks и неверный ownership token не удаляются. Unregistered
файл после crash между file creation и registry commit сохраняется для отдельной
операторской проверки; GC не угадывает его принадлежность.

До P5 crash tests используют отдельные workers, SIGKILL, SQL barriers и test-only
single-use receipt counter. Они доказывают durable accounting и отсутствие
повторного dispatch в recovery. Реальные wire crash checks остаются P5.3/P8.
