# modman — система управления модулями

Система управления модулями CMS на принципе **compile-not-patch**: состояние модулей декларативно и
единично (реестр), а вся Yii-конфигурация — производная и компилируется заново. Модуль на стадии
тестирования.

Пришла на смену прежнему патч-based `modman`, который сохранён как образец в `app/modules/modman_b`
(нерабочий, для сверки). Артефакты пишутся в **канонические** пути конфигурации приложения — без
суффиксов и аддитивных слияний.

- Идея и слои — [ARCHITECTURE.md](./ARCHITECTURE.md)
- Пошаговый трекер и журнал решений — [plan.md](./plan.md)
- Сравнение «патч-modman → modman» — таблица в ARCHITECTURE.md §12

## Карта каталога

```
catalog/      ModuleManifest + value-объекты, discovery (Filesystem/Composer), ManifestFactory,
              PackageCatalog, InvalidModule, маркер CmsMarker/CmsKind (extra.bescms)
registry/     ModuleStatus, Version, ModuleState, ModuleRegistry (атомарный lock-файл — источник истины)
compiler/     AtomicWriter, ConfigCompiler (чистая компиляция), MenuCompiler, ArtifactPaths
deps/         SemverConstraint, DependencyGraph, DependencyResolver (прямые + обратные зависимости)
migration/    MigrationOwnershipRepository, ModuleMigrationRunner (учёт владения, pending-only update)
lifecycle/    Planner/Executor/Steps/Handlers (mutex, commit-at-end, компенсация, reconcile, sync)
events/       ModuleLifecycleDispatcher (app-level шина межмодульных интеграций)
controllers/  backend/ModulesController (веб-интерфейс)
commands/     ModulesController + MenuController (консольные драйверы поверх того же фасада)
ModuleManager.php  фасад — единый публичный API для драйверов
```

> **Контракты модуля вынесены в пакеты.** Capability-интерфейсы (`DeclaresModule`, `Provides*`)
> живут в пакете `besnovatyj/yii2-cms-contracts` (`Besnovatyj\Contracts\module\*`), а рантайм-базовый
> класс — в `besnovatyj/yii2-cms-kernel` (`Besnovatyj\Kernel\module\CmsModule`). Менеджер и модули
> используют версии из пакетов; локального каталога `contract/` больше нет.

## Подключение в приложение

**1. Глобальный bootstrap** — в `app/common/config/main.php` (app-level `bootstrap`), чтобы DI-проводка
и шина событий поднимались рано, до загрузки скомпилированных артефактов:

```php
'bootstrap' => ['log', 'queue', \Besnovatyj\Modman\Bootstrap::class],
```

`Bootstrap` поднимает DI-контейнер менеджера и его канал лога `modman/*`. Это же решает chicken-and-egg:
менеджер обязан работать, чтобы скомпилировать конфиги, поэтому его проводка — глобальная, а не из
компилируемого артефакта.

**2. Компилируемые артефакты — это и есть конфиг приложения.** Менеджер пишет в канонические пути
(`@config-dyn-gen/modulesConfigFile.php`, `componentsConfigFile.php`, `logChannelsConfigFile.php`,
`menu-*.php`, …), которые приложение и так загружает. Никаких `_new` и аддитивного слияния — это
основной конфиг.

**3. Холодный старт — автоматический.** `Bootstrap` (шаг 1) сам регистрирует модуль `Modman` в
приложении (`Application::setModule`), поэтому менеджер доступен даже когда `modulesConfigFile.php`
пуст, устарел или ссылается на мёртвый старый id/namespace. Это разрывает chicken-and-egg: инструмент,
который компилирует артефакт модулей, не зависит от этого артефакта, чтобы запуститься, — руками
артефакт править не нужно (и нельзя, closed-loop compile-not-patch).

Регистрация идемпотентна (guard `hasModule`): как системный модуль (`editable=false`) `Modman` всё
равно попадёт в `modulesConfigFile.php` при следующей `recompile` и переживёт любую пересборку (см.
ARCHITECTURE §6); саморегистрация в bootstrap — постоянная страховка на холодный старт, а не костыль.

## Использование

**Веб:** `/Modman/backend/modules/index` — список модулей/пакетов (фильтры по статусу/обновлениям,
сортировка, пагинация), «План» (dry-run), установка, обновление, удаление, «Сверка» (reconcile),
«Пересобрать конфиг», «Пересобрать меню». (`sync` — пока только в консоли, см. ниже.)

**Консоль:**

```
php yii Modman/modules/list
php yii Modman/modules/check <moduleId>
php yii Modman/modules/install <moduleId>
php yii Modman/modules/update <moduleId>
php yii Modman/modules/uninstall <moduleId>
php yii Modman/modules/reconcile
php yii Modman/modules/sync [--adoptAll]   # пересобрать реестр из реальности (см. ниже)
php yii Modman/modules/recompile
php yii Modman/menu/info       # диагностика локаций меню (вкл/выкл, файл, существование, число пунктов)
php yii Modman/menu/rebuild    # перекомпилировать только артефакты меню
```

(Для консоли модуль также должен быть в `modules` console-приложения.)

### `sync` — восстановление реестра из фактического состояния

Реестр `modules-state.php` — единственный источник истины, но если его файл затёрли/побили, штатного
способа восстановить его «из реальности» раньше не было (`reconcile` чинит только транзиентные записи,
уже присутствующие в реестре). `sync` закрывает эту точку отказа — **без ручной правки файла и без
ручных контрольных сумм**:

- набор модулей берётся из discovery (composer + скан `packages/besnovatyj`);
- применённые миграции — из БД-истории владения (`MigrationOwnershipRepository`);
- `manifestChecksum` — пересчитывается детерминированно фабрикой манифестов.

«Установлен по факту» = уже `installed` в реестре, **или** за модулем числятся применённые миграции в
БД, **или** передан `--adoptAll`. Иначе модуль пропускается (используйте `install`). После пересборки
запускается `recompile`. Осиротевшие записи реестра (пакет исчез из каталога) не удаляются — только
сообщаются. `--adoptAll` (`-a`) — крайняя мера: усыновить как `installed` все обнаруженные
editable-модули для голого восстановления.

## Маркер пакета CMS (`extra.bescms`)

Менеджер показывает **только** пакеты, явно помеченные как часть этой CMS, — чужие composer-зависимости
из `vendor/` (после переезда в GitHub их будут сотни) в список не попадают. Маркер живёт в `composer.json`
в блоке `extra` (трогать `type: yii2-extension` нельзя — по нему Yii подключает свои bootstrap):

```json
"extra": {
"bescms": {"kind": "module"}
}
```

- `kind: "module"` — полноценный управляемый модуль. Должен также объявлять `extra.moduleClass`/`moduleId`
  и реализовывать контракт `DeclaresModule` (см. ниже). Попадает во вкладку «Модули».
- `kind: "package"` — пакет CMS без жизненного цикла (например, виджет). Виден во вкладке «Пакеты»,
  но не устанавливается менеджером.

Пакет без маркера менеджер игнорирует. Модуль, помеченный `kind: module`, но с ошибкой конфигурации
(нет `moduleClass`, не реализует контракт, дубликат id), показывается строкой с причиной и погашенной
кнопкой установки; подробности уходят в лог-канал `modman/*`, а не во flash на всю страницу.

## Контракт модуля

Модуль наследует тонкий рантайм-базовый класс `Besnovatyj\Kernel\module\CmsModule` (раскладка
controllerNamespace по контексту приложения, layout из активной темы, хук DI `/config/container.php`) и
реализует `DeclaresModule` плюс нужные `Provides*` (статические методы — discovery не инстанцирует класс):

```php
use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesMigrations;

final class Module extends CmsModule implements DeclaresModule, ProvidesMigrations
{
    public static function moduleId(): string { return 'Shortcode'; }
    public static function moduleVersion(): string { return '1.0.0'; }
    public static function isEditable(): bool { return true; }
    public static function moduleConfig(): array { return ['id' => 'Shortcode', 'params' => [...]]; }

    public static function migrationPath(): string { return __DIR__ . '/migrations'; }
    public static function migrationNamespace(): ?string { return __NAMESPACE__ . '\\migrations'; }
}
```

Соответствующий `composer.json` модуля объявляет и маркер, и класс/id:

```json
"extra": {
"bescms": {"kind": "module"},
"moduleClass": "Besnovatyj\\Shortcode\\Module",
"moduleId": "Shortcode"
}
```

> **Размещение контрактов.** Контракты и базовый класс вынесены в отдельные пакеты
> (`besnovatyj/yii2-cms-contracts` и `besnovatyj/yii2-cms-kernel`), поэтому модули не зависят от
> менеджера.

> **Сам менеджер — обычный модуль.** `modman/Module` реализует тот же `DeclaresModule` с
> `isEditable() === false`: он виден в общем списке как «системный» (с версией, без кнопок
> установки/удаления), а не как исключение. Его проводка — глобальная (шаг 1), а в компиляцию он
> попадает как любой системный модуль.

## Проверка синтаксиса (Docker)

```
docker compose exec php sh -c 'find /home/node/app/packages/besnovatyj/modman/src -name "*.php" -not -path "*/.git/*" -print0 | xargs -0 -n1 -P4 php -l'
```

## Описание функционала и логики

### Кнопка "Обновить" модуль

Это кнопка **update-lifecycle модуля в modman** — не git, не composer, не upstream-проверка. Она пересобирает интеграцию
уже лежащего на диске кода модуля в приложение.

Путь: POST → `actionUpdate` → `ModuleManager::update()` → `UpdateHandler::update()`.

**Когда появляется**: только если `installed && hasUpdate`, где 
`hasUpdate = модуль активен И (версия манифеста > версии в реестре ИЛИ checksum манифеста ≠ записанного в реестре)`. 
То есть код на диске изменился (новый тег/новый вклад), а реестр modman ещё отражает старое состояние.

**Что делает (под `LifecycleLock` + мьютексом)**:

1. Пишет в реестр статус `Updating` (write-ahead intent), шлёт событие `BeforeUpdate` (на него могут реагировать другие
   модули).
2. Применяет **только pending-миграции** модуля — благодаря учёту владения миграциями, без `down→up`/переустановки (в
   старом modman «обновление» = снести и поставить заново; здесь — настоящий инкрементальный апдейт).
3. Создаёт недостающие директории модуля (`@static/...`).
4. **Commit**: обновляет запись в реестре — новая `version`, новый `checksum`, `updatedAt = now`, список применённых
   миграций дополняется, статус → `Installed`.
5. `LifecycleExecutor` в конце **перекомпилирует конфигурацию** (merge-plan/артефакты) из реестра.
6. При успехе — событие `AfterUpdate` и отчёт «обновлён до vX» в модалке; при ошибке — **rollback** к прежнему
   установленному состоянию.

**Чего она НЕ делает**: не тянет новый код с GitHub и не запускает `composer update` — предполагается, что новый код уже
на диске (composer его уже поставил). Кнопка лишь синхронизирует под него БД-миграции, директории, реестр и
скомпилированный конфиг.

Кнопка-иконка «обновить, минуя кэш» в колонке Upstream — другое: она лишь заново запрашивает последний тег с GitHub,
ничего в системе не меняя.  
