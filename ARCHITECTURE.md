# Архитектура `modman`

Система управления модулями CMS (compile-not-patch). Документ описывает идею, слои и потоки данных.
Сопровождается пошаговым [plan.md](./plan.md).

## 1. Архитектурный закон

> **Состояние модулей декларативно и единично. Вся Yii-конфигурация — чистая производная от него.
> Конфиги не патчатся, а компилируются заново.**

Из этого закона вытекает всё остальное:

- единственный изменяемый источник истины — **реестр** (`ModuleRegistry`, атомарный lock-файл);
- все Yii-конфиги (`modules`, `bootstrap`, `components`, `logChannels`, меню) — **производные**,
  собираются `ConfigCompiler` целиком из `(реестр × манифесты)`;
- запись артефактов — только `tmp + rename()` (атомарно), без `*_backup`;
- откат операции = «вернуть прежний реестр и перекомпилировать», а не N точечных компенсаций;
- финальная запись реестра — **последний** шаг (commit-at-end): пока он не выполнен, снаружи
  «модуль установлен» не наблюдается.

Это устраняет корневую причину болей прежнего патч-`modman`, где первичное состояние было размазано по
нескольким изменяемым PHP-файлам, каждый со своим `_backup`.

## 2. Слои

```
            ┌─────────────────────────────────────────────────────────────┐
            │                      Драйверы (UI-агностично)                 │
            │   controllers/backend/ModulesController   commands/Modman…    │
            └───────────────┬───────────────────────────┬──────────────────┘
                            │                            │
                            ▼                            ▼
            ┌─────────────────────────────────────────────────────────────┐
            │                        lifecycle/                             │
            │  Planner → LifecyclePlan        Executor (mutex, commit-end)  │
            │  handler/{Check,Install,Uninstall,Update,Reconcile}           │
            │  step/{RunMigrations,CreateDirs,Recompile,CommitRegistry,…}   │
            └───┬──────────────┬─────────────┬──────────────┬──────────────┘
                │              │             │              │
                ▼              ▼             ▼              ▼
        ┌────────────┐ ┌─────────────┐ ┌──────────┐ ┌──────────────┐
        │  catalog/  │ │  registry/  │ │ compiler/│ │  migration/  │
        │ PackageCat │ │ ModuleRegis │ │ ConfigCo │ │ Runner +     │
        │ + sources  │ │ try (lock)  │ │ mpiler   │ │ Ownership    │
        │ Manifests  │ │ ModuleState │ │ +Atomic  │ │              │
        └─────┬──────┘ └─────────────┘ └──────────┘ └──────────────┘
              │                ▲
              ▼                │
        ┌────────────┐   ┌──────────┐
        │  contract/ │   │  deps/   │
        │ Provides*  │   │ Resolver │
        └────────────┘   └──────────┘
                  events/  ◄── lifecycle публикует фазы (межмодульные интеграции)
```

## 3. Контракты модуля (`contract/`)

Вместо россыпи опциональных статических методов и `method_exists()` — набор **capability-интерфейсов**.
Модуль реализует ровно то, что предоставляет; менеджер проверяет через `instanceof`. Это статически
проверяемо, видно IDE и тестируемо.

- `DeclaresModule` — ядро: `moduleId()`, `moduleVersion()`, `moduleConfig()`, `isEditable()`.
- `ProvidesDependencies` — `dependencies(): array` (оборачивается в `Requirements`).
- `ProvidesComponents` — `components(): array`.
- `ProvidesBootstrap` — `bootstrapClasses(): array`.
- `ProvidesAdminMenu` — `adminMenu(): array`.
- `ProvidesOptions` — `options(): array`.
- `ProvidesLogChannels` — `logChannels(): array`.
- `ProvidesMigrations` — `migrationPath(): string`, `migrationNamespace(): ?string`.
- `ProvidesDirectories` — `directories(): array`.

> **Рантайм vs метаданные.** Контракты — это метаданные (читаются менеджером статически). Рантайм-поведение
> экземпляра (раскладка `controllerNamespace`, layout из темы, DI способа A) живёт в базовом классе
> `common\components\module\CmsModule`, который модуль наследует параллельно реализации контрактов. Это
> разные оси (наследование vs интерфейсы), без конфликта.

> **Размещение.** Сейчас контракты лежат внутри `modman/contract/` для целостности репозитория и удобства
> diff. Архитектурно правильное место — `common\components\module\` (фреймворк-уровень), чтобы модули не
> зависели от менеджера. Это «повышение» — оставшийся шаг.

## 4. Каталог и discovery (`catalog/`)

- `ModuleManifest` (readonly value object) — **единственный** носитель метаданных пакета. Собирается
  из `composer.json` (`extra.moduleClass/moduleId`) + опроса контрактов класса модуля. Менеджер
  никогда не инстанцирует Yii-модуль ради метаданных.
- `Requirements`, `Contributions`, `RequiredDirectory` — типизированные части манифеста.
- **Маркер `extra.bescms`** (`CmsMarker`/`CmsKind`) — единственный признак принадлежности пакета к CMS;
  каталог показывает только помеченные пакеты, чужие зависимости из `vendor/` игнорирует.
- `source/ModuleSource` — интерфейс источника. Реализации:
  - `FilesystemModuleSource` — скан директорий (`@modules`, `@root/packages/besnovatyj`);
  - `ComposerInstalledModuleSource` — `vendor/composer/installed.json` (прод-путь).
- `PackageCatalog` — агрегирует источники, кэширует на запрос, дедуплицирует один пакет из разных
  источников по composer-имени. CMS-модули с ошибкой конфигурации собирает в `InvalidModule` (показ
  строкой с причиной + лог `modman/discovery`), а не валит ими список.

## 5. Реестр состояния (`registry/`)

- `ModuleStatus` (enum): `discovered`, `installing`, `installed`, `updating`, `removing`, `failed`.
- `Version` — value object семантической версии.
- `ModuleState` (readonly): id, package, installedVersion, status, operationId, appliedMigrations,
  manifestChecksum, installedAt.
- `ModuleRegistry` — единственный изменяемый источник истины. Читается до создания Yii-приложения
  (поэтому файл, не БД). Запись атомарна (`AtomicWriter`). Status-машина разводит «прописан в конфиге»
  и «реально применён» — то, что в патч-modman было слито в `isInstalled() === hasModule()`.

## 6. Компилятор (`compiler/`)

- `ArtifactPaths` — конфигурируемые (через `config/params.php`) канонические пути всех артефактов.
- `AtomicWriter` — `tmp + rename + opcache_invalidate`, единственная точка записи на диск.
- `ConfigCompiler` — **чистая** функция: из реестра + манифестов собирает все конфиги целиком.
  **Системные модули** (`editable=false`, например сам менеджер) компилируются ВСЕГДА, даже без записи
  в реестре: они ставятся ядром/бутстрапом, иначе их конфиг и меню вымывались бы при каждой `recompile`.
- `MenuCompiler` — сборка меню по locations/группам/приоритетам (как чистая функция от манифестов).
- `CompiledArtifacts` — DTO результата компиляции (что и куда записать).

Критерий корректности: повторная install/uninstall даёт **побайтово те же** артефакты
(детерминированная компиляция).

## 7. Зависимости (`deps/`)

- `SemverConstraint` — обёртка над `composer/semver` (если доступен) с fallback на `version_compare`.
- `DependencyGraph` + `DependencyResolver` — топологическая сортировка для порядка установки,
  обнаружение циклов, **обратные зависимости** для запрета удаления (чего не было в патч-modman).

## 8. Миграции с владельцем (`migration/`)

- `MigrationOwnershipRepository` — таблица «миграция ↔ модуль» (`{{%modman_migration}}`), поверх штатной
  истории Yii. Решает проблему патч-modman (откат «всех миграций в директории»).
- `ModuleMigrationRunner` — применяет/откатывает строго свои миграции; для update — только pending.
  Захватывает вывод миграций (`create table … done`) в лог-канал `modman/migration`.

## 9. Lifecycle (`lifecycle/`)

- `LifecyclePlanner` строит `LifecyclePlan` — **чистый** объект, ничего не меняющий. `dry-run`
  возвращает его же (полноценный план, а не `json_encode` во flash). Недоступность записи артефактов —
  блокер (а не warning); `planUpdate` проверяет так же строго, как `planInstall`.
- `LifecycleExecutor` — выполняет типизированные `LifecycleStep` под файловым `Mutex`
  (`@runtime/modman_mutex`), с принципом **commit-at-end** (запись реестра — последний шаг) и
  компенсацией только необратимых вне-реестровых эффектов (миграции, директории). После recompile
  обновляет карту представлений темы (`Theme::renewPathMap`) и выгружает весь `OperationReport` в лог.
- Хендлеры: `CheckHandler`, `InstallHandler`, `UninstallHandler`, `UpdateHandler`, `ReconcileHandler`.
- `OperationReport` — DTO результата (что сделано, предупреждения, счётчики); рендерится драйвером.
  Сервисный слой не знает про `session flash` (исправление SRP-проблемы патч-modman).

## 10. События (`events/`)

- `ModuleLifecycleDispatcher` — шина уровня приложения. Lifecycle публикует фазы
  (`beforeInstall/afterInstall/...`), на которые **другие** модули могут подписаться декларативно в
  своём `Bootstrap.php`. В патч-modman события висели на выбрасываемом экземпляре и межмодульные
  интеграции были невозможны.

## 11. Переход (cutover) и образец

Cutover выполнен: менеджер пишет в **канонические** пути конфигурации, которые приложение загружает
напрямую (без суффиксов и аддитивных слияний). Прежний патч-modman сохранён как `app/modules/modman_b`
(нерабочий образец для сверки, вне автозагрузки и discovery — у него нет маркера `extra.bescms`).

Оставшиеся шаги — обкатка операций на реальных модулях, «повышение» контрактов в `common`, затем
удаление `modman_b` (см. «Статус» в [README.md](./README.md)).

## 12. Карта «патч-modman (modman_b) → modman»

| Патч-modman (modman_b) | modman |
|---|---|
| `ModulesManageService` (10 ответственностей) | `lifecycle/*` (план/исполнитель/шаги/хендлеры) |
| `method_exists()` | `contract/Provides*` + `instanceof` |
| статические `getConfig/...` | `ModuleManifest` (value object) |
| инкрементальная правка 4 конфигов + `_backup` | `ConfigCompiler` + `AtomicWriter` (compile, не patch) |
| `InstallationLog` (журнал-компенсация, удаляется) | реестр со статусами + commit-at-end + `ReconcileHandler` |
| `isInstalled() === hasModule()` | `ModuleStatus` (разведены состояния) |
| откат «всех миграций в каталоге» | `MigrationOwnershipRepository` (владение) |
| нет update | `UpdateHandler` (pending-only миграции) |
| зависимости только на install | `DependencyResolver` (+ обратные на uninstall) |
| flash внутри сервиса | `OperationReport` + рендер в драйвере |
| события на выброшенном инстансе | `ModuleLifecycleDispatcher` (app-level) |
| фильтрация пакетов «по директории» | маркер `extra.bescms` (`kind: module|package`) |
