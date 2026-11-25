# Обновление Proxyma

Следуйте [runbook обновления](docs/upgrading.md). Смена клонов и path repositories —
[установка](docs/installation.md). В 0.1 первый durable claim новой authority —
точка, после которой восстановление идёт только roll-forward. Откат исходников
сам по себе не откатывает отправку, бюджет или состояние authority.
