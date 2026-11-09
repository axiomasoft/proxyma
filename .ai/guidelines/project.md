# Proxyma — project contract

Private Laravel network package, target 0.1.0 (the 0.1 line). Repository:
`axiomasoft/proxyma`; two deliverables: `axiomasoft/proxyma` in `packages/core`
and `axiomasoft/proxyma-filament` in `packages/filament`. No public release,
Packagist submission, GitHub release or publication is part of this work.

This checkout currently contains setup and a fully detailed design. Implementation
belongs to Native Planner PLAN1, reachable through `plans/` (the usual Swissknifeman
workspace link, as in Chatom/Vaulter). Use the project launcher:
`python3 -I .swissknifeman/launcher.py planner`. Read/mutate plan data through that
API; `views/` are generated human views. Do not auto-start implementation for a
design request. Never run archived Task Layout v2 lifecycle commands.

Architectural decisions and implementation context belong to PLAN1 knowledge and
decisions. Core must not depend on `App\`, Filament, Process Core or LLM billing.
PostgreSQL is the only stateful backend in 0.1. PHP 8.4, Laravel 13 and Filament 5
are the first qualification targets, matching the pinned Agelto baseline.

The principal agent owns cross-cutting architecture and review acceptance. Delegate
bounded implementation only after delivering the item context. No executor may
weaken ownership, network, TLS, budget, health, DB isolation or evidence guards.
Always preserve unrelated worktree changes and record observed checks honestly.

Project rules override generic agent-pack examples: PostgreSQL concurrency tests
require separate real connections/processes; SQLite is not authority evidence.
The package rig must use an isolated `proxyma_test` DB, private fixture network,
fake providers and safe artifacts. Agelto verification is exclusively its
`agelto_core_test` rig and `make core-*`. No dev/shared DB resets, live crawling or
paid provider calls. Do not infer a PHP executable or model from generated examples;
use the item's verified runtime and current native model route.

Generic code moves to Proxyma; process owners, adapter health, platform bindings,
financial ledger and `SourceTlsTrust` integration remain explicit Agelto adapters.
Shared process/financial/network authorization must use one PostgreSQL transaction
and connection. No external I/O in DB transactions; clock_timestamp() owns durable
deadlines. A timeout after authorization never proves that a request was not sent.

The setup is connected to Swissknifeman, not vendored. Generated bundles, rules,
agent packs and launcher files are managed by their tools. Use the user-owned
`proxyma-reviewer-overrides.md` extension point for project differences. Shared
instructions live here; keep root AGENTS.md/CLAUDE.md pointers aligned.
