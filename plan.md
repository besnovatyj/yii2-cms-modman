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

## Фаза 9. Веб-интерфейс и драйверы
- [ ] `controllers/backend/ModulesController` (полноценный UI: список, check/plan, install, uninstall, update, reconcile)
- [ ] `forms/backend/search/ModuleSearch`
- [ ] `views/backend/modules/*` (Bootstrap 5, HTMX-дружелюбно)
- [ ] `commands/ModmanController` — вторичный CLI-драйвер поверх тех же хендлеров

## Фаза 10. Сборка модуля
- [ ] `Module.php`, `Bootstrap.php`, `config/*`, `composer.json`, `README.md`, `.gitignore`
- [ ] DI-контейнер (`config/container.php`), adminMenu, params (пути артефактов, scan dirs)
- [ ] Коммит в git

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
