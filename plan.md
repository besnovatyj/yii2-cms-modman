# План работ: новая система управления модулями `modmanNew`

> Канонический трекер задачи. Иду по шагам, отмечаю статусы. Статусы: `[ ]` todo, `[~]` in progress, `[x]` done.

## Цель

Создать **полноценную** переработанную систему управления модулями CMS на основе единого
архитектурного закона:

> **Состояние модулей декларативно и единично. Вся Yii-конфигурация — чистая производная.
> Конфиги не патчатся, а компилируются заново.**

Старый модуль `app/modules/modman` продолжает работать. Новый `modmanNew` сосуществует с ним:
он управляет своим реестром и компилирует свои артефакты конфигурации (в переходный период — с
суффиксом `_new`, путь конфигурируется в DI). После «отстрела» (cutover) пути артефактов
переключаются на канонические, а старый `modman` удаляется.

Подробности архитектуры — в [ARCHITECTURE.md](./ARCHITECTURE.md).

## Принципы для тестовых пакетов (`*-new`)

Тестовые пакеты создаются из существующих по правилу «минимально необходимых изменений»:
- имя пакета и `extra.moduleId`/`extra.moduleClass` получают суффикс;
- PSR-4 namespace получает суффикс (иначе коллизия автозагрузки);
- имена таблиц БД получают суффикс;
- имена директорий на домене статики получают суффикс;
- модуль переводится на **новый контракт** (capability-интерфейсы вместо статических методов +
  `method_exists`) — это и есть «принцип перехода», который видно в diff.

Пакеты ставятся штатно через composer (path-repository уже настроен). Никаких рантайм-костылей
автозагрузки.

---

## Фаза 0. Подготовка
- [x] Исследовать старый `modman`, структуру модулей, автозагрузку, генерируемые конфиги
- [x] Зафиксировать целевую архитектуру (ARCHITECTURE.md)
- [x] Создать каркас `modmanNew` + git-репозиторий

## Фаза 1. Контракты модуля (`contract/`) — ✅
- [x] `DeclaresModule` — ядро: id, версия, класс модуля, editable
- [x] capability-интерфейсы: `ProvidesDependencies`, `ProvidesComponents`, `ProvidesBootstrap`,
      `ProvidesAdminMenu`, `ProvidesOptions`, `ProvidesLogChannels`, `ProvidesMigrations`,
      `ProvidesDirectories`

## Фаза 2. Каталог и discovery (`catalog/`) — ✅
- [x] Value-объекты: `ModuleManifest`, `Requirements`, `Contributions`, `RequiredDirectory`
- [x] `source/ModuleSource` + `FilesystemModuleSource` + `ComposerInstalledModuleSource`
- [x] `ManifestFactory` — сборка манифеста из пакета (composer.json + контракты)
- [x] `PackageCatalog` — агрегация источников, кэш на запрос, разрешение дубликатов

## Фаза 3. Реестр состояния (`registry/`) — ✅
- [x] `ModuleStatus` (enum), `Version` (value object), `ModuleState` (readonly)
- [x] `ModuleRegistry` — атомарный lock-файл, status-машина, владение миграциями

## Фаза 4. Компилятор конфигурации (`compiler/`) — ✅
- [x] `ArtifactPaths`, `AtomicWriter` (tmp+rename+opcache), `CompiledArtifacts`
- [x] `ConfigCompiler` — чистая функция (registry × manifests) → артефакты
- [x] `MenuCompiler` — сборка меню по locations

## Фаза 5. Зависимости (`deps/`) — ✅
- [x] `SemverConstraint` (через composer/semver, fallback на version_compare)
- [x] `DependencyGraph` + `DependencyResolver` — topo-сортировка, обратные зависимости

## Фаза 6. Миграции с владельцем (`migration/`) — ✅
- [x] `MigrationOwnershipRepository` — учёт «миграция ↔ модуль»
- [x] `ModuleMigrationRunner` — up/down только своих миграций, pending-only для update

## Фаза 7. Lifecycle (`lifecycle/`) — ✅
- [x] `OperationContext`, `OperationReport`, `LifecyclePlan` + `PlannedStep`
- [x] `LifecycleStep` + конкретные шаги (миграции, директории) + commit/recompile в исполнителе
- [x] `LifecyclePlanner` (чистое планирование, dry-run, детекция конфликтов)
- [x] `LifecycleExecutor` (mutex, commit-at-end, компенсация)
- [x] Хендлеры: `CheckHandler`, `InstallHandler`, `UninstallHandler`, `UpdateHandler`, `ReconcileHandler`

## Фаза 8. События (`events/`) — ✅
- [x] `LifecyclePhase` (enum), `ModuleLifecycleEvent`, `ModuleLifecycleDispatcher`

## Фаза 9. Веб-интерфейс и драйверы — ✅
- [x] `ModuleManager` (фасад) + `ModuleView` (DTO для UI)
- [x] `controllers/backend/ModulesController` (список, check/plan, install, uninstall, update, reconcile, recompile)
- [x] `forms/backend/search/ModuleSearch`
- [x] `views/backend/modules/*` (Bootstrap 5: index с вкладками + plan)
- [x] `commands/ModulesController` — вторичный CLI-драйвер поверх того же фасада

## Фаза 10. Сборка модуля — ✅
- [x] `Module.php`, `Bootstrap.php`, `config/*`, `composer.json`, `README.md`, `.gitignore`
- [x] DI-контейнер (`config/container.php`), adminMenu, params (пути артефактов, scan dirs)
- [x] Коммит в git
- [ ] Проверка `php -l` в Docker (за пользователем) + подключение к приложению по README

## Фаза 11. Тестовые пакеты (после готовности modmanNew)
- [ ] `yii2-cms-shortcode-new` (эталонная конверсия, минимальный модуль)
- [ ] `yii2-cms-blog-new`
- [ ] `yii2-cms-person-new`
- [ ] Регистрация modmanNew в приложении + подключение `_new`-артефактов (additively)
- [ ] Прогон: scan → check → install → проверка БД/директорий/артефактов → uninstall → reconcile

---

## Журнал решений
- Контракты модуля размещены **внутри** `modmanNew/contract/` для целостности репозитория и удобства
  diff. В продакшене (после cutover) их следует «повысить» в `common\components\module\`, чтобы
  модули не зависели от менеджера. Зафиксировано в ARCHITECTURE.md.
- Артефакты компиляции и реестр пишутся в `@config-dyn-gen` с суффиксом `_new` (путь конфигурируем).
  Это не временный хак, а параметр сосуществования; на cutover меняется один конфиг.
- Драйверов два (web + console) поверх одного набора хендлеров — демонстрация UI-агностичного
  сервисного слоя (исправляет связку «сервис ↔ session flash» из старого modman).
- **Маркер `extra.bescms` (объект `{kind: module|package}`)** — единственный признак принадлежности
  пакета к CMS. Каталог показывает только помеченные пакеты; чужие зависимости из `vendor/` (после
  переезда в GitHub их сотни) игнорируются. Заменяет неустойчивую фильтрацию «по директории» старого
  modman. `type: yii2-extension` трогать нельзя (по нему Yii грузит bootstrap).
- **Менеджер — обычный модуль.** `Module` реализует `DeclaresModule` + `ProvidesAdminMenu` +
  `ProvidesLogChannels`, `isEditable()=false`. Не выбивается из общей кучи: в списке — «системный»
  (активен, без кнопок install/uninstall). Возвращает потерянный при переписывании канал лога
  (`config/log.php`, категория `modmanNew/*`, как у старого modman). Открывает путь к самообновлению.
- **Невалидное — не flash, а строка с причиной.** CMS-модуль с ошибкой (не сконвертирован, нет
  moduleClass, дубликат id) собирается в `catalog/InvalidModule`, показывается строкой рядом с
  причиной и погашенной кнопкой; диагностика уходит в лог-канал, а не «кричит» уведомлениями.
  Дубликат «один пакет из двух источников» гасится тихо (дедуп по composer-имени); настоящий конфликт
  (два разных пакета на один moduleId) — в невалидные.
- **Рантайм-поведение модуля — в тонком базовом классе `common\components\module\CmsModule`**, а не в
  каждом модуле. Природа разделена: метаданные → статические контракты (`DeclaresModule`/`Provides*`),
  поведение экземпляра → наследование. `CmsModule` несёт только `init()` (раскладка controllerNamespace,
  достраивает значение от `parent::init()` без рефлексии/пересчёта), `getLayoutPath()` (layouts из активной
  темы — нельзя выразить интерфейсом, т.к. это ленивый override; в init компонент темы ещё не поднят) и
  `bootstrapContainer()` — хук **способа A** проводки DI (`/config/container.php` модуля, замена
  `method_exists`-автовызова `setContainerConfig` на проверку файла; переопределяем — менеджер добавляет
  guard, т.к. его контейнер грузится глобально через Bootstrap = способ B). Способ C — контракт
  `ProvidesBootstrap` (discovery-время). Слим старого `BaseModule` без магии; legacy не тронут (рядом).
  `modmanNew/Module` тоже на нём (init больше не нужен — namespace и DI-страховку даёт база). Guard на
  отсутствие темы — бэкенд не падает.
- **Иконка модуля в списке** — на стороне менеджера: фабрика читает `config.params.iconClass` в
  `ModuleManifest::$iconClass` → `ModuleView` → вьюха. Метод на модуле (как `BaseModule::getIcon`) не
  нужен — иконка нужна менеджеру для списка, а не самому модулю.
- **Подробный лог установки/удаления — в свой канал `modmanNew/*`** (аналог старого `InstallationLog`).
  Три слоя были дырявы и закрыты: (1) канал поднимается в рантайме из `Bootstrap` (в фантомной фазе
  `_new`-артефакт logChannels не подключён, иначе всё валилось в общий `monolog.log`); (2)
  `LifecycleExecutor` выгружает весь `OperationReport` (старт, каждый шаг, созданные/удалённые
  директории, предупреждения, ошибки, итог) в канал, а не только во flash; (3) `ModuleMigrationRunner`
  снимает `compact`, захватывает вывод миграций (`create table … done`) и пишет в `modmanNew/migration` —
  это «записи о созданных таблицах». Несоответствия discovery уже шли в `modmanNew/discovery`.
  Файл: `@runtime/logs/monolog-modmanNew.log`.
