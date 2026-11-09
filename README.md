# Proxyma

Приватный сетевой пакет для Laravel. Целевая версия — **0.1.0** (линейка 0.1).
Планируемые пакеты: `axiomasoft/proxyma` и `axiomasoft/proxyma-filament`.
Сейчас подготовлены окружение и план; реализация ещё не начата.

Подробный план Swissknifeman: **PLAN1 — Proxyma 0.1: приватный сетевой пакет и
интеграция Agelto**. Как в соседних пакетах, `plans/` связан с хранилищем workspace
`axioma`; план доступен из этого каталога, а его состояние хранит Native Planner.

```bash
python3 -I .swissknifeman/launcher.py planner show PLAN1
python3 -I .swissknifeman/launcher.py planner next PLAN1
python3 -I .swissknifeman/launcher.py planner context PLAN1 P1.1 --stdout
python3 -I .swissknifeman/launcher.py planner check PLAN1
```

Человекочитаемый обзор: [план](plans/2026.11.09-№1-PROXYMA-01/views/plan.md).
Исполнители читают JSON context через API. `knowledge/` содержит контрактные
решения, алгоритмы, матрицу проверок и сценарий интеграции; это часть плана.

Все **10 фаз** имеют статус `detailed`: **39 пунктов**, **106 проверок приёмки**,
**10 принятых проектных решений**. Проверки приёмки относятся к будущей реализации.
Дизайн проверен отдельно: `planner check` — **0 ошибок, 0 предупреждений**;
проверена сборка всех 39 context packs, без отсутствующих обязательных inputs,
дублирующих reads и превышения выбранного бюджета контекста.
Фактический отчёт: [design-validation.json](docs/reference/design-validation.json).

Исходный аудит сохранён в `docs/reference/perplexity-proxyma.md`. Собственная
проверка и отклонения от его предложений отражены в plan knowledge `evidence` и
решениях D1–D10. База анализа Agelto: `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`.

Приватная поставка планируется через закреплённый commit SHA, Composer path
repositories с копированием и проверку в Agelto. Публичная публикация и release
workflow не требуются.

Swissknifeman подключён через `connect`, launcher и workspace проверены,
для Codex выбран проектный набор из 7 plugins. MCP настроен для Claude Code,
Codex и Cursor; документация помечена приватной. Удалённый сервис памяти mAInd
на момент настройки недоступен: это не мешает хранению плана и работе launcher.
Режим памяти — `manual`, локальная замена удалённого сервиса не запускалась.
