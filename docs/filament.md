# Каталог Proxyma в Filament 5

`axiomasoft/proxyma-filament` — отдельный optional package. Только он требует
`filament/filament:^5`; core устанавливается без Filament. Plugin использует
реальный `Filament\Contracts\Plugin`: `getId()`, `register(Panel)`, `boot(Panel)`.

После установки UI package consumer добавляет plugin в свой PanelProvider:

```php
use Proxyma\Filament\ProxymaPlugin;

return $panel->plugin(ProxymaPlugin::make());
```

Plugin регистрирует три страницы: узлы (`proxyma-endpoints`), маршруты
(`proxyma-routes`), профили (`proxyma-profiles`). Provider загружает views,
но не добавляет plugin во все панели. Конкретные Agelto panel registration и
Process editor остаются в consumer. Основа API: [Filament 5 panel plugins](https://filamentphp.com/docs/5.x/plugins/panel-plugins).

Consumer связывает core `TenantContextResolver` с аутентифицированным контекстом
запроса и `Authorizer` со своей permission system. Ни actor, ни tenant не берутся
из формы. Без этих bindings действует core default deny. Plugin не импортирует
`App\User`, AzGuard или consumer permissions.

| Ability | Назначение |
| --- | --- |
| `catalog.endpoint.list`, `catalog.route.list`, `catalog.profile.list` | Навигация, список и поиск options |
| `catalog.endpoint.read`, `catalog.route.read`, `catalog.profile.read` | Просмотр конкретной записи и загрузка формы |
| `catalog.endpoint.upsert`, `catalog.route.upsert`, `catalog.profile.upsert` | Создание и изменение через core `CatalogCommands` |
| `catalog.route.select` | Выбор каждого route в составе профиля |
| `catalog.endpoint.rotate-credentials` | Отдельная ротация через core `RotateCredentials` |

Authorizer получает trusted `ActorRef`, `TenantKey`, ability и resource UUID.
Для `.list` resource — `CatalogBrowser::CollectionId`
(`00000000-0000-4000-8000-000000000000`); остальные проверки используют ID записи.
Consumer явно отображает эти операции на свои permissions; отсутствие mapping
должно запрещать операцию. UI проверяет write permission независимо от видимости
кнопки; core повторяет проверку, включая связанные endpoint/route IDs.
Скрытая кнопка не даёт права на прямой Livewire вызов `save` или
`rotateCredentials`.

Списки читают текущий tenant, по 25 записей, в порядке UUID. Поиск options
ограничен 25 результатами; permissions проверяются для каждой строки. Route
другого tenant доступен в options только по живому explicit sharing grant,
с последующей core-проверкой при сохранении. UI не редактирует shared route
как собственный. Списки не возвращают credentials или глобальный перечень tenants.

Каждая запись меняется через core command с `CatalogMutationActor`: trusted
`ActorRef`, обязательная причина, загруженная `expectedRevision`, новый
idempotency UUID. Record ID и revision защищены Livewire `Locked`.
Новая запись имеет revision 0. Конфликт показывает предложение перезагрузить
запись и сохраняет старую revision формы: автоматического last-write-wins нет.
Core сохраняет audit и command receipt в собственной SQL transaction; UI не
сохраняет ORM models и не оборачивает secret preparation в SQL transaction.

Credentials меняются отдельно после создания endpoint. Username и password —
только новый ввод, без чтения `SecretResolver` и без заполнения из хранилища.
Форма показывает только маску и credential version. После любого вызова ротации
ввод очищается, включая validation failure и revision conflict. Пустая строка,
`********` и `••••••••` не сохраняются. Обычное сохранение host/protocol/port
сохраняет существующий credential reference. Plaintext не попадает в список,
форму редактирования, audit или command receipt.

Membership профиля заменяется точным выбранным списком, включая `[]`.
Пустой профиль явно запрещает отправку. Direct — отдельный route, выбранный
явно в membership; дополнительно effective policy должна разрешать direct.
Создание пустого профиля не добавляет direct, смена типа proxy на direct требует
явно убрать endpoint. Назначение маршрута, egress pool и independence group
не расширяют runtime authority. Strategy key проверяется по core registry,
включая зарегистрированные consumer extensions.

Поведение проверяют `packages/filament/tests/CatalogAuthorizationTest.php`
(command audit, direct Livewire authorization, tenant isolation, masking,
credential conflict, pagination) и `RouteProfilesTest.php` (точный состав,
empty deny, explicit direct, foreign IDs, locked revision, sharing grant и
stale membership). Все DB fixtures используют отдельные схемы marked
`proxyma_test` rig; Agelto DB и внешние provider APIs не используются.
