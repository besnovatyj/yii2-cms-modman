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
contract/     capability-интерфейсы модуля (DeclaresModule, Provides*) — вместо method_exists
catalog/      ModuleManifest + value-объекты, discovery (Filesystem/Composer), ManifestFactory,
              PackageCatalog, InvalidModule, маркер CmsMarker/CmsKind (extra.bescms)
registry/     ModuleStatus, Version, ModuleState, ModuleRegistry (атомарный lock-файл — источник истины)
compiler/     AtomicWriter, ConfigCompiler (чистая компиляция), MenuCompiler, ArtifactPaths
deps/         SemverConstraint, DependencyGraph, DependencyResolver (прямые + обратные зависимости)
migration/    MigrationOwnershipRepository, ModuleMigrationRunner (учёт владения, pending-only update)
lifecycle/    Planner/Executor/Steps/Handlers (mutex, commit-at-end, компенсация, reconcile)
events/       ModuleLifecycleDispatcher (app-level шина межмодульных интеграций)
controllers/  backend/ModulesController (веб-интерфейс)
commands/     ModulesController + MenuController (консольные драйверы поверх того же фасада)
ModuleManager.php  фасад — единый публичный API для драйверов
```

## Подключение в приложение

**1. Глобальный bootstrap** — в `app/common/config/main.php` (app-level `bootstrap`), чтобы DI-проводка
и шина событий поднимались рано, до загрузки скомпилированных артефактов:

```php
'bootstrap' => ['log', 'queue', \modules\modman\Bootstrap::class],
```

`Bootstrap` поднимает DI-контейнер менеджера и его канал лога `modman/*`. Это же решает chicken-and-egg:
менеджер обязан работать, чтобы скомпилировать конфиги, поэтому его проводка — глобальная, а не из
компилируемого артефакта.

**2. Компилируемые артефакты — это и есть конфиг приложения.** Менеджер пишет в канонические пути
(`@config-dyn-gen/modulesConfigFile.php`, `componentsConfigFile.php`, `logChannelsConfigFile.php`,
`menu-*.php`, …), которые приложение и так загружает. Никаких `_new` и аддитивного слияния — это
основной конфиг.

**3. Холодный старт.** Сам менеджер — системный модуль (`editable=false`) и компилируется в
`modulesConfigFile.php` **всегда** (см. ARCHITECTURE §6), поэтому его регистрация и пункт меню переживают
любую `recompile`. Но до самой первой компиляции (или если артефакт пуст) прописать его вручную, иначе
до UI не добраться:

```php
'modman' => ['class' => \modules\modman\Module::class],
```

## Использование

**Веб:** `/modman/backend/modules/index` — список модулей/пакетов (фильтры по статусу/обновлениям,
сортировка, пагинация), «План» (dry-run), установка, обновление, удаление, «Сверка» (reconcile),
«Пересобрать конфиг», «Пересобрать меню».

**Консоль:**

```
php yii modman/modules/list
php yii modman/modules/check <moduleId>
php yii modman/modules/install <moduleId>
php yii modman/modules/update <moduleId>
php yii modman/modules/uninstall <moduleId>
php yii modman/modules/reconcile
php yii modman/modules/recompile
php yii modman/menu/info       # диагностика локаций меню (вкл/выкл, файл, существование, число пунктов)
php yii modman/menu/rebuild    # перекомпилировать только артефакты меню
```

(Для консоли модуль также должен быть в `modules` console-приложения.)

## Маркер пакета CMS (`extra.bescms`)

Менеджер показывает **только** пакеты, явно помеченные как часть этой CMS, — чужие composer-зависимости
из `vendor/` (после переезда в GitHub их будут сотни) в список не попадают. Маркер живёт в `composer.json`
в блоке `extra` (трогать `type: yii2-extension` нельзя — по нему Yii подключает свои bootstrap):

```json
"extra": {
    "bescms": { "kind": "module" }
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

Модуль наследует тонкий рантайм-базовый класс `common\components\module\CmsModule` (раскладка
controllerNamespace по контексту приложения, layout из активной темы, хук DI `/config/container.php`) и
реализует `DeclaresModule` плюс нужные `Provides*` (статические методы — discovery не инстанцирует класс):

```php
use common\components\module\CmsModule;
use modules\modman\contract\DeclaresModule;
use modules\modman\contract\ProvidesMigrations;

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
    "bescms": { "kind": "module" },
    "moduleClass": "Besnovatyj\\Shortcode\\Module",
    "moduleId": "Shortcode"
}
```

> **Размещение контрактов.** Сейчас они в `modman/contract/` (для целостности репозитория и diff).
> Архитектурно правильнее «повысить» их в `common\components\module\`, чтобы модули не зависели от
> менеджера — это оставшийся шаг (см. «Статус» ниже).

> **Сам менеджер — обычный модуль.** `modman/Module` реализует тот же `DeclaresModule` с
> `isEditable() === false`: он виден в общем списке как «системный» (с версией, без кнопок
> установки/удаления), а не как исключение. Его проводка — глобальная (шаг 1), а в компиляцию он
> попадает как любой системный модуль.

## Проверка синтаксиса (Docker)

```
docker compose exec php sh -c 'find /home/node/app/modules/modman -name "*.php" -not -path "*/.git/*" -print0 | xargs -0 -n1 -P4 php -l'
```

## Статус

Cutover на канонические пути выполнен; модуль на стадии тестирования. Прежний патч-modman сохранён как
`app/modules/modman_b` (образец, вне автозагрузки). Остаётся:

1. Обкатать `install`/`uninstall`/`update`/`reconcile` на реальных модулях (Фаза 11 в [plan.md](./plan.md)).
2. «Повысить» контракты `contract/*` в `common\components\module\`.
3. После доверия — удалить `modman_b`.
