# modman — система управления модулями

Переработанная (compile-not-patch) система управления модулями CMS. Сосуществует со старым
`app/modules/modman`, не влияя на его работу: компилирует собственные артефакты с суффиксом `_new`
и держит собственный реестр состояния.

- Идея и слои — [ARCHITECTURE.md](./ARCHITECTURE.md)
- Пошаговый трекер — [plan.md](./plan.md)
- Сравнение со старым modman — таблица «старый → новый» в ARCHITECTURE.md §12

## Карта каталога

```
contract/     capability-интерфейсы модуля (DeclaresModule, Provides*) — вместо method_exists
catalog/      ModuleManifest + value-объекты, discovery (Filesystem/Composer), ManifestFactory, PackageCatalog
registry/     ModuleStatus, Version, ModuleState, ModuleRegistry (атомарный lock-файл — источник истины)
compiler/     AtomicWriter, ConfigCompiler (чистая компиляция), MenuCompiler, ArtifactPaths
deps/         SemverConstraint, DependencyGraph, DependencyResolver (прямые + обратные зависимости)
migration/    MigrationOwnershipRepository, ModuleMigrationRunner (учёт владения, pending-only update)
lifecycle/    Planner/Executor/Steps/Handlers (mutex, commit-at-end, компенсация, reconcile)
events/       ModuleLifecycleDispatcher (app-level шина межмодульных интеграций)
controllers/  backend/ModulesController (веб-интерфейс)
commands/     ModulesController (консольный драйвер поверх того же фасада)
ModuleManager.php  фасад — единый публичный API для драйверов
```

## Подключение в приложение

Маршрутизация общая, поэтому веб-интерфейс доступен сразу после регистрации модуля.

**1. Зарегистрировать модуль** (в `app/var/config/modulesConfigFile.php` или ином месте, попадающем в
`modules` приложения):

```php
'modman' => [
    'class' => \modules\modman\Module::class,
],
```

> Это нужно для **холодного старта**: пока нет скомпилированного `_new`-конфига, менеджеру неоткуда
> взяться. Дальше менеджер (как системный модуль, `editable=false`) сам попадает в компилируемые
> артефакты — его регистрация и пункт меню идут через `_new`-файлы (шаг 3), а не вымываются при
> каждой `recompile`. Системные модули компилируются всегда, независимо от реестра.

**2. Добавить Bootstrap** (в `bootstrapComponentsAndModulesConfigFile.php` или app `bootstrap`), чтобы
DI и шина событий поднимались рано:

```php
\modules\modman\Bootstrap::class,
```

> Шаг 2 необязателен для работы веб-интерфейса (Module::init поднимает DI как страховку), но нужен,
> чтобы другие модули могли подписаться на фазы lifecycle.

**3. (Опционально) Подключить скомпилированные `_new`-артефакты**, чтобы управляемые модули реально
работали в приложении. В `app/common/config/main.php`, рядом с подключением оригинальных артефактов:

```php
// Дополнительно к основным модулям — те, что установлены через modman.
$modulesNew = @include Yii::getAlias('@config-dyn-gen/modulesConfigFile_new.php');
if (is_array($modulesNew)) {
    $modules = array_merge($modules, $modulesNew);
}
// Аналогично при необходимости: bootstrap_new, components_new, logChannels_new, menu-*_new.
```

Поскольку `_new`-модули изолированы (свой namespace, таблицы `*_new`, директории с суффиксом),
это сложение аддитивно и не задевает то, чем владеет старый modman.

## Использование

**Веб:** `/modman/backend/modules/index` — список модулей/пакетов, «План» (dry-run), установка,
обновление, удаление, «Сверка» (reconcile), «Пересобрать конфиг».

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

Пакет без маркера менеджер игнорирует. Модуль, помеченный `kind: module`, но ещё не переведённый на
новый контракт (или с ошибкой конфигурации), показывается строкой с причиной и погашенной кнопкой
установки; подробности уходят в лог-канал `modman/*`, а не во flash на всю страницу.

## Контракт модуля (новый)

Модуль реализует `DeclaresModule` и нужные `Provides*` (статические методы — discovery не инстанцирует
класс). Пример минимального модуля:

Модуль наследует тонкий рантайм-базовый класс `common\components\module\CmsModule` (даёт раскладку
controllerNamespace и layout из темы) и реализует нужные контракты:

```php
use common\components\module\CmsModule;
use modules\modman\contract\DeclaresModule;
use modules\modman\contract\ProvidesMigrations;

final class Module extends CmsModule implements DeclaresModule, ProvidesMigrations
{
    public static function moduleId(): string { return 'ShortcodeNew'; }
    public static function moduleVersion(): string { return '1.0.0'; }
    public static function isEditable(): bool { return true; }
    public static function moduleConfig(): array { return ['id' => 'ShortcodeNew', 'params' => [...]]; }

    public static function migrationPath(): string { return __DIR__ . '/migrations'; }
    public static function migrationNamespace(): ?string { return __NAMESPACE__ . '\\migrations'; }
}
```

Соответствующий `composer.json` модуля объявляет и маркер, и класс/id:

```json
"extra": {
    "bescms": { "kind": "module" },
    "moduleClass": "Besnovatyj\\ShortcodeNew\\Module",
    "moduleId": "ShortcodeNew"
}
```

> **Размещение контрактов.** Сейчас они в `modman/contract/` (для целостности репозитория и diff).
> В продакшене (после cutover) их следует «повысить» в `common\components\module\`, чтобы модули не
> зависели от менеджера.

> **Сам менеджер — обычный модуль.** `modman/Module` реализует тот же `DeclaresModule` с
> `isEditable() === false`: он виден в общем списке как «системный» (с версией, без кнопок
> установки/удаления), а не как исключение. Бутстрапится приложением вручную (шаги 1–2 выше).

## Проверка синтаксиса (Docker)

```
docker compose exec php sh -c 'find /home/node/app/modules/modman -name "*.php" -not -path "*/.git/*" -print0 | xargs -0 -n1 -P4 php -l'
```

## Cutover (план перехода)

1. Перевести существующие модули на новый контракт (capability-интерфейсы), убрать `*_new`-суффиксы.
2. В `ArtifactPaths`/`params.php` заменить `_new`-пути на канонические.
3. `php yii modman/modules/recompile`.
4. Удалить старый `app/modules/modman`. Контракты перенести в `common`.
