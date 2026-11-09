Да. Здесь стоит выделять **не просто справочник прокси, а небольшой расширяемый контур управления исходящими запросами**: маршруты, профили, разрешения на отправку, настройки и точки расширения. В Agelto уже есть значительная часть такого контура, но она связана с владельцами процессов, площадками и адаптерами — именно эти зависимости нужно разрезать, а не механически переносить каталоги. [Исходная схема](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php), [проверка владельца](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/NetworkOwnerFence.php).

Ниже — подробный архитектурный план для **Proxyma**: что переносить, что оставить в Agelto, как устроить пакет, настройки, расширения, бюджетирование, Filament и поэтапное извлечение. Название везде нормализовано как `proxyma`, как в исходном запросе.

## Статус и границы анализа

Разбор привязан к [коммиту `f8828dc`](https://github.com/axiomasoft/agelto/commit/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f) от 9 ноября 2026 года. В нём как раз присутствуют изменения платформенных прокси-бюджетов, профилей стадий и балансировки пула. [Коммит](https://github.com/axiomasoft/agelto/commit/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f).

Исследованы ключевые классы прокси и сети, настройки, сетевая миграция, связанные модели, страницы Filament и состав тестов. Это **статический архитектурный анализ**, а не подтверждение работоспособности: тесты, миграции и конкурентные сценарии здесь не запускались.

Далее используются три обозначения:

- **Есть сейчас** — подтверждено исходниками.
- **Предлагается** — целевая конструкция Proxyma.
- **Проверка перед переносом** — место, где необходим дополнительный исполняемый тест или инвентаризация зависимостей.

Все фрагменты нового API ниже — **предлагаемый дизайн**, а не существующий API Agelto и не готовая реализация пакета.

## Главные архитектурные решения

Предлагается принять следующие решения до начала реализации:

| Вопрос | Решение |
|---|---|
| Основной репозиторий | `axiomasoft/proxyma` |
| Основной Composer-пакет | `axiomasoft/proxyma` |
| Filament | Отдельный Composer-пакет `axiomasoft/proxyma-filament` |
| Организация разработки | Сначала один репозиторий с двумя пакетами |
| Ядро | Независимая предметная логика внутри Laravel-пакета |
| Модели и миграции | В основном пакете, не в Filament |
| Бюджеты и health | Встроенные, но явно подключаемые модули |
| Процессы Agelto | Остаются в Agelto |
| Интеграция с процессами | Через небольшой адаптер Agelto → Proxyma |
| PHP-конфигурация | Структура, классы, разрешённые возможности и ограничения |
| База данных | Операционные настройки в пределах разрешённой PHP-схемы |
| Расширение | Контракты, реестры стратегий, типизированные обработчики и контролируемые pipeline |
| Произвольный PHP из админки | Запрещён |
| Неявный direct fallback | Запрещён |
| Извлечение | С сохранением гарантий текущего сетевого контура |

**Не рекомендую сразу создавать пять–семь отдельных пакетов**: `contracts`, `core`, `eloquent`, `budgets`, `health`, `events`, `filament`. Это добавит выпуск версий и сложность сопровождения раньше, чем появится реальная потребность.

При этом внутренние границы должны позволять позднее выделить бюджетирование или отдельный транспорт без переписывания ядра.

***

## Что уже реализовано

### Текущий контур

В Agelto уже существует последовательность:

```text
Контекст владельца
    ↓
Выбор профиля маршрутов
    ↓
Предварительный выбор маршрута
    ↓
Регистрация demand
    ↓
Резервирование permit
    ↓
Повторная проверка перед отправкой
    ↓
Одна физическая отправка
    ↓
Фиксация результата
    ↓
Обновление health / cooldown
    ↓
Решение о повторе или завершении
```

Это следует из `ProxyResolver`, `RouteSelector`, `ScheduledAttemptGate`, `NetworkPermitRepository` и `OutboundExecutor`. Поэтому извлекать только `Proxy` и `ProxyResolver` недостаточно: получится новый фасад над прежней зависимостью от Agelto. [Резолвер](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/ProxyResolver.php), [допуск](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/ScheduledAttemptGate.php), [permit repository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkPermitRepository.php), [executor](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/OutboundExecutor.php).

### Инвентаризация механизмов

| Механизм | Есть сейчас | Вывод для Proxyma |
|---|---|---|
| Справочник прокси | Модель `Proxy`, связанная с каноническим маршрутом | Перенести с отделением универсальных полей от проектных назначений |
| Direct и proxy | Единые `network_routes` | Сохранить |
| Профили маршрутов | Профиль и точное членство маршрутов | Сохранить как основной механизм выбора разрешённого пула |
| Разрешение назначения | Связи с crawler и procurement | Оставить проектную часть в Agelto |
| Настройки стадий | Отдельные профили для `listing`, `detail`, `status_refresh` | Обобщить механизм, но не значения стадий |
| Балансировка | Случайный выбор, учёт бюджетной доступности; есть ветка least-recent authorization | Сделать стратегии зарегистрированными |
| Бюджеты | Origin, route-origin, egress-origin, probe | Сохранить и расширять через общий набор ограничений |
| Очередь допуска | Ограниченные FIFO demand tickets | Сохранить в модуле admission/budget |
| Последняя проверка | Отдельный `claimSend` | Обязательно сохранить |
| Circuit breaker | Route, route-target, target | Вынести как сетевой health-модуль |
| Проверки восстановления | Probe ownership и epochs | Сохранить в health-модуле |
| Повторы и редиректы | Общий `OutboundExecutor` | Перенести общую часть |
| Классификация ошибок | Типизированные transport failures и HTTP-коды | Разделить факт, health-атрибуцию и решение вызывающего кода |
| Административное изменение | Версии, причины, команды | Обобщить actor и authorization |
| Filament | Глобальный справочник и страница профилей | Вынести в отдельный UI-пакет |

Основания для этой карты: [модель прокси](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Models/Procurement/Crawler/Proxy.php), [выбор маршрута](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/RouteSelector.php), [конфигурация стадий](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Dto/Procurement/Acquisition/AcquisitionConfiguration.php), [сетевая схема](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php), [страница профилей](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Filament/Pages/NetworkRouteProfilesPage.php).

### Важные существующие гарантии

Следующее нельзя потерять при упрощении пакета:

- Пустой профиль не означает разрешение прямого соединения.
- Предварительный выбор маршрута не является окончательным разрешением отправки.
- Перед отправкой повторно проверяются владельцы, версии политик, health и бюджетные ограничения.
- Один физический запрос имеет собственную идентичность.
- Поздний результат не должен бесконтрольно восстанавливать уже изменившееся состояние.
- Секреты и административное состояние маршрута не должны размазываться между разными моделями.

Эти ограничения уже выражены в текущем резолвере, модели, permit repository и health repository. [ProxyResolver](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/ProxyResolver.php), [Proxy](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Models/Procurement/Crawler/Proxy.php), [NetworkPermitRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkPermitRepository.php), [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

**Лёгкое ядро — это небольшое количество ответственностей, а не удаление проверок конкурентного доступа.**

***

## Граница пакета и проекта

### Что принадлежит Proxyma

Proxyma должна понимать:

- Что такое маршрут.
- Какой профиль разрешает какие маршруты.
- К какому назначению относится запрос.
- Как получить эффективную сетевую политику.
- Как проверить право на очередную физическую отправку.
- Как применить подключённые ограничения.
- Как выполнить одну отправку через поддерживаемый транспорт.
- Как сохранить технический результат.
- Как передать результат обработчикам.
- Как объяснить принятое решение.

### Что принадлежит Agelto

В Agelto должны остаться:

- Закупки, реестры и площадки как предметные сущности.
- Crawlers и acquisition profiles.
- Стадии `listing`, `detail`, `screening`, `extraction`.
- Work, run, attempt, generation и существующая process-authority.
- Жизненный цикл процессов.
- Парсеры, completeness, adapter contract validation.
- Публикация результатов закупки.
- Финансовый учёт LLM и paid-response recovery.
- Проектные формы редактирования процессов.
- Проектные права доступа и их связь с пользователями.

Сейчас `NetworkOwnerFence` непосредственно принимает `ExecutionLease`, обращается к authority процесса, проверяет stage и связывает сетевой доступ с состоянием work/attempt. Это явная точка для адаптера, а не класс, который следует переносить без изменений. [NetworkOwnerFence](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/NetworkOwnerFence.php).

### Карта переноса

| Текущий элемент | Целевой владелец | Действие |
|---|---|---|
| `Proxy` | Proxyma | Перенести универсальные данные и связь с route |
| `NetworkRoute` | Proxyma | Перенести |
| `NetworkRouteProfile` | Proxyma | Перенести |
| `ProxyScope` | Agelto | Заменить в пакете расширяемыми ключами назначения |
| `ProxyResolver` | Proxyma | Отделить от procurement DTO и исключений |
| `ProxyBindingResolver` | Agelto | Оставить как project binding adapter |
| `RouteSelector` | Proxyma | Отделить policy, repository и стратегию выбора |
| `RequestClassifier` | Proxyma | Превратить в композицию fact classifiers |
| `HealthReducer` | Proxyma health | Перенести чистую логику с независимыми DTO |
| `HealthRepository` | Proxyma health | Убрать process transaction и procurement-хранилище наблюдений |
| `NetworkOwnerFence` | Agelto | Реализовать контракт Proxyma |
| `NetworkPermitRepository` | Proxyma admission | Переписать зависимость от владельца и схему demand |
| `ScheduledAttemptGate` | Proxyma | Использовать независимый execution context |
| `OutboundExecutor` | Proxyma + Agelto adapter | Убрать специальные финансовые и process-ветки |
| Parser/adapter health | Agelto | Подключать через дополнительную проверку допуска |
| Proxy Filament resource | Proxyma Filament | Убрать `App\User`, проектный guard и permission enum |
| Редактор процесса | Agelto | Встроить предоставляемую Proxyma секцию настроек |

`ProxyScope` сейчас содержит конкретные назначения Agelto и реализует интерфейс Filament; `ProxyBindingResolver` обращается к crawlers и procurements. Эти две зависимости нельзя включать в универсальное ядро. [ProxyScope](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Enums/Procurement/Proxy/ProxyScope.php), [ProxyBindingResolver](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/ProxyBindingResolver.php).

***

## Структура репозитория

Предлагаемая исходная структура:

```text
proxyma/
├── README.md
├── CHANGELOG.md
├── LICENSE
├── composer.json
├── phpstan.neon
├── pint.json
├── phpunit.xml
├── docs/
│   ├── architecture.md
│   ├── configuration.md
│   ├── extension-contracts.md
│   ├── admission-and-fencing.md
│   ├── budgets.md
│   ├── health.md
│   ├── security.md
│   ├── migrations.md
│   ├── filament.md
│   └── upgrading.md
├── packages/
│   ├── core/
│   │   ├── composer.json
│   │   ├── config/proxyma.php
│   │   ├── database/migrations/
│   │   │   ├── core/
│   │   │   ├── admission/
│   │   │   ├── budgets/
│   │   │   └── health/
│   │   ├── src/
│   │   │   ├── Domain/
│   │   │   │   ├── Catalog/
│   │   │   │   ├── Context/
│   │   │   │   ├── Policy/
│   │   │   │   ├── Routing/
│   │   │   │   ├── Execution/
│   │   │   │   └── Outcomes/
│   │   │   ├── Contracts/
│   │   │   ├── Application/
│   │   │   │   ├── Commands/
│   │   │   │   ├── Queries/
│   │   │   │   ├── Resolution/
│   │   │   │   ├── Execution/
│   │   │   │   └── Extensions/
│   │   │   ├── Modules/
│   │   │   │   ├── Admission/
│   │   │   │   ├── Budgets/
│   │   │   │   └── Health/
│   │   │   ├── Infrastructure/
│   │   │   │   ├── Database/
│   │   │   │   ├── Eloquent/
│   │   │   │   ├── Http/
│   │   │   │   ├── Secrets/
│   │   │   │   ├── Events/
│   │   │   │   └── Cache/
│   │   │   ├── Laravel/
│   │   │   │   ├── ProxymaServiceProvider.php
│   │   │   │   ├── Console/
│   │   │   │   └── Facades/
│   │   │   └── Testing/
│   │   └── tests/
│   │       ├── Unit/
│   │       ├── Integration/
│   │       ├── Concurrency/
│   │       ├── Contract/
│   │       └── Architecture/
│   └── filament/
│       ├── composer.json
│       ├── resources/
│       ├── src/
│       │   ├── ProxymaPlugin.php
│       │   ├── Resources/
│       │   ├── Pages/
│       │   ├── Schemas/
│       │   ├── Contracts/
│       │   └── Actions/
│       └── tests/
└── tests/
    └── Installation/
```

Здесь `packages/core` — **не чистый PHP-пакет без Laravel**, а основной устанавливаемый пакет с моделями и миграциями. Но его `Domain` не зависит от Laravel: это сохраняет компактность и тестируемость без преждевременного разделения на дополнительные Composer-пакеты.

### Направление зависимостей

```text
Agelto
 ├── зависит от Proxyma
 ├── зависит от Proxyma Filament
 └── реализует адаптеры проектных сущностей

Proxyma Filament
 └── зависит от Proxyma

Proxyma Application
 └── зависит от Domain + Contracts

Proxyma Infrastructure
 └── реализует Contracts

Proxyma Domain
 └── не зависит от Filament, Eloquent и Agelto
```

Архитектурными тестами необходимо запретить:

```text
Proxyma → App\
Proxyma → Filament\
Domain → Illuminate\
Domain → Eloquent Model
```

Запрет `Proxyma → Filament` относится к основному пакету; UI-пакет, естественно, использует Filament.

### Версии первой реализации

В исходном проекте заявлены PHP `^8.4`, Laravel `^13.0` и Filament `^5.0`. Первую проверяемую совместимость разумно строить на этом стеке, а не обещать поддержку старых версий без CI. [composer.json](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/composer.json).

Для stateful admission первой реализации предлагается **PostgreSQL как единственный поддерживаемый backend**. Поддержку MySQL или Redis-authority следует считать отдельной работой с собственными контрактными и конкурентными тестами.

***

## Предметные сущности

### Не смешивать понятия

Нужно явно разделить следующие сущности:

| Сущность | Значение |
|---|---|
| `ProxyEndpoint` | Адрес и протокол прокси, ссылка на credentials |
| `Route` | Способ выхода наружу: direct или proxy |
| `RouteProfile` | Набор разрешённых маршрутов и параметры выбора |
| `Target` | Логическое внешнее назначение |
| `Origin` | Канонический scheme + host + port |
| `SubjectRef` | Внешняя сущность проекта, к которой применяются настройки |
| `PanelDefinition` | Зарегистрированная в PHP схема области настройки |
| `PanelBinding` | Сохранённые настройки конкретной области |
| `EffectivePolicy` | Результат разрешения всех слоёв |
| `OwnerClaim` | Свидетельство о владельце исполнения |
| `Demand` | Заявка на доступ |
| `Permit` | Ограниченное разрешение на одну физическую попытку |
| `Attempt` | Факт отдельной физической попытки |
| `Observation` | Техническое наблюдение по попытке |

**PanelDefinition не должна становиться ещё одним RouteProfile.**

Профиль отвечает на вопрос: «какие маршруты разрешены?».

Панель отвечает на вопрос: «какие настройки доступны этой категории потребителей и откуда они наследуются?».

### ProxyEndpoint и Route

Предлагаемая связь:

```text
ProxyEndpoint 1 ─── 1 Route(kind=proxy)

Route(kind=direct)
    └── не имеет ProxyEndpoint
```

В `Route`:

```text
id
key
kind
enabled
egress_identity
independence_group
credential_version
state_version
```

В `ProxyEndpoint`:

```text
id
route_id
protocol
host
port
username
secret_reference
provider_key
country
metadata
```

Правила:

- `enabled` хранится только у route.
- Credential version имеет одного владельца.
- Пароль не дублируется в endpoint и route.
- `metadata` не используется для скрытых системных настроек.
- Страна и provider — фильтруемые метаданные, не автоматическое право использования.

Текущая модель уже отделяет конфигурационные данные прокси от административного состояния и health канонического маршрута. Эту идею нужно сохранить. [Proxy](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Models/Procurement/Crawler/Proxy.php).

### Target и Origin

Необходимо сохранить различие:

```text
Target:  supplier-api.document-download
Origin:  https://api.example.test:443
```

Несколько targets могут обращаться к одному origin и должны разделять его общий лимит.

И наоборот, один предметный процесс может обращаться к нескольким origins.

**Квота origin не должна умножаться при создании нового target или новой панели.**

В Agelto уже есть отдельные `target_key`, `canonical_origin`, `capability` и origin-scoped budgets. [Схема](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php).

### Внешние сущности

В ядре не должно быть:

```php
Crawler $crawler
Procurement $procurement
ExecutionLease $lease
User $user
```

Для привязок предлагается независимая ссылка:

```php
namespace AxiomaSoft\Proxyma\Domain\Context;

final readonly class SubjectRef
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $tenantId = null,
    ) {
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException(
                'Subject type and id must not be empty.',
            );
        }

        if ($tenantId === '') {
            throw new \InvalidArgumentException(
                'Tenant id must be null or non-empty.',
            );
        }
    }

    public function canonicalKey(): string
    {
        return json_encode(
            [$this->tenantId, $this->type, $this->id],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
```

Проектные примеры:

```php
new SubjectRef('agelto.crawler', '123');
new SubjectRef('agelto.acquisition-profile', '456');
new SubjectRef('agelto.process-run', 'run-uuid');
```

Это не обязательно должна быть Eloquent morph-связь. Стабильный зарегистрированный alias лучше, чем сохранённое в базе имя PHP-класса.

Обязательные правила:

- `type` существует в реестре.
- Разрешённая длина и формат `id` валидируются.
- Tenant участвует в уникальности и authorization.
- Удаление внешней сущности не удаляет исторические attempts.
- Отсутствующая внешняя сущность делает binding orphaned, а не молча глобальным.

***

## Модели и хранилище

### Назначение Eloquent-моделей

Модели нужны для:

- Чтения справочника.
- Отношений.
- Форм и таблиц.
- Проектных расширений представления.
- Factories.
- Неавторитетных read-моделей.

Но изменения permit, budget counters, epochs и административных версий должны проходить через команды и репозитории.

Нельзя делать основной API таким:

```php
$route->enabled = false;
$route->save();
```

Предлагаемый API:

```php
$routeCommands->disable(
    routeId: $routeId,
    expectedVersion: $version,
    actor: $actor,
    reason: 'Credentials are being rotated',
);
```

Это обеспечивает одну точку:

```text
authorization
→ validation
→ locking
→ version check
→ mutation
→ audit
→ event/outbox
```

### Подмена моделей

Можно позволить конфигурацию моделей:

```php
return [
    'models' => [
        'proxy_endpoint' => CustomProxyEndpoint::class,
        'route' => CustomRoute::class,
        'route_profile' => CustomRouteProfile::class,
    ],
];
```

Но границу следует ограничить:

- Класс должен наследоваться от соответствующей базовой модели.
- Идентичность и обязательные поля не меняются.
- Ledger/admission-таблицы не переопределяются через произвольную модель.
- Все связи используют `ModelRegistry`.
- UI не должен жёстко обращаться к базовому классу.
- Критические SQL-репозитории работают по собственному storage-контракту.

Для дополнительных проектных полей предпочтительнее связанная таблица:

```text
proxyma_proxy_endpoints
    ↓ hasOne
agelto_proxy_endpoint_metadata
```

Это безопаснее, чем превращать замену модели в обещание совместимости с любой схемой БД.

### Предлагаемые таблицы

| Группа | Таблицы |
|---|---|
| Каталог | `proxyma_routes`, `proxyma_proxy_endpoints`, `proxyma_route_profiles`, `proxyma_route_profile_members`, `proxyma_targets` |
| Настройки | `proxyma_policy_bindings`, `proxyma_policy_revisions`, `proxyma_policy_heads` |
| Аудит | `proxyma_audit_entries` |
| Admission | `proxyma_operations`, `proxyma_demands`, `proxyma_permits` |
| Попытки | `proxyma_attempts`, `proxyma_observations` |
| Budget module | `proxyma_budget_policies`, `proxyma_budget_states`, `proxyma_quota_windows`, `proxyma_permit_budget_scopes` |
| Health module | `proxyma_health_states`, `proxyma_health_applications`, `proxyma_probe_claims` |
| Надёжная доставка событий | `proxyma_outbox_messages` |

Это целевая схема, а не требование обязательно создавать все таблицы при минимальной установке.

### Разделение настроек и состояния

Критически важно:

```text
Policy / configuration
    ≠
Runtime state
    ≠
Historical facts
```

Например:

```text
budget policy:
    limit = 600/hour
    version = 4

budget runtime:
    consumed = 317
    reserved = 8
    window_start = ...

attempt fact:
    request_id = ...
    authorized_at = ...
```

Редактирование `limit` не должно пересоздавать runtime state и обнулять историю.

***

## Модульная композиция

### Минимальная установка

Предлагается поддержать минимальный режим:

```text
Каталог
+ профили
+ настройки
+ выбор маршрута
+ безопасное исполнение
+ базовый журнал попыток
```

Дополнительно подключаются:

```text
Durable admission
Budgets
Health
Durable external events
Filament
```

Но для Agelto должны быть явно обязательными:

```text
durable admission
budgets
health
owner fencing
```

**Отключение модуля не должно незаметно отменять требования проекта.**

Пример:

```php
return [
    'modules' => [
        'admission' => true,
        'budgets' => true,
        'health' => true,
        'outbox' => true,
    ],

    'required_capabilities' => [
        'durable-admission',
        'multi-scope-budgets',
        'owner-fencing',
        'health-epochs',
    ],
];
```

При загрузке:

```text
required capability
    отсутствует
        → ошибка конфигурации
```

Не:

```text
required capability
    отсутствует
        → NullBudgetLimiter и отправка без ограничений
```

### Где размещать модули

Первоначально модули находятся внутри основного Composer-пакета, но имеют:

- Собственный provider.
- Собственные миграции.
- Собственные repositories.
- Собственные тесты.
- Явный список capabilities.
- Явные зависимости от других модулей.

Это даёт опциональность использования без немедленного разделения релизов.

Если появляется независимый потребитель budget engine, модуль можно выделить позднее.

### Отключение после установки

Настройка `enabled=false`:

- Не удаляет таблицы.
- Не удаляет накопленные ограничения.
- Не освобождает автоматически активные permits.
- Не снимает обязательные requirements.
- Не выполняет `down()` миграций.

Отключение stateful-модуля — управляемый lifecycle: drain, проверка зависимостей, переключение режима, затем очистка при необходимости.

***

## Настройки и наследование

### Две разные задачи

В запросе смешаны две полезные, но разные возможности:

1. **Выбрать значение настройки по слоям.**
2. **Не дать более узкому слою нарушить ограничения верхнего уровня.**

Обычный `array_replace_recursive()` решает только часть первой задачи и не подходит как полная модель.

Нужны:

```text
Resolution
    + Validation
    + Enforcement
    + Explanation
```

### Источники конфигурации

| Источник | Что задаёт | Когда меняется |
|---|---|---|
| Package defaults | Безопасные стартовые значения | Релиз пакета |
| Project PHP config | Классы, обработчики, схемы, ограничения | Deploy |
| Global DB settings | Общие операционные значения | Админка/API |
| Tenant DB settings | Значения конкретного tenant | Админка/API |
| Panel defaults | Политика категории потребителя | PHP и разрешённые DB overrides |
| Entity binding | Настройки конкретной сущности | Проектный UI или общая страница |
| Process/run snapshot | Зафиксированные настройки исполнения | Создание/обновление процесса |
| Request constraints | Дополнительное сужение для запроса | Код вызова |
| Live safety state | Disable, hold, cooldown, действующие лимиты | Операционная система |

### Порядок разрешения

Предлагаемый базовый порядок:

```text
Package defaults
    ↓
Project PHP defaults
    ↓
Global DB override
    ↓
Tenant DB override
    ↓
Panel definition defaults
    ↓
Panel-level DB override
    ↓
Entity override
    ↓
Process/run snapshot
    ↓
Request-level narrowing
```

После этого отдельно применяются:

```text
PHP hard constraints
∩ tenant permissions
∩ current route membership
∩ current disable/hold state
∩ current budget constraints
```

Это означает:

- Нижний слой может выбрать профиль, если это разрешено схемой.
- Но не может вернуть в работу административно выключенный route.
- Не может выключить TLS verification.
- Не может отменить общий origin budget.
- Не может разрешить direct, если direct запрещён политикой.
- Не может выбрать чужой tenant route.

### Не всем полям нужен overwrite

| Тип настройки | Семантика |
|---|---|
| Label, описание | Последнее заданное значение |
| Выбранный профиль | Замена в пределах разрешённых профилей |
| Максимальный timeout | Значение с ограничением верхней границей |
| Разрешённые destinations | Пересечение ограничений |
| Запрещённые routes | Объединение запретов |
| Общий и процессный budgets | Одновременное применение |
| Обязательные security checks | Неотключаемые |
| Outcome handlers | Управляемый список по отдельным правилам |
| Live cooldown | Не наследуется как обычный default; применяется отдельно |

Особенно важно:

> Процессный бюджет не заменяет глобальный. Он добавляет ещё одно ограничение.

### Отсутствие, null и пустой список

Нужно различать:

```text
ключ отсутствует
    → наследовать

set(null)
    → установить null, если тип допускает

set([])
    → установить пустой список

disable
    → выключить опциональную функцию, если это разрешено

inherit
    → удалить локальный override
```

Для профиля:

```text
нет override профиля
    → наследовать профиль

профиль существует, но пуст
    → отправки запрещены

профиль содержит direct
    → direct разрешён явно
```

Текущая страница профилей уже сообщает, что пустой профиль запрещает отправку и direct выбирается явно. Это нужно сохранить и в API, и в UI. [NetworkRouteProfilesPage](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Filament/Pages/NetworkRouteProfilesPage.php).

### Типизированные patches

Предлагаемая структура хранения:

```json
{
  "schema_version": 1,
  "operations": {
    "routing.profile": {
      "op": "set",
      "value": "supplier-primary"
    },
    "retry.max_attempts": {
      "op": "set",
      "value": 3
    },
    "timeouts.total_seconds": {
      "op": "inherit"
    }
  }
}
```

Не следует разрешать произвольные JSON paths.

Каждый path обязан иметь:

```text
key
type
default
nullable
allowed scopes
validation
merge mode
editable permissions
hard bounds
sensitive flag
schema version
```

Пример описания поля:

```php
Setting::integer('retry.max_attempts')
    ->default(2)
    ->minimum(1)
    ->maximum(5)
    ->editableAt(
        PolicyScope::Panel,
        PolicyScope::Entity,
    );
```

### Зафиксированные и живые настройки

Предлагается разделить:

**Pinned:**

- Выбранная revision политики.
- Правила retry.
- Класс зарегистрированной стратегии.
- Исходный route profile reference.
- Идентификаторы обработчиков результата.
- Базовые параметры исполнения.

**Live:**

- Route enabled.
- Credential version.
- Retirement target.
- Current profile membership.
- Security revocation.
- Health epoch.
- Cooldown.
- Текущее состояние бюджетов.

Если профиль изменился после создания run, заранее зафиксированное намерение не должно позволять обходить новую административную блокировку.

Допустимые результаты несовпадения:

```text
PolicyChanged
NeedsReplan
Blocked
```

Но не молчаливое использование потенциально запрещённого старого маршрута.

В текущем `AcquisitionConfiguration` уже сохраняются версии и stage-specific route bindings; это полезная база для pinned policy references. [AcquisitionConfiguration](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Dto/Procurement/Acquisition/AcquisitionConfiguration.php).

### Объяснение результата

Нужен первоклассный API:

```php
$explanation = $policyResolver->explain($context);
```

Результат для UI:

```text
routing.profile = supplier-primary
source = entity:agelto.crawler:123
revision = 7

retry.max_attempts = 3
source = panel:agelto.crawling.detail

timeouts.total_seconds = 20
requested = 60
constraint = project.maximum_total_seconds
```

Для каждого поля:

```text
effective value
source layer
source revision
inherited or explicit
constraints applied
editable or locked
reason
```

Без такого механизма многослойные настройки быстро станут непрозрачными.

***

## Динамические панели

### Назначение панели

Панель Proxyma — **не Filament Panel**.

Она представляет зарегистрированную область конфигурации:

```text
agelto.crawling
agelto.documents
agelto.ai

другой проект:
crm.integrations
billing.providers
monitoring.checks
```

Внутри области могут быть слоты:

```text
agelto.crawling:
    listing
    detail
    status-refresh
```

Слоты — строки, зарегистрированные проектом, а не встроенный enum ядра.

### Предлагаемый builder

```php
$registry->register(
    ProxyPanel::make('agelto.crawling')
        ->label('Сбор данных')
        ->subjects([
            'agelto.crawler',
            'agelto.acquisition-profile',
        ])
        ->slots([
            'listing',
            'detail',
            'status-refresh',
        ])
        ->defaultProfile('agelto.default')
        ->allowOverrides([
            'routing.profile',
            'retry.max_attempts',
            'timeouts.total_seconds',
            'budgets.process-hourly',
            'budgets.process-daily',
        ])
        ->outcomePolicy('agelto.crawling')
        ->requireCapabilities([
            'durable-admission',
            'multi-scope-budgets',
            'owner-fencing',
        ]),
);
```

Реестр должен отвергать:

- Повторную регистрацию одного ID.
- Неизвестные subject types.
- Неизвестные setting keys.
- Ссылки на отсутствующие handlers.
- Несовместимые capabilities.
- Циклическое наследование.
- Неоднозначные slot bindings.

### Определение и экземпляр

```text
PanelDefinition
    PHP, deploy-time

PanelBinding
    DB, runtime

Subject
    принадлежит приложению
```

Пример:

```text
Definition:
    agelto.crawling

Binding:
    subject = agelto.crawler:123
    slot = listing
    override profile = eis-listing
```

### Иерархия сущностей

Ядро не должно самостоятельно угадывать:

```text
Procurement → Crawler → Registry → Platform
```

Проект передаёт упорядоченный policy path:

```php
$context = RequestContext::forPanel('agelto.crawling')
    ->slot('detail')
    ->subjects([
        $platformSubject,
        $crawlerSubject,
        $processSubject,
    ]);
```

Дополнительно panel definition задаёт допустимый порядок типов.

Если два binding одного ранга задают разные значения, предлагается возвращать `AmbiguousPolicy`, а не выбирать случайный.

В текущем `ProxyBindingResolver` неоднозначность нескольких crawler profiles уже приводит к отказу конфигурации. Эту строгость стоит сохранить, но в универсальном виде. [ProxyBindingResolver](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/ProxyBindingResolver.php).

### Регистрация без побочных эффектов

Регистрация панелей:

- Не делает запросы в БД.
- Не синхронизирует catalog.
- Не создаёт routes.
- Не проверяет внешний proxy.
- Не выполняет сетевые вызовы.
- Не зависит от текущего пользователя.

После загрузки реестра — `freeze()`.

Поздняя регистрация во время обработки запроса должна считаться ошибкой, иначе разные workers будут иметь разные наборы возможностей.

***

## Публичный API

### Три уровня использования

Предлагается три уровня, с разными гарантиями:

| API | Для чего | Гарантии |
|---|---|---|
| `RouteCatalog` / `RouteResolver` | Просмотр и предварительный подбор | Не разрешает физическую отправку |
| `ProxymaClient` | Обычное выполнение HTTP | Полный управляемый lifecycle |
| `AdmissionCoordinator` + approved transport adapter | Интеграция SDK и сторонних transport systems | Полный lifecycle при соблюдении контракта адаптера |

Не следует называть метод, возвращающий proxy URL, «безопасным управляемым исполнением».

Получив строку подключения, приложение может отправлять сколько угодно запросов мимо бюджета. Такой API допустим только как **неуправляемый экспорт**, явно отмеченный в документации и отключаемый политикой.

### Основной вызов

Предлагаемая форма:

```php
$result = $proxyma->execute(
    request: $request,
    context: $context,
);
```

Контекст:

```php
$context = RequestContext::forPanel('agelto.crawling')
    ->slot('listing')
    ->subject($crawlerSubject)
    ->owner($ownerClaim)
    ->target('agelto.eis.listing')
    ->operationId($operationId);
```

Возвращается не только response, а типизированный результат:

```text
Completed
Deferred
Blocked
Interrupted
```

Отдельно внутри `Completed`:

```text
HTTP response
+ technical classification
+ attempt references
+ policy reference
```

**HTTP 404 может быть успешно завершённым транспортным обменом**, но не успешным бизнес-результатом.

### Минимальные контракты

```php
namespace AxiomaSoft\Proxyma\Contracts;

interface EffectivePolicyResolver
{
    public function resolve(
        RequestContext $context,
    ): PolicyResolution;
}

interface RouteSelector
{
    public function select(
        RouteSelectionInput $input,
    ): RouteSelectionResult;
}

interface OutcomeClassifier
{
    public function classify(
        AttemptFacts $facts,
    ): Classification;
}

interface OutcomeDecisionPolicy
{
    public function decide(
        OutcomeContext $context,
    ): OutcomeDecision;
}

interface SecretResolver
{
    public function resolve(
        SecretReference $reference,
    ): ResolvedSecret;
}

interface Authorization
{
    public function authorize(
        ActorRef $actor,
        Operation $operation,
        ResourceRef $resource,
    ): void;
}
```

Типы в сигнатурах должны быть отдельными DTO/value objects, не Eloquent-моделями.

### Основные value objects

| Тип | Обязательные свойства |
|---|---|
| `RouteId` | Валидная стабильная идентичность route |
| `TargetId` | Идентичность назначения |
| `Origin` | Нормализованные scheme, host, port |
| `PanelId` | Зарегистрированный ключ |
| `SubjectRef` | Tenant + type + opaque ID |
| `PolicyRevision` | Неизменяемая версия политики |
| `PolicyFingerprint` | Хеш канонического исполняемого представления |
| `LogicalRequestId` | Один логический вызов |
| `PhysicalAttemptId` | Одна физическая попытка |
| `OwnerClaim` | Ссылка на владельца, token, generation, deadline |
| `RetryAt` | Абсолютный момент повторного допуска |
| `SecretReference` | Ссылка, а не открытый пароль |
| `AttemptFacts` | Неизменяемые технические факты |

Для canonical hashing необходимо сортировать map keys, но **не сортировать списки, где порядок значим**: например, приоритеты маршрутов или обработчиков.

***

## Допуск и исполнение

### Обязательная последовательность

Предлагается закрепить следующий lifecycle:

```text
Build request
    ↓
Resolve policy
    ↓
Validate target and URL
    ↓
Resolve DNS / credentials outside locks
    ↓
Select candidate route
    ↓
Register demand
    ↓
Reserve resources
    ↓
Revalidate immediately before send
    ↓
Commit send authorization
    ↓
Physical sendOnce
    ↓
Persist outcome
    ↓
Apply health and accounting
    ↓
Decide retry / defer / return
```

На каждом новом retry и redirect создаётся новая physical attempt.

Текущий `OutboundExecutor` уже создаёт отдельные физические планы и авторизует каждый переход, а `NetworkPermitRepository` разделяет reservation и actual-send claim. Это нужно перенести как гарантию, а не заменить одним rate-limit вызовом перед циклом retry. [OutboundExecutor](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/OutboundExecutor.php), [NetworkPermitRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkPermitRepository.php).

### Что проверяется перед send

В последней транзакции:

1. Владелец ещё действителен.
2. Не изменился fencing token/generation.
3. Процесс или операция не остановлены.
4. Binding не отозван.
5. Route существует и включён.
6. Route входит в разрешённый профиль.
7. Credentials соответствуют разрешённой версии.
8. Target не retired.
9. Destination соответствует policy.
10. Health допускает отправку.
11. Применимы все обязательные budget scopes.
12. Лимиты позволяют отправку.
13. Permit не использован и не истёк.
14. Request fingerprint совпадает.
15. Timeout помещается в deadline.
16. Создан durable authorization fact.

### Отделение владельца

Предлагаемый контракт:

```php
interface OwnerAuthority
{
    public function snapshot(
        OwnerClaim $claim,
    ): OwnerSnapshot;

    public function lockAndValidate(
        OwnerClaim $claim,
        OwnerSnapshot $snapshot,
    ): OwnerValidation;
}
```

Гарантия контракта:

- `lockAndValidate()` выполняется внутри общей транзакции.
- Все project-owner locks берутся **до** package network locks.
- Возвращаемое состояние не содержит Eloquent-модели Agelto.
- Внутри метода нет сетевых вызовов.
- Метод не вызывает admission Proxyma рекурсивно.

В Agelto:

```text
AgeltoOwnerAuthority
    ├── проверяет work
    ├── проверяет attempt
    ├── проверяет generation/token
    ├── проверяет controls
    ├── проверяет subject authority
    └── возвращает OwnerValidation
```

В простом стороннем проекте:

```text
StandaloneOperationAuthority
    └── проверяет ограниченную Proxyma operation
```

### Одна БД и внешние системы

Строгая композиция блокировок возможна, когда Agelto owner authority и Proxyma admission находятся в одной PostgreSQL-транзакции.

Если owner хранится в сторонней системе, нельзя обещать ту же атомарность. Для такого режима нужны отдельные правила:

- Внешний lease или подписанный token.
- Короткий срок действия.
- Явная граница консистентности.
- Отдельная capability, например `external-owner-lease`.
- Запрет использовать этот режим там, где проект требует `transactional-owner-fencing`.

### Транзакция и сетевой вызов

Нельзя держать SQL-транзакцию открытой на всё время HTTP-запроса.

Но нельзя и обещать атомарность:

```text
COMMIT в PostgreSQL
+
получение запроса удалённым сервером
```

Поэтому после durable authorization возможен crash до socket write. А после отправки возможен crash до записи результата.

Нужны отдельные состояния:

```text
reserved
authorized
completed
cancelled_before_send
expired_before_send
unknown_after_authorization
```

`unknown_after_authorization` нельзя автоматически трактовать как «точно не отправлено».

Для неидемпотентных запросов это определяет запрет автоматического retry без дополнительного контракта.

### Разрешённый транспорт

Предлагаемый интерфейс:

```php
interface PhysicalTransport
{
    public function sendOnce(
        AuthorizedWireRequest $request,
        CancellationToken $cancellation,
    ): AttemptFacts;
}
```

`AuthorizedWireRequest` должен содержать уже зафиксированные:

```text
attempt ID
method
URL
destination policy result
selected route
credential version
body hash
timeout bounds
authorization reference
```

После авторизации нельзя менять URL, proxy, method или body.

Транспорт:

- Не выполняет собственные retry.
- Не следует redirect автоматически.
- Не выбирает другой proxy.
- Не использует скрытый системный proxy из окружения.
- Возвращает типизированные технические факты.
- Соблюдает deadline и ограничения размера ответа.

Сторонний SDK считается совместимым только после подтверждения этих свойств.

***

## Бюджеты и квоты

### Разделить виды ограничений

Под словом «бюджет» нужно поддерживать несколько независимых механизмов:

| Ограничение | Пример | Назначение |
|---|---|---|
| Rate | Не чаще заданной частоты | Сглаживание потока |
| Burst | Ограниченный краткий всплеск | Контроль пиков |
| Concurrency | Не более N активных попыток | Ограничение параллельности |
| Fixed-window quota | Не более N за календарный час | Жёсткая квота окна |
| Rolling-window quota | Не более N за последние 60 минут | Скользящая квота |
| Cooldown | Не отправлять до момента T | Реакция на ограничение/ошибку |
| Traffic share | Доля доступной ёмкости для класса | Распределение между назначениями |

В текущем Agelto есть GCRA-поля `tat`, `send_tat`, `emission_interval_us`, burst, max-inflight и cooldown, а также отдельная квота status-refresh. Это не следует представлять как уже готовую универсальную систему произвольных часовых и суточных квот на каждую сущность. [Схема бюджетов](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php), [резервирование](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkPermitRepository.php), [настройки](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/config/outbound.php).

### Что именно считать

Предлагаемый default:

> Один budget unit — одно разрешение на отдельную физическую HTTP-попытку.

Тогда:

- Retry расходует новую единицу.
- Redirect расходует новую единицу.
- Probe расходует единицу.
- Diagnostic расходует единицу.
- Повторная регистрация того же physical attempt не расходует вторую единицу.
- Возврат уже сохранённого результата не расходует единицу.

«Количество TCP-соединений» — другая метрика: keep-alive и multiplexing делают её отличной от количества запросов.

Если нужны именно connections, следует добавить отдельный transport-level limiter, а не переименовать request counter.

### Совместное применение scopes

Для одного запроса могут одновременно действовать:

```text
system
tenant
panel
entity
process
origin
route
route-origin
egress-origin
traffic-class
probe
```

Пример проектной конфигурации:

```php
BudgetSet::make()
    ->rate(
        id: 'origin-rate',
        scope: BudgetScope::Origin,
        limit: 15,
        period: BudgetPeriod::Minute,
        burst: 1,
    )
    ->concurrency(
        id: 'process-concurrency',
        scope: BudgetScope::Process,
        limit: 2,
    )
    ->fixedWindow(
        id: 'process-hourly',
        scope: BudgetScope::Process,
        limit: 600,
        period: BudgetPeriod::Hour,
        timezone: 'UTC',
    )
    ->fixedWindow(
        id: 'process-daily',
        scope: BudgetScope::Process,
        limit: 5000,
        period: BudgetPeriod::Day,
        timezone: 'UTC',
    );
```

Значения здесь — пример новой конфигурации, не измеренные требования источника.

Отправка разрешена, только если проходят **все** ограничения.

### Семантика часа и дня

Нужно явно различать:

```text
600 за календарный час UTC
```

и:

```text
600 за последние 60 минут
```

Это разные политики.

Для первой версии рекомендую:

- GCRA rate limiter.
- Concurrency limiter.
- Fixed calendar windows.
- UTC по умолчанию.
- Rolling window — отдельный backend позднее.

Если разрешаются локальные часовые пояса, нужно определить поведение переходов DST. Нельзя считать любой календарный день равным 24 часам безоговорочно.

### Резервирование и списание

Для quota:

```text
available = limit - consumed - reserved
```

На reserve:

```text
reserved += cost
```

На durable send authorization:

```text
reserved -= cost
consumed += cost
```

На подтверждённую отмену до авторизации:

```text
reserved -= cost
```

После авторизации и неопределённого исхода:

```text
consumed сохраняется
```

Нельзя возвращать квоту только потому, что приложение не получило HTTP response.

Для GCRA предлагается сохранить консервативную семантику: отмена reservation не должна произвольно перематывать общий TAT назад.

### Атомарность нескольких budgets

Последовательность:

```text
Определить полный scope set
    ↓
Материализовать отсутствующие строки
    ↓
Упорядочить lock keys
    ↓
Заблокировать все строки
    ↓
Проверить все ограничения
    ↓
Применить все изменения
```

Если один budget не проходит, остальные не должны оставаться частично списанными.

Нельзя делать:

```text
global limiter → success
process limiter → success
origin limiter → fail
```

с независимыми фиксациями без общей reservation protocol.

### Изменение действующего лимита

Изменение с `1000/day` на `500/day`:

- Не обнуляет consumed.
- Не создаёт новое окно.
- Не снимает cooldown.
- Может временно полностью закрыть новые отправки.
- Не отменяет уже физически начатые запросы.
- Инвалидирует несовместимые ещё не использованные permits.

В текущей схеме уже подчёркнуто, что budget state нельзя перематывать catalog sync или override. Это нужно сохранить как отдельный тестируемый invariant. [Сетевая миграция](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php).

### Egress identity

Отдельно нужно предусмотреть:

- Несколько proxy endpoints с одним внешним IP.
- Direct workers за общей NAT.
- Rotating proxy, у которого egress заранее неизвестен.
- Провайдерские группы с общей квотой.

`route_id` не доказывает независимость выхода.

Для неизвестного egress:

```text
egress identity = unknown/provider-group
```

Политика должна выбирать консервативное агрегирование, а не считать каждый endpoint независимым.

Текущая схема уже различает `egress_id` и `independence_group`. Не следует сливать эти два понятия. [Схема routes](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php).

***

## Ошибки и HTTP-политики

### Три независимых слоя

Предлагается:

```text
Attempt facts
    ↓
Technical classification
    ↓
Health attribution
    ↓
Caller outcome decision
```

Например:

```text
HTTP 404
    transport: completed
    route health: not a proxy failure
    application: entity missing
```

Или:

```text
HTTP 407
    transport: proxy authentication rejected
    route credentials: held
    application: another permitted route may be considered
```

**Бизнес-решение не должно переписывать технический факт.**

### Текущее поведение

Сейчас `RequestClassifier` отдельно обрабатывает proxy auth/407, proxy connect, connect/timeout/truncated, а 403 и 429 исключает из обычной health-атрибуции. При этом `HealthRepository` дополнительно применяет rate-limit/cooldown логику к 429 и к некоторым 403. [RequestClassifier](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/RequestClassifier.php), [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

В новом API это должны быть явно разные результаты, а не один флаг `failed`.

### Политики на сущность

Предлагаемый registry:

```php
return [
    'outcome_policies' => [
        'default' => DefaultOutcomePolicy::class,
        'agelto.listing' => ListingOutcomePolicy::class,
        'agelto.detail' => DetailOutcomePolicy::class,
        'agelto.download' => DownloadOutcomePolicy::class,
    ],
];
```

В DB:

```json
{
  "outcome_policy": "agelto.detail"
}
```

Не:

```json
{
  "class": "\\App\\Anything",
  "method": "execute",
  "code": "..."
}
```

### Таблица решений

| Факт | Возможное стандартное решение | Что может менять проект |
|---|---|---|
| Proxy auth / 407 | Credential hold | Разрешить выбор другого route |
| Proxy connect failure | Route failure | Допустимость retry |
| Origin timeout | Route-target/target evidence | Retry bounds |
| 404 | Вернуть completed response | `not_found`, terminal, ignore |
| 403 | Контекстная policy | Access denied или WAF cooldown |
| 429 | Cooldown по доверенной policy | Scope и bounded fallback |
| 502/503/504 | Retry candidate | Число попыток, delay, route preference |
| TLS verification failure | Block | Не разрешать UI отключать проверку |
| Некорректный ответ парсера | Вне transport health | Обрабатывает проект |

### Типизированные решения

```text
ReturnResponse
RetrySameRoute
RetryAnotherRoute
DeferUntil
Block
RequireOperatorReview
```

Каждое решение содержит:

```text
reason code
policy reference
bounded retry parameters
safe diagnostic details
```

Правило `RetryAnotherRoute` не может:

- Добавить route вне профиля.
- Обойти глобальную квоту.
- Сбросить cooldown.
- Нарушить max distinct routes.
- Повторить неидемпотентный запрос без разрешения.

### Приоритет правил

Предлагается:

```text
Неотменяемые security/ownership запреты
    ↓
Точное проектное правило
    ↓
Правило панели
    ↓
Стандартная policy
```

Если несколько правил одного приоритета дают несовместимые terminal decisions — ошибка конфигурации.

Нужно избегать неявного «последний зарегистрированный обработчик победил».

***

## Health и восстановление

### Что переносить

Переносить как универсальное:

- Route health.
- Route-target health.
- Target availability.
- Circuit states.
- Credential hold.
- Epoch fencing.
- Controlled probes.
- Observation deduplication.
- Backoff и stable recovery.

`HealthReducer` уже является чистым reducer, а repository проверяет epochs и ownership перед применением. Это хорошая внутренняя граница для извлечения. [HealthReducer](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/HealthReducer.php), [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

### Что не переносить

Не включать в transport health:

- Проверку полного разбора карточки.
- Версии parser contract.
- Подсчёт разных проблемных procurement inputs.
- Решение о completeness результата.
- Публикацию частичных предметных проекций.

Такие проверки подключаются в Agelto через admission guard и собственные events.

В текущем runbook сетевое состояние и состояние адаптера уже описаны как независимые факты. [Runbook](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/docs/dev/runbooks/process-test-rig.md).

### Поздние результаты

Нужна следующая семантика:

```text
Поздний response
    → сохраняется как факт

Epoch уже изменился
    → старый response не восстанавливает новый health state
```

При этом отдельно надо определить влияние позднего 429 на актуальный cooldown. Это не тот же вопрос, что применение health reducer.

В существующем `HealthRepository` quota и stale-health обработка разделены внутри одной процедуры. Перед переносом это место требует отдельных сценариев на credential rotation, target changes и поздние rate-limit ответы. [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

### Владелец probe

Probe должен иметь:

```text
token
exact scope set
epoch set
lease/deadline
purpose
```

Нельзя считать любой успешный запрос подтверждением восстановления всех circuits.

Credential hold снимается только по разрешённому событию: например, новой credential version или явной audited operation, а не просто по таймеру.

***

## Расширения и pipeline

### Не один универсальный hook

Рекомендую разные контракты для разных задач:

| Расширение | Назначение |
|---|---|
| `PolicyContributor` | Добавить значения/ограничения политики |
| `RouteFilter` | Сузить кандидатов |
| `RouteSelectionStrategy` | Выбрать из разрешённых кандидатов |
| `AdmissionGuard` | Дополнительный запрет перед отправкой |
| `BudgetScopeProvider` | Добавить budget scopes |
| `OutcomeClassifier` | Классифицировать факты |
| `OutcomeDecisionPolicy` | Выбрать дальнейшее действие |
| `ObservationConsumer` | Обработать сохранённый факт |
| `SecretResolver` | Получить credentials |
| `TransportAdapter` | Реализовать один physical send |
| `SubjectResolver` | Найти отображение внешней сущности |
| `PanelSchemaContributor` | Добавить разрешённые настройки панели |

Так проще объяснить дешёвой модели, **что разрешено менять**, чем дать ей один `handle($context, $next)` на весь lifecycle.

### Фазы исполнения

```text
prepare
resolve-policy
validate-request
filter-routes
select-route
admit
authorize-send
send
record-outcome
decide-next-step
publish-events
```

Для каждой фазы указывается:

```text
input type
output type
mutable fields
forbidden operations
transaction context
exception semantics
ordering
```

### Неотменяемый каркас

Расширения не должны иметь возможности пропустить:

```text
owner validation
destination validation
profile membership check
required budgets
send authorization
attempt recording
```

Поэтому общий Laravel Pipeline допустим для отдельных фаз, но не как полностью заменяемая оболочка над всем executor.

Плохой контракт:

```php
public function handle(
    mixed $request,
    \Closure $next,
): mixed;
```

Предпочтительнее:

```php
interface RouteFilter
{
    public function filter(
        RouteCandidates $candidates,
        RouteFilterContext $context,
    ): RouteCandidates;
}
```

Результат filter обязан быть подмножеством входных candidates.

### Регистрация плагина

Предлагаемый API:

```php
interface ProxymaExtension
{
    public function id(): string;

    public function register(
        ExtensionRegistry $registry,
    ): void;
}
```

Пример:

```php
final class AgeltoProxymaExtension implements ProxymaExtension
{
    public function id(): string
    {
        return 'agelto';
    }

    public function register(
        ExtensionRegistry $registry,
    ): void {
        $registry->ownerAuthority(
            'agelto.process',
            AgeltoOwnerAuthority::class,
        );

        $registry->outcomePolicy(
            'agelto.detail',
            DetailOutcomePolicy::class,
        );

        $registry->admissionGuard(
            'agelto.adapter-health',
            AdapterHealthAdmissionGuard::class,
        );
    }
}
```

Реестр freeze-ится после bootstrap.

### Порядок расширений

У расширения могут быть:

```text
priority
before
after
requires capabilities
provides capabilities
```

Правила:

- ID уникален.
- Граф порядка ацикличен.
- Неизвестный `before/after` — ошибка, если не помечен optional.
- Один и тот же вход даёт одинаковый порядок.
- Priority не позволяет вклиниться внутрь неотменяемой атомарной операции.
- DB-зависимые extensions объявляют lock requirements заранее.

### Подмена классов

В PHP-конфигурации:

```php
return [
    'bindings' => [
        RouteSelectionStrategy::class
            => WeightedRouteSelection::class,

        SecretResolver::class
            => ProjectSecretResolver::class,

        OutcomeDecisionPolicy::class
            => ProjectOutcomePolicy::class,
    ],
];
```

Но не нужно обещать подмену каждого внутреннего класса.

Достаточно заменяемых стабильных контрактов. Критические coordinators и invariant-enforcing components могут оставаться `final`.

### События и надёжность

Предлагаемые события:

```text
RouteCreated
RouteDisabled
CredentialsRotated
ProfileChanged
PolicyBindingUpdated
DemandDeferred
SendAuthorized
AttemptCompleted
OutcomeRecorded
CircuitOpened
ProbeCompleted
BudgetExhausted
```

Разделить:

- Внутрипроцессные hooks.
- События после commit.
- Durable integration events через outbox.

Outbox даёт повторную доставку, поэтому:

```text
event_id
event_type
schema_version
aggregate_id
aggregate_version
occurred_at
payload
```

Consumers должны быть идемпотентными.

Нельзя обещать «обработчик будет вызван ровно один раз» только потому, что используется Laravel event.

***

## Filament-пакет

### Общая структура

Предлагаемые страницы:

| Экран | Ответственность |
|---|---|
| Proxy endpoints | Справочник адресов и secret references |
| Routes | Direct/proxy identities и административное состояние |
| Route profiles | Членство и стратегия выбора |
| Proxy panels | Зарегистрированные области настройки |
| Panel binding editor | Настройки выбранной сущности/слота |
| Policy inspector | Эффективные значения и источники |
| Budgets | Лимиты, текущая доступность, next eligible time |
| Health | Состояния, причины, probe/reset |
| Attempts | Ограниченный технический журнал |
| Audit | История административных изменений |

Текущие глобальная форма прокси и страница route profiles уже дают исходный материал, но зависят от проектных enums, user и permission system. [ProxyForm](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Filament/Resources/Procurement/Crawler/Proxies/Schemas/ProxyForm.php), [NetworkRouteProfilesPage](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Filament/Pages/NetworkRouteProfilesPage.php).

### Подключение плагина

Предлагаемый API:

```php
$panel->plugin(
    ProxymaPlugin::make()
        ->catalog()
        ->profiles()
        ->panels()
        ->budgets()
        ->health()
        ->audit(),
);
```

Filament поддерживает подключаемые panel plugins с `getId()`, `register()` и `boot()`, включая регистрацию resources и pages. Это соответствует отдельному UI-пакету, а не зависимости ядра от Filament. [Документация Filament](https://filamentphp.com/docs/5.x/plugins/panel-plugins.md).

### Экран зарегистрированных панелей

```text
Панель: Сбор данных
Ключ: agelto.crawling
Источник: AgeltoProxymaExtension
Слоты: listing, detail, status-refresh
Обязательные capabilities: admission, budgets, health
```

После выбора сущности:

```text
Crawler #123

Профиль:
    inherited: agelto.default
    override: eis-listing

Retry:
    inherited: 2
    override: 3

Часовая квота:
    inherited: отсутствует
    override: 600
```

Для каждого поля UI показывает:

- Текущее значение.
- Источник.
- Локальный override.
- Кнопку «Наследовать».
- Ограничения.
- Причину недоступности редактирования.
- Версию, с которой сохраняется изменение.

### Встраиваемая секция

Помимо общей страницы нужен reusable component:

```php
ProxymaSettingsSection::make()
    ->panel('agelto.crawling')
    ->subjectResolver(CrawlerSubjectResolver::class);
```

Или action:

```php
ConfigureProxymaAction::make()
    ->panel('agelto.crawling')
    ->subjectResolver(ProcessSubjectResolver::class);
```

Тогда Agelto сохраняет собственный редактор процессов, но не дублирует логику сетевых настроек.

### Где проходит граница формы

Общая секция знает:

```text
panel
subject
slot
allowed setting fields
policy revision
```

Она не знает:

```text
расписание acquisition
период выборки закупок
cursor
режим fresh/archive/delta
parser completeness
```

Эти поля остаются рядом, в проектной форме Agelto.

### Сохранение изменений

Filament не пишет прямо в таблицы настроек:

```text
UI state
    ↓
typed command
    ↓
authorization
    ↓
schema validation
    ↓
expected revision check
    ↓
transaction
    ↓
audit
    ↓
cache invalidation/event
```

При конфликте версий нельзя автоматически перезаписывать чужие изменения.

### Права доступа

Пакет не зависит от `App\Models\User` и конкретного permission enum.

Пример операций:

```text
proxyma.catalog.view
proxyma.catalog.update
proxyma.credentials.rotate
proxyma.profiles.update
proxyma.bindings.update
proxyma.budgets.update
proxyma.health.reset
proxyma.audit.view
```

Проектный authorization adapter сопоставляет их со своей системой прав.

Скрыть кнопку недостаточно: permission и tenant scope проверяются повторно в application command.

***

## PHP-конфиг и база

### Что только в PHP

- Реализации контрактов.
- Реестр плагинов.
- Subject types.
- Panel definitions.
- Outcome policy classes.
- Transport adapters.
- Secret backends.
- Допустимые setting keys.
- Верхние security bounds.
- Возможность direct.
- Разрешённые protocols.
- Правила tenant isolation.
- Требуемые capabilities.

### Что допустимо в БД

- Proxy endpoints.
- Route/profile membership.
- Binding overrides.
- Выбранный зарегистрированный handler ID.
- Лимиты в разрешённых пределах.
- Timeouts в разрешённых пределах.
- Включение/выключение маршрута.
- Credential references.
- Health state.
- Runtime counters.
- Audit.

### Чего не хранить в БД

- PHP-код.
- Сериализованные closures.
- Произвольные FQCN для исполнения.
- Произвольные имена container bindings.
- Произвольные SQL expressions.
- Флаги обхода обязательной авторизации.
- Открытые credentials в общем JSON.

### Laravel config cache

Конфигурация должна оставаться сериализуемой.

Динамические builder-объекты, closures и проектные модели не нужно складывать в `config/proxyma.php`. В конфиге — class strings и простые значения; реестр строится provider-ами.

Также нельзя принимать стандартный `mergeConfigFrom()` за полноценное рекурсивное разрешение настроек: Laravel документирует merge только первого уровня. Для Proxyma нужен собственный schema-aware resolver. [Laravel package configuration](https://laravel.com/framework/docs/packages).

### Кеш

Ключ effective policy должен учитывать:

```text
tenant
panel
slot
ordered subject chain
policy head/revisions
registry fingerprint
code policy version
```

Но кешированное разрешение не заменяет send-time revalidation.

В долгоживущих workers нельзя держать mutable singleton с текущим tenant, subject или resolved credentials.

***

## Безопасность

### Credentials

Предлагается хранить:

```text
backend
reference
version
```

Например:

```text
env / EIS_PROXY_PRIMARY / version 8
vault / secret/proxy/provider-a / version 12
```

Правила:

- Пароль не показывается в таблице.
- Secret resolver не пишет значение в exceptions.
- DTO с секретом не сериализуется в очередь.
- Audit содержит reference и версию, но не secret.
- Ротация увеличивает credential version.
- Смена значения за прежним reference также требует ротации версии.
- Старый permit с прежней версией не отправляется.

В текущем UI уже используется `secret_ref`, а runbook описывает увеличение credential version при повторном применении секрета. Эту модель стоит сохранить и формализовать. [ProxyForm](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Filament/Resources/Procurement/Crawler/Proxies/Schemas/ProxyForm.php), [runbook](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/docs/dev/runbooks/process-test-rig.md).

### URL и DNS

Для каждого запроса и redirect:

- Разрешённый scheme.
- Проверенный host.
- Разрешённый port.
- Запрет userinfo в destination URL.
- Ограничение адресных диапазонов.
- Нормализация IPv4/IPv6.
- Ограничение DNS resolution time.
- Проверка всех допустимых адресов.
- Запрет HTTPS downgrade.
- Очистка чувствительных headers при cross-origin redirect.
- Ограничение числа hops.

Отдельная оговорка: если destination DNS разрешает сам proxy, локальная проверка адреса ещё не доказывает, что proxy подключится именно к этому IP.

Поддержку HTTP forward, CONNECT и SOCKS нужно описывать отдельной capability matrix. Нельзя обещать одинаковое DNS pinning для всех режимов.

### Ответы

Обязательны:

```text
max response bytes
max decoded bytes
max decompression expansion
bounded headers
total timeout
connect timeout
bounded redirects
bounded attempts
```

Body artifact cleanup должен иметь владельца. Нельзя удалять spool, уже переданный вызывающему коду.

Текущий executor уже различает ownership binary artifact и очищает не переданные вызывающему коду артефакты. Это поведение требует переноса тестами. [OutboundExecutor](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/OutboundExecutor.php).

### Логирование

По умолчанию не логировать:

- Полный URL с query.
- Authorization headers.
- Proxy password.
- Raw transport exception.
- Request body.
- Неограниченный response body.

Разрешать:

```text
attempt ID
route ID
target ID
reason code
HTTP status
duration
byte counts
safe transport metrics
policy revision
```

Открытые диагностические артефакты должны иметь отдельную retention и authorization policy.

***

## Блокировки и конкурентность

### Единый порядок

Предлагаемый общий порядок:

```text
Project owner/control locks
    ↓
Policy authority rows
    ↓
Route profile
    ↓
Route
    ↓
Route health
    ↓
Route-target health
    ↓
Target
    ↓
Target health
    ↓
Budget scopes in canonical order
    ↓
Demand
    ↓
Permit
    ↓
Attempt / accounting records
```

Точный порядок нужно закрепить одним документом и повторить в контрактных тестах. Текущий `NetworkAuthorityRepository` уже документирует порядок network-tier, а health completion согласует свой subset с grant/send. [NetworkAuthorityRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkAuthorityRepository.php), [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

Расширение не может взять «раннюю» project lock после budget lock.

### Время

Предлагается:

- База — authority для quota windows и persisted timestamps.
- Monotonic clock — локальное ожидание и timeout loops.
- Не использовать PHP wall clock как независимое доказательство допуска.
- Не спать внутри открытой транзакции.
- После ожидания повторно проверять admission.

В текущем `ScheduledAttemptGate` ожидание рассчитывается от DB-time и выполняется короткими интервалами с повторными проверками. [ScheduledAttemptGate](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/ScheduledAttemptGate.php).

### Concurrency не равна числу сокетов

`max_inflight` следует определить как:

> Максимальное число действующих контролируемых permits/попыток по scope.

Для строгого ограничения реально активных удалённых соединений дополнительно нужны гарантированные transport timeouts и корректный reaping.

Истечение lease не является доказательством мгновенной остановки удалённого сервера.

### Deadlocks и повторы транзакций

Можно повторять безопасную SQL-транзакцию при transient failure.

Нельзя при таком retry:

- Повторно отправлять HTTP.
- Повторно публиковать неидемпотентный внешний event.
- Повторно списывать budget.
- Менять physical attempt ID.

Идентичности должны создаваться вне повторяемого transaction callback либо быть детерминированными.

***

## Интеграция с Agelto

### Предлагаемый project adapter

```text
app/Infrastructure/Proxyma/
├── AgeltoProxymaServiceProvider.php
├── AgeltoProxymaExtension.php
├── Owners/
│   └── AgeltoOwnerAuthority.php
├── Subjects/
│   ├── CrawlerSubjectResolver.php
│   ├── AcquisitionProfileSubjectResolver.php
│   └── ProcessSubjectResolver.php
├── Panels/
│   ├── CrawlingPanel.php
│   ├── DocumentsPanel.php
│   └── AiPanel.php
├── Policies/
│   ├── ListingOutcomePolicy.php
│   ├── DetailOutcomePolicy.php
│   └── DownloadOutcomePolicy.php
├── Guards/
│   └── AdapterHealthAdmissionGuard.php
├── Authorization/
│   └── AgeltoProxymaAuthorization.php
└── Filament/
    └── AgeltoSubjectPresentation.php
```

Это предлагаемые новые пути, не существующая структура проекта.

### Стадии процессов

В Agelto остаётся mapping:

```text
StageCode::Listing
    → panel agelto.crawling / slot listing

StageCode::Detail
    → panel agelto.crawling / slot detail

StageCode::StatusRefresh
    → panel agelto.crawling / slot status-refresh
```

Proxyma не импортирует `StageCode`.

### Финансовый LLM-контур

В текущем executor существует специальная операция `reserveProviderSend`, предназначенная для финансового владельца. Её нельзя переносить в универсальный proxy API вместе с финансовыми предположениями. [OutboundExecutor](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Network/OutboundExecutor.php).

Целевая граница:

```text
Agelto financial owner
    ↓
проверка денег/paid-operation state
    ↓
Proxyma network admission
    ↓
совместный send coordinator в Agelto
```

Если финансовая и сетевая authority должны проверяться атомарно, композиция выполняется в Agelto в заранее определённом lock order.

Proxyma не должна становиться системой финансового биллинга.

### Синхронизация каталогов

Не переносить `platform:sync-catalog` целиком.

Разделить:

```text
Agelto:
    знает площадки и capabilities
    строит desired target definitions

Proxyma:
    принимает универсальные target/route definitions
    валидирует
    применяет versioned catalog commands
```

Catalog sync:

- Явная команда.
- Не выполняется при package boot.
- Не сбрасывает operational overrides.
- Не перематывает budgets.
- Не снимает health hold.
- Показывает diff до применения.

Текущий outbound config уже различает defaults при создании authority и последующие audited overrides. [outbound.php](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/config/outbound.php).

***

## Миграции и перенос данных

### Почему нельзя перенести файл

Текущая сетевая миграция:

- Создаёт универсальные network tables.
- Ссылается на `prc_platforms`.
- Добавляет поля к `prc_process_attempts`.
- Связывает demands с process work/attempt.
- Связывает operations с controls.
- Добавляет route FK в proxies.
- Добавляет profile FK в crawlers.

Поэтому она не может стать миграцией независимого пакета без разделения. [Исходная миграция](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/database/migrations/2026_10_25_100000_create_prc_process_network_tables.php).

### Разделение схемы

**В Proxyma:**

```text
routes
proxy endpoints
profiles
targets
policy bindings
network operations
demands
permits
attempts
budgets
health
audit
```

**В Agelto:**

```text
platform → target mapping
crawler → binding mapping
process owner linkage
adapter health
financial operation linkage
project controls
```

В package-owned таблицах не должно быть FK на `prc_process_work`, `prc_platforms` или `users`.

При необходимости Agelto создаёт собственную link-таблицу с FK в обе стороны.

### Установка миграций

Предлагается:

- Основные миграции загружаются provider-ом.
- Модульные пути подключаются явно.
- Не использовать одновременно auto-load и публикацию тех же migrations.
- Не выполнять миграции на bootstrap.
- Не создавать случайно часть схемы по текущему запросу пользователя.
- После подключения нового модуля делать явный `migrate`.
- Doctor проверяет наличие schema capabilities.

Laravel предоставляет package service providers и механизмы регистрации/публикации миграций; конкретную стратегию пакет должен выбрать одну и документировать. [Laravel packages](https://laravel.com/framework/docs/packages), [ServiceProvider API](https://api.laravel.com/docs/13.x/Illuminate/Support/ServiceProvider.html).

### Не переписывать историю

Для существующих инсталляций нельзя просто удалить старую миграцию из Agelto и положить похожую в пакет.

Нужны:

```text
Старые исторические миграции
    сохраняются

Новые package migrations
    создают целевую схему

Project transfer migrations/commands
    переносят и связывают данные

Cleanup
    только после удаления последнего reader
```

Для fresh install должна проверяться итоговая схема полного набора migrations, а не только наличие новых таблиц.

### Рекомендуемый первый cutover

Для первого извлечения рекомендую **короткий drain-based cutover**, а не сложный dual-write.

Порядок:

1. Установить Proxyma и создать новую схему.
2. Настроить adapters и policies без переключения отправок.
3. Перенести каталог и bindings.
4. Сверить profiles, route membership и credentials versions.
5. Остановить выдачу новых network demands старым контуром.
6. Дождаться завершения активных отправок в пределах timeout.
7. Убедиться, что не осталось живых reserved/sent permits.
8. Перенести health, cooldown и budget state.
9. Зафиксировать mapping identities.
10. Переключить единственный authoritative path.
11. Запустить smoke и concurrency checks.
12. Возобновить процессы.

**Два контура не должны одновременно считать себя единственным владельцем одной квоты.**

### Что переносить без обнуления

- Route IDs, если совместимы.
- Profile IDs.
- Credential versions.
- Target mapping.
- Health state и epochs.
- Holds.
- Cooldown.
- GCRA TAT/send-TAT.
- Остатки действующих quota windows.
- Policy revisions.
- Исторические attempt references, если меняются IDs.

Для старого integer `Proxy.id` и новой endpoint identity нужен явный mapping, если идентификатор меняется.

### Исторические наблюдения

Варианты:

| Подход | Плюсы | Минусы |
|---|---|---|
| Полный перенос истории | Один журнал | Сложнее миграция и проверки |
| История остаётся в Agelto | Проще cutover | Нужен объединённый read adapter |
| Перенос только активного окна | Меньше данных | Нужно строго определить reducer window |

Для первой версии разумно оставить старые факты в Agelto read-only и переносить только необходимое operational state. Но если health reducer использует текущую выборку наблюдений, её нужно либо перенести, либо заменить доказанно эквивалентным state snapshot.

### Rollback

Rollback — не просто возврат старого кода.

После новых отправок:

- Появился новый consumed budget.
- Изменились cooldowns.
- Могли измениться credentials versions.
- Накопились новые health observations.

Поэтому rollback должен включать drain и обратный перенос действующего state либо явно запрещаться после точки невозврата.

План rollback необходимо проверить до production cutover.

***

## Проверки перед извлечением

Это не подтверждённые дефекты, а конкретные места для обязательной проверки.

### Выбор маршрута

Сейчас selector читает health и budget state для кандидатов и при некоторых стратегиях дополнительно смотрит историю отправок. На больших пулах это следует проверить на количество SQL-запросов и возможный N+1. [RouteSelector](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Services/Procurement/Proxy/RouteSelector.php).

Целевая форма:

```text
одна batch snapshot query
    ↓
чистая стратегия выбора
    ↓
финальная authoritative revalidation
```

Кеш snapshot может оптимизировать подбор, но не отменять последнюю проверку.

### Материализация scope

В текущем authority repository присутствует создание egress budget при обнаружении общего выхода. Нужно проверить гонки создания и соответствие lock order. Для Proxyma предпочтительно заранее материализовать обязательные scopes либо иметь один формально описанный безопасный lazy-materialization protocol. [NetworkAuthorityRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/NetworkAuthorityRepository.php).

### Область cooldown

Нужно проверить:

- Что считается другим здоровым route.
- Должен ли этот route принадлежать тому же разрешённому профилю.
- Как учитывается общий egress.
- Не смягчает ли наличие недоступного вызывающему route глобальный cooldown.
- Как влияет retirement target между send и completion.

Основание для проверки — логика выбора scope у 429/403 в текущем health repository. [HealthRepository](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/app/Repositories/Procurement/Network/HealthRepository.php).

### Scope metadata

Нельзя при переносе случайно вернуть старым `ProxyScope` роль runtime access-control, если действующий выбор уже основан на точном profile membership.

Runbook прямо описывает прежние scopes и страну как конфигурационные метаданные, а доступ — через профили. [Runbook](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/docs/dev/runbooks/process-test-rig.md).

### Transport coverage

Существующий test-rig отдельно предупреждает, что его HTTP proxy fixture не доказывает CONNECT/TLS и полноценные внешние provider-контракты. Для Proxyma эти протоколы должны получить собственные реальные локальные contract tests. [Runbook](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/docs/dev/runbooks/process-test-rig.md).

***

## Тестовая спецификация

### Перенос существующих тестов

В проекте уже есть сетевые наборы на GCRA concurrency, send fencing, health, probe ownership, provider ownership, FIFO, source catalog и HTTP transport. Их нужно распределить между пакетом и project adapter, а не заменить несколькими новыми smoke tests. [Каталог сетевых тестов](https://github.com/axiomasoft/agelto/tree/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/tests/Feature/ProcessCore/Network).

| Текущий набор | Целевой владелец |
|---|---|
| `GcraConcurrencyTest` | Proxyma budgets |
| `BoundedFifoDemandTest` | Proxyma admission |
| `ClaimSendFenceTest` | Proxyma + Agelto adapter |
| `ProbeOwnershipTest` | Proxyma health |
| `RouteHealthTest` | Proxyma health |
| `HttpTransportTest` | Proxyma transport |
| `HttpPolicyTest` | Proxyma security |
| `ResponseDecoderTest` | Proxyma transport |
| `AdapterSendFenceTest` | Agelto |
| `AdapterOutcomeTest` | Agelto |
| `ProviderOwnershipTest` | Разделить network и project ownership |
| `LegacyOutboundClientTest` | Временный Agelto bridge |
| `SourceCatalogSyncTest` | Разделить generic catalog и platform adapter |
| `StatusCapacityQuotaTest` | Generic quota + Agelto traffic policy |

Наличие тестовых файлов не означает, что эти сценарии были выполнены в рамках данного анализа.

### Новые обязательные сценарии

| Группа | Минимальные сценарии |
|---|---|
| Config | Неизвестный key; неверный тип; невозможный bound; config cache |
| Registry | Duplicate ID; missing dependency; cycle; freeze |
| Policy | Все слои; inherit; null; empty list; conflict |
| Tenant | Нельзя прочитать или выбрать чужой route/profile |
| Binding | Missing subject; orphan binding; неоднозначный policy path |
| Routing | Empty profile; explicit direct; disabled route; unsupported protocol |
| Credentials | Rotation между reserve/send; missing secret; secret redaction |
| Budgets | Одновременные scopes; hour/day; exhaustion; bounds |
| Reservations | Cancel; expire; duplicate reserve; no double release |
| Send | Duplicate claim; expired permit; changed request fingerprint |
| Owner | Pause/cancel/lost generation между reserve/send |
| Health | Stale completion; exact probe scope; credential hold |
| Retry | Max attempts; distinct routes; no hidden SDK retries |
| Redirect | Новый permit; cross-origin headers; downgrade deny |
| Transport | HTTP proxy; CONNECT; TLS verification; bounded body |
| Crash | До reserve; после reserve; после authorization; после send |
| Events | After commit; outbox retry; consumer dedup |
| Filament | Authorization; tenant scope; version conflict; inherit |
| Migration | Fresh install; existing upgrade; cutover; rollback |
| Architecture | Нет `App\`; нет Filament в core; нет DB в Domain |

### Конкурентные тесты

Обязательны отдельные PHP-процессы и отдельные DB connections.

Один тест с последовательными вызовами не доказывает:

- Соблюдение concurrency.
- Отсутствие over-consumption.
- Корректность FIFO.
- Отсутствие двойного send claim.
- Правильность lock order.

Для квоты:

```text
несколько workers одновременно
    ↓
один общий ограниченный budget
    ↓
число успешных authorizations не превышает limit
```

Для смены credentials:

```text
worker A reserves
worker B rotates credentials
worker A claims send
    ↓
старый permit отклонён
```

### Сохранение provenance

Runbook Agelto уже предусматривает scenario transfers и запрещает терять исторические executed cases при переименовании тестов. Извлечение пакета должно обновить эту карту, сохранив связь исходного сценария и нового owning test. [Runbook](https://github.com/axiomasoft/agelto/blob/f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f/docs/dev/runbooks/process-test-rig.md).

***

## План для исполнителей

### Этап A — зафиксировать контракты

**Результат:**

```text
docs/architecture.md
docs/configuration.md
docs/admission-and-fencing.md
docs/extension-contracts.md
docs/migrations.md
```

Задачи:

- Зафиксировать package/project boundary.
- Утвердить сущности и имена.
- Утвердить lock order.
- Утвердить quota semantics.
- Утвердить pinned/live policy semantics.
- Утвердить error decision model.
- Утвердить migration/cutover strategy.

Критерий готовности: ни один следующий исполнитель не должен самостоятельно решать, считает ли quota retry и может ли request override ослабить общий лимит.

### Этап B — создать каркас

**Изменения:**

```text
packages/core/composer.json
packages/core/src/Domain/
packages/core/src/Contracts/
packages/core/src/Laravel/ProxymaServiceProvider.php
packages/filament/composer.json
```

Критерии:

- Core устанавливается без Filament.
- Package discovery работает.
- Config cache проходит.
- Domain не импортирует Laravel.
- Нет production migrations/network calls на bootstrap.

### Этап C — каталог

**Изменения:**

```text
Domain/Catalog/
Application/Commands/
Infrastructure/Eloquent/
database/migrations/core/
```

Задачи:

- Routes.
- Proxy endpoints.
- Profiles.
- Exact membership.
- Targets.
- Versioned commands.
- Actor abstraction.
- Audit.

Критерии:

- Нельзя получить implicit direct.
- Нельзя изменить state без version check.
- Credential rotation инвалидирует старую version.
- Catalog sync не сбрасывает operational state.

### Этап D — настройки

**Изменения:**

```text
Domain/Policy/
Application/Resolution/
Application/Extensions/
database/migrations/core/
```

Задачи:

- Settings schema.
- Typed patches.
- Panel registry.
- Subject registry.
- Policy path.
- Revisions.
- Explain API.
- Cache invalidation.

Критерии:

- Полный набор layer tests.
- Нет arbitrary DB class execution.
- Locked fields нельзя изменить через API.
- Tenant входит во все нужные keys.
- Кеш не переживает несовместимую registry revision.

### Этап E — execution

**Изменения:**

```text
Domain/Execution/
Application/Execution/
Infrastructure/Http/
```

Задачи:

- Logical/physical identities.
- Request sealing.
- Safe URL policy.
- One-send transport.
- Controlled redirects.
- Controlled retries.
- Cancellation/deadline.
- Outcome persistence.

Критерии:

- Нет скрытых retry.
- Каждая физическая попытка имеет отдельный ID.
- Изменение request после authorization запрещено.
- Неидемпотентные ambiguous outcomes не повторяются автоматически.

### Этап F — admission и budgets

**Изменения:**

```text
Modules/Admission/
Modules/Budgets/
database/migrations/admission/
database/migrations/budgets/
```

Задачи:

- Demands.
- FIFO.
- Reservations.
- Send claim.
- Multi-scope GCRA.
- Concurrency.
- Hour/day fixed quotas.
- Cooldown.
- Recovery/reaping.

Критерии:

- Реальные конкурентные тесты.
- No partial consumption.
- No double claim.
- No double release.
- No reset on policy update.
- Fail closed при отсутствии required capability.

### Этап G — health

**Изменения:**

```text
Modules/Health/
database/migrations/health/
```

Задачи:

- Independent classifiers.
- Reducers.
- Epochs.
- Probe ownership.
- Credential hold.
- Late observation handling.

Критерии:

- Старый response не снимает новый hold.
- Probe применим только к своему exact scope set.
- Parser health отсутствует в generic module.

### Этап H — Agelto adapter

**Изменения:**

```text
app/Infrastructure/Proxyma/
```

Задачи:

- Owner authority.
- Subject mappings.
- Panels и slots.
- Outcome policies.
- Adapter admission guard.
- Authorization.
- Financial coordinator bridge.

Критерии:

- Proxyma не импортирует `App\`.
- Процессная authority не ослаблена.
- Сохраняются pause/cancel/generation fences.
- Существующие stage bindings воспроизводятся.

### Этап I — Filament

**Изменения:**

```text
packages/filament/src/
```

Задачи:

- Catalog resources.
- Profiles.
- Dynamic panels page.
- Embedded settings section.
- Policy inspector.
- Budget/health pages.
- Authorization checks.
- Conflict handling.

Критерии:

- Плагин работает в выбранной Filament panel.
- Ядро работает без UI.
- Проектный редактор процессов остаётся в Agelto.
- Изменения идут через application commands.

### Этап J — перенос и переключение

Задачи:

- Inventory mappings.
- Dry-run transfer.
- Drain.
- Operational state transfer.
- Single-authority switch.
- Regression.
- Rollback rehearsal.
- Reader cleanup.

Критерии:

- Нет потерянных active quotas.
- Нет сброшенных cooldowns.
- Нет одновременно работающих authority engines.
- Исторические migrations не переписаны.
- Последний legacy reader удалён до удаления таблиц.

***

## Правила для дешёвых моделей

Каждую задачу следует выдавать в фиксированном формате:

```text
Задача:
    одно ограниченное изменение

Разрешённые файлы:
    точный список или каталог

Запрещённые изменения:
    соседние контракты, migrations history, baseline

Входные контракты:
    конкретные интерфейсы и DTO

Инварианты:
    что нельзя нарушить

Обязательные тесты:
    имена сценариев

Команды проверки:
    точные команды проекта

Результат:
    diff + список выполненных проверок

Условия остановки:
    неоднозначность контракта или необходимость изменить boundary
```

Отдельно закрепить запреты:

- Не создавать новые абстракции без необходимости задачи.
- Не менять публичную сигнатуру ради удобства реализации.
- Не заменять typed result на `array` или `mixed`.
- Не использовать `catch (\Throwable)` для превращения любой ошибки в success/fallback.
- Не добавлять direct fallback.
- Не отключать verification ради прохождения тестов.
- Не подменять конкурентный тест последовательным.
- Не считать наличие тестового файла доказательством выполнения.
- Не удалять baseline-сценарии.
- Не добавлять network I/O внутрь DB transaction.
- Не вызывать `env()` из runtime business services.
- Не сериализовать credentials.
- Не использовать Eloquent events как единственный механизм критического учёта.
- Не делать silent no-op для обязательного модуля.
- Не создавать двойного владельца бюджета при переходе.

Публичные контракты и миграционная стратегия должны изменяться только координатором архитектуры, а не каждым исполнителем по отдельности.

***

## Дополнительные возможности

Эти механизмы стоит предусмотреть в контрактах, но не обязательно реализовывать сразу:

| Возможность | Зачем | Приоритет |
|---|---|---|
| Sticky routing | Одна сессия/операция использует один egress | После базового извлечения |
| Weighted selection | Разные приоритеты providers/routes | Ранний |
| Provider sync adapter | Импорт endpoints из внешнего сервиса | Позднее |
| Temporary override | Операционный override с expiry | Ранний |
| Policy preview | Показать последствия до сохранения | Ранний |
| Dry-run admission | Объяснить блокировку без отправки и списания | Ранний |
| Retry budget | Не только requests, но и ограничение повторов | После основного budget module |
| Per-provider quota | Общая квота провайдера proxy/API | Через scope provider |
| Route retirement | Мягкое удаление без потери истории | Сразу |
| Import/export | Перенос политик между окружениями без secrets | После стабилизации схемы |
| OpenTelemetry adapter | Трассировка logical/physical attempts | Опционально |
| Adaptive routing | Выбор по latency/error rate | Только после накопления достоверных метрик |
| Distributed admission service | Несколько приложений используют общий authority | Отдельная будущая архитектура |

Особенно полезен `dry-run`:

```text
Почему запрос сейчас не разрешён?

1. Профиль выбран корректно.
2. Доступны 3 маршрута.
3. Один отключён оператором.
4. Один находится в credential hold.
5. У оставшегося исчерпана процессная часовая квота.
6. Следующий допустимый момент: ...
```

Такой ответ нужен и администратору, и проектному разработчику, и автоматическому исполнителю.

## Что не включать сначала

Чтобы Proxyma действительно оставалась компактной, в первую версию не стоит включать:

- Собственный workflow engine.
- Собственную систему очередей.
- Собственный редактор бизнес-процессов.
- Универсальный financial billing.
- GUI для написания PHP.
- Автоматическое исполнение загружаемых плагинов из БД.
- Полноценный marketplace proxy providers.
- Автоматическое снятие любых блокировок по таймеру.
- Поддержку всех SQL dialects.
- Обещание exactly-once HTTP.
- Универсальную поддержку любого стороннего SDK.
- Автоматическую оптимизацию лимитов без наблюдаемой модели.
- Перенос procurement/parser semantics в пакет.

**Критерий готовности Proxyma v1:** новый проект может установить основной пакет, зарегистрировать собственную панель и тип сущности, выбрать профиль, при необходимости подключить budgets и health, встроить Filament-секцию и выполнить управляемый запрос — не импортируя ни одного класса Agelto. При этом Agelto использует тот же пакет через свои адаптеры и сохраняет существующие гарантии допуска, владельцев и восстановления.