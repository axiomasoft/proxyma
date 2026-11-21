# Proxyma Core (`axiomasoft/proxyma-core`)

Ядро маршрутизации исходящего трафика: политики, durable admission, бюджеты,
health и transport. Namespace `Proxyma\`. PHP ^8.4, Laravel 13, только PostgreSQL.
Пакет не импортирует приложение-хост и не зависит от Filament.

Установка из клонов GitHub, а не из корня монорепозитория: [установка](../../docs/installation.md).
Packman для этого дерева — commit `a5a5442898cabb2d22e9f6a47a83caa798e3e5a3`
(<https://github.com/axiomasoft/packman>). Path repositories с `symlink: false`
и явной картой `versions`. Конфиг публикуется тегом `proxyma-config`. Набор
миграций central: `axiomasoft/proxyma-core:proxyma`.

Чтобы отключить плагин, добавьте `axiomasoft.proxyma` в `packman.disabled_plugins`.
Хост может задать `proxyma.table_prefix` до boot.
