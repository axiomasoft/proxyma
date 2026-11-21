# Установка и обновление пакетов

Ставятся два пакета из клонов GitHub, не корень монорепозитория и не архив
Packagist. Имена: `axiomasoft/proxyma-core` и, если нужен UI,
`axiomasoft/proxyma-filament`. В `composer.json` пакетов нет поля `version`:
path repository задаёт её в `options.versions`.

PostgreSQL — единственное stateful-хранилище. MySQL, SQLite и Redis не являются
authority. Нужны PHP ^8.4, Laravel 13 (`illuminate/*` ^13.0), расширения curl,
mbstring, openssl, pdo и pdo_pgsql. UI дополнительно требует Filament ^5.0.
Не передавайте Composer `--ignore-platform-reqs`. Границы проверенных сборок —
[совместимость](compatibility.md).

## Клоны

Команды ниже — из корня приложения. Выберите полный commit SHA Proxyma и
запишите его. Для этого дерева CI берёт Packman
`a5a5442898cabb2d22e9f6a47a83caa798e3e5a3` из
<https://github.com/axiomasoft/packman>. Другой SHA Packman не подставляйте,
пока выбранный commit Proxyma явно не сменил этот pin и карту версий в своём
корневом `composer.json`.

Клонируйте по SSH. Токен не входит в URL: ни в `https://<token>@github.com/...`,
ни в git remote, ни в Docker context. Ключ даёт SSH agent или отдельный
read-only deploy key; файл auth Composer, если он нужен, лежит вне репозитория.

```bash
test ! -e local/vendors/proxyma
test ! -e local/vendors/packman
git clone --no-checkout git@github.com:axiomasoft/proxyma.git local/vendors/proxyma
git -C local/vendors/proxyma checkout --detach "$PROXYMA_SHA"
git clone --no-checkout git@github.com:axiomasoft/packman.git local/vendors/packman
git -C local/vendors/packman checkout --detach a5a5442898cabb2d22e9f6a47a83caa798e3e5a3
test "$(git -C local/vendors/proxyma rev-parse HEAD)" = "$PROXYMA_SHA"
test "$(git -C local/vendors/packman rev-parse HEAD)" = a5a5442898cabb2d22e9f6a47a83caa798e3e5a3
test -z "$(git -C local/vendors/proxyma status --porcelain --untracked-files=all)"
test -z "$(git -C local/vendors/packman status --porcelain --untracked-files=all)"
```

Грязный или чужой checkout не перезаписывайте и не reset-ите. Нет нужного
объекта — остановитесь. Локальный доверенный клон допустим только как
`git clone --no-hardlinks --no-checkout /absolute/path/to/proxyma local/vendors/proxyma`
с тем же detach на полный SHA и пустым `status`. Origin после этого всё равно
должен быть `git@github.com:axiomasoft/proxyma.git` без учётных данных в URL.

## Path repositories

В `composer.json` приложения добавьте отдельные repositories. Не добавляйте
корень `local/vendors/proxyma` как VCS или path: Composer не объявит вложенные
пакеты сам. `symlink` только `false`: проверка поставки копирует файлы и затем
удаляет исходные `sources/`. Symlink корня монорепозитория — только его
собственная сборка, не доказательство установки.

Обоим пакетам Proxyma нужна одна строка версии. Filament зависит от
`axiomasoft/proxyma-core` через `self.version`; разные строки не сойдутся.
Проверенные install fixtures задают `dev-main`. Это path-версия, не git-тег.

Версии Packman в path map этого дерева: `axiomasoft/packman`,
`axiomasoft/packman-schema`, `axiomasoft/packman-panels` и
`axiomasoft/packman-filament` — `0.1.1`; `axiomasoft/packman-plugins` — `0.1.2`
(core требует `^0.1.2`). `reference: config` — хеш manifest и path options, не
Git SHA. Git SHA проверяется checkout-ом выше.

Приложение только с core опускает repository `packages/filament`, requirement
`axiomasoft/proxyma-filament` и строку `axiomasoft/packman-filament`.
`packman-testkit` и `packman-devtools` в приложение не ставятся.

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "local/vendors/proxyma/packages/core",
      "options": {
        "symlink": false,
        "reference": "config",
        "versions": {"axiomasoft/proxyma-core": "dev-main"}
      }
    },
    {
      "type": "path",
      "url": "local/vendors/proxyma/packages/filament",
      "options": {
        "symlink": false,
        "reference": "config",
        "versions": {"axiomasoft/proxyma-filament": "dev-main"}
      }
    },
    {
      "type": "path",
      "url": "local/vendors/packman/packages/*",
      "options": {
        "symlink": false,
        "reference": "config",
        "versions": {
          "axiomasoft/packman": "0.1.1",
          "axiomasoft/packman-schema": "0.1.1",
          "axiomasoft/packman-plugins": "0.1.2",
          "axiomasoft/packman-panels": "0.1.1",
          "axiomasoft/packman-filament": "0.1.1"
        }
      }
    }
  ],
  "require": {
    "axiomasoft/proxyma-core": "dev-main",
    "axiomasoft/proxyma-filament": "dev-main"
  }
}
```

```bash
composer update axiomasoft/proxyma-core axiomasoft/proxyma-filament --with-all-dependencies
composer validate --strict
composer check-platform-reqs
test ! -L vendor/axiomasoft/proxyma-core
test ! -L vendor/axiomasoft/proxyma-filament
```

Сохраните `composer.lock`. Повторная установка из уже скачанных архивов:

```bash
COMPOSER_DISABLE_NETWORK=1 composer install --no-dev --prefer-dist --no-interaction
```

Отсутствующий архив должен давать отказ, а не тихий переход на плавающую ветку.
В production image копируются subtrees пакетов и собранный `vendor`, без `.git`,
`.env`, `auth.json` и ключей.

## Конфигурация

Discovery регистрирует `Proxyma\Laravel\ProxymaServiceProvider` и, при установленном
UI, `Proxyma\Filament\ProxymaFilamentServiceProvider`. Если discovery выключен,
добавьте их в `bootstrap/providers.php`.

```bash
php artisan vendor:publish --tag=proxyma-config
php artisan package:discover
php artisan config:cache
```

Тег `proxyma-config` публикует `config/proxyma.php`. Cache и discovery не
открывают базу и не читают секреты. До команд runtime задайте соединение
PostgreSQL, `APP_KEY` и отдельный `PROXYMA_WIRE_DIGEST_KEY` не короче 32 байт.
Секреты приходят из окружения или mount, не из репозитория и не из образа.

Префикс таблиц по умолчанию `pxm_`. Хост может задать `proxyma.table_prefix` до
boot. Набор миграций central: `axiomasoft/proxyma-core:proxyma`. Обычный
`artisan migrate` этот набор не выбирает.

```bash
php artisan packman:schema:migrate --scope=central --database=pgsql --force
php artisan proxyma:doctor
php artisan proxyma:transport-doctor
```

`--database` указывает на PostgreSQL приложения. Doctor с кодом 0 — локальная
готовность схемы и конфигурации, не успешная сеть. Authorizer по умолчанию
запрещает операции; не подменяйте его allow-all ради doctor или cron. Приложение
само регистрирует доверенные `TenantContextResolver` и `Authorizer`.

Отключение плагина не удаляет таблицы. Добавьте id в конфиг Packman:

```php
'disabled_plugins' => ['axiomasoft.proxyma'],
```

После смены списка снова выполните `package:discover` и `config:cache`.

Расписание, batch и retention в секундах — [операции](operations.md). Смена
authority уже заполненной базы, drain и точка невозврата — [обновление](upgrading.md).
Модель секретов и TLS — [security](security.md).

## Обновление исходников

1. Сохраните текущие SHA обоих клонов и `composer.lock`.
2. В клоне Proxyma получите объекты и сделайте `checkout --detach` на новый
   полный SHA. Ветку без записанного SHA не используйте. Fetch идёт тем же SSH
   без токена в URL. Грязное дерево не reset-ите.
3. Packman оставьте на `a5a5442898cabb2d22e9f6a47a83caa798e3e5a3`, если корневой
   `composer.json` нового commit Proxyma не меняет версии path map. Если меняет,
   перейдите на SHA и версии из этого commit и обновите `options.versions`.
4. Повторите `composer update` двух пакетов Proxyma и проверьте, что
   `vendor/axiomasoft/proxyma-core` и `vendor/axiomasoft/proxyma-filament` не
   симлинки. Строки версий обоих пакетов Proxyma по-прежнему совпадают.
5. Заново опубликуйте конфиг только если приняли изменения шаблона:
   `php artisan vendor:publish --tag=proxyma-config`. Не затирайте локальные
   значения вслепую. Затем `config:cache`.
6. На PostgreSQL выполните
   `php artisan packman:schema:migrate --scope=central --database=pgsql --force`.
   Это не откат и не перенос чужой заполненной authority. Условия rollback —
   [обновление](upgrading.md).
