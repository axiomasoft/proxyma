# Proxyma

Public monorepo for two Laravel packages: `axiomasoft/proxyma-core` (`packages/core`, namespace `Proxyma\`) and optional `axiomasoft/proxyma-filament` (`packages/filament`). The repository root is the development workspace and is not what Composer installs. Project invariants live in `.ai/guidelines/project.md`.

IDE packs under `.claude/`, `.codex/`, and `.gemini/` are local generated files and are not part of the tree. Do not commit them, `.swissknifeman/`, or `integration/`.

## Invariants

- PostgreSQL is the only authority. SQLite does not prove admission, locks, or quotas.
- Core does not import `App\`, Filament, a host process runtime, or LLM billing.
- No network, DNS, TLS, or secret resolution inside a SQL transaction. Durable deadlines use `clock_timestamp()`.
- A timeout after authorization does not prove that the request was not sent.
- The default authorizer denies. TLS peer and hostname verification cannot be turned off.
- Tests use the isolated `proxyma_test` database, the private fixture network, and fake providers. Do not reset a shared database or call a paid provider.
- PHP 8.4 commands go through `./bin/php84` when that wrapper is present.
- Do not suppress PHPStan with `@phpstan-ignore` in package `src/`, and do not leave `dd()`, `dump()`, or `eval()` there.

## Checks

```bash
composer lint:check
composer analyse
composer test
composer test:pg
make test-up && make test-all
```

`composer test` excludes the `pg` and `docker-rig` groups. The rig is the qualification run.
