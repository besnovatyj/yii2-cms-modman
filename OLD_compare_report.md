# Анализ модулей `modman` и `modman_b`

Дата анализа: 2026-07-02.

## Краткий вывод

Новая версия `app/modules/modman` архитектурно значительно сильнее старой `app/modules/modman_b`: она заменяет patch-based правку PHP-конфигов на модель `compile-not-patch`, вводит единый реестр состояния, lifecycle-планировщик, атомарную запись артефактов, владельца миграций, обратные зависимости, update/reconcile и UI-agnostic сервисный слой. По основной функциональности старого модуля критичной потери не видно: установка, удаление, dry-run/план, меню, компоненты, bootstrap, log channels, опции, директории, миграции, права записи, opcache invalidation, обновление карты темы и CLI-пересборка меню покрыты.

Удалять `modman_b` уже можно только если принято условие cutover: все реальные управляемые модули должны быть переведены на новый контракт `DeclaresModule`/`Provides*` и получить маркер `extra.bescms`. Без этого новая версия намеренно не будет считать старые пакеты управляемыми модулями. В текущем состоянии сам `README.md`/`plan.md` нового `modman` прямо фиксирует, что модуль на стадии тестирования и фаза обкатки на реальных модулях ещё не завершена.

Главные риски перед удалением старого модуля:

1. `php -l` и реальные lifecycle-сценарии не проверены в текущем окружении: бинарника `php` нет.
2. Старые модули без `extra.bescms` и новых интерфейсов будут проигнорированы или показаны как невалидные.
3. Зависимости от системных `editable=false` модулей без записи в registry могут считаться отсутствующими.
4. `reconcile` для статуса `updating` возвращает статус `installed`, но не сверяет фактическую версию/checksum/миграции.
5. Поведение старой конвенции миграций `{basePath}/migrations` без явного объявления в модуле не перенесено: теперь нужен `ProvidesMigrations`.

## Что проверялось

Проверены файлы:

- `app/modules/modman`: документация, bootstrap, DI, catalog/discovery, registry, compiler, deps, migration, lifecycle, web/CLI controllers, views, search form.
- `app/modules/modman_b`: старые service/repository/controller/entity/config/menu/migration реализации.

Команда синтаксической проверки была запущена:

```bash
find app/modules/modman app/modules/modman_b -path app/modules/modman/.git -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

Результат: проверить не удалось, потому что в текущем окружении нет `php`:

```text
xargs: php: No such file or directory
```

## Архитектура новой версии

### Сильные стороны

`modman` хорошо разделён на слои:

- `contract/` - capability-интерфейсы вместо `method_exists()`;
- `catalog/` - discovery пакетов, манифесты, валидация `composer.json`;
- `registry/` - единый файл состояния `modules-state.php`;
- `compiler/` - детерминированная компиляция производных конфигов;
- `deps/` - semver, топологическая сортировка, прямые и обратные зависимости;
- `migration/` - запуск миграций с таблицей владения;
- `lifecycle/` - планирование, блокировка, шаги, commit-at-end, rollback/reconcile;
- `events/` - app-level lifecycle events;
- `ModuleManager` - фасад для web/CLI.

Это исправляет главную проблему `modman_b`: состояние больше не размазано по `modulesConfigFile.php`, `bootstrapComponentsAndModulesConfigFile.php`, `componentsConfigFile.php`, `logChannelsConfigFile.php`, меню и backup-файлам. Новая модель делает реестр единственным источником истины, а все конфиги - производными артефактами.

Отдельно положительно:

- `Bootstrap` поднимает DI и лог-канал `modman/*` на холодном старте.
- Системные модули `editable=false` компилируются всегда, даже без записи в registry.
- `ConfigCompiler` сортирует ключи и пишет все артефакты через `AtomicWriter`.
- `LifecycleExecutor` выполняет операции под `FileMutex`.
- `InstallHandler` использует write-ahead intent (`installing`) и commit-at-end.
- `UninstallHandler` запрещает удаление через обратные зависимости.
- `UpdateHandler` появился как отдельный сценарий.
- `ReconcileHandler` закрывает зависшие `installing/updating/removing/failed`.
- `ModuleMigrationRunner` ведёт `{{%modman_migration}}` и синхронизирует `{{%migration}}`.
- Web-контроллер стал тонким, сервисы больше не завязаны на session flash.
- CLI покрывает list/check/install/uninstall/update/reconcile/recompile, а `MenuController` покрывает диагностику и пересборку меню.

### Архитектурные вопросы

Контракты сейчас лежат в `modules\modman\contract`. Это удобно для разработки, но заставляет каждый модуль зависеть от менеджера модулей. В документации это уже отмечено как будущий перенос в `common\components\module`. До удаления `modman_b` это не блокер, но до массовой конвертации модулей лучше принять окончательное место контрактов, иначе придётся править все модули дважды.

Системные модули в compiler считаются активными по `editable=false`, но dependency resolver проверяет зависимости только через `ModuleRegistry::isInstalled()`. Если модуль зависит от системного модуля, который активен в приложении, но не записан в registry, зависимость будет считаться отсутствующей.

`LifecyclePlanner::detectConflicts()` проверяет компоненты с `Yii::$app->has($name)` и bootstrap-дубликаты среди registry-модулей. Bootstrap-дубликаты с системными модулями вне registry явно не проверяются, хотя compiler затем дедуплицирует bootstrap-классы.

## Функциональный паритет со старым `modman_b`

### Discovery и список пакетов

Старый `modman_b` сканировал директории и считал модулем любой пакет с `extra.moduleClass` и `extra.moduleId`. Новая версия показывает только CMS-пакеты с `extra.bescms.kind`. Это намеренное изменение, но оно ломает совместимость со старыми модулями.

Паритет:

- сканирование `@modules` и `@root/packages/besnovatyj` есть;
- composer installed source для production добавлен;
- список пакетов есть;
- лицензия и require отображаются;
- дубликаты и невалидные модули не валят страницу.

Потеря/изменение:

- старые пакеты без `extra.bescms` теперь вообще не видны;
- старые классы, наследующие `BaseModule`, но не реализующие `DeclaresModule`, не управляются;
- старый fallback “модуль валиден, если наследует `BaseModule`” заменён на новый контракт.

### Установка

Старый `ModulesManageService::moduleInstall()` делал:

- before install event на экземпляре модуля;
- dependency check;
- conflict check;
- миграции;
- директории `@static/origin/<moduleId>` и `@static/cache/<moduleId>`;
- patch bootstrap/components/logChannels;
- patch modules config;
- runtime `Yii::$app->setModule`;
- rebuild menu;
- `Theme::renewPathMap()`;
- opcache invalidate;
- after install event;
- rollback через `InstallationLog`.

Новая версия покрывает это иначе:

- before/after события через `ModuleLifecycleDispatcher`;
- зависимости через `DependencyResolver`;
- конфликты через `LifecyclePlanner`;
- миграции через `RunMigrationsStep`;
- директории через `CreateDirectoriesStep`;
- modules/bootstrap/components/log/options/menu собираются `ConfigCompiler`;
- карта темы обновляется в `LifecycleExecutor`;
- opcache инвалидируется в `AtomicWriter`;
- rollback через компенсацию шагов + откат registry + recompile.

Функционально установка покрыта. Архитектурно новая версия лучше, потому что исключает частичную patch-запись нескольких файлов.

### Удаление

Старый `moduleUninstall()`:

- запрещал удалять `getEditable() === false`;
- откатывал миграции по файлам из каталога;
- удалял директории;
- удалял bootstrap/components/logChannels/modules config;
- пересобирал меню и карту темы;
- не имел полноценной проверки обратных зависимостей.

Новая версия:

- запрещает удаление системного модуля;
- проверяет обратные зависимости;
- откатывает миграции строго по ownership-таблице;
- удаляет директории из `ProvidesDirectories`;
- удаляет состояние из registry;
- пересобирает производные артефакты.

Паритет есть, новая версия безопаснее по миграциям и зависимостям.

### Миграции

Новая версия лучше старой:

- есть `{{%modman_migration}}` как владелец миграций;
- стандартная `{{%migration}}` синхронизируется;
- uninstall откатывает только миграции модуля;
- update применяет только pending;
- вывод миграций логируется.

Изменение поведения:

- старая версия умела найти миграции по `getMigrationsPath()` или по конвенции `{basePath}/migrations`;
- новая версия требует `ProvidesMigrations::migrationPath()` и `migrationNamespace()`;
- если файл миграции исчез, новая версия чистит историю и предупреждает, но таблицы могут остаться.

### Директории статики

Старый модуль всегда создавал/удалял две директории по конвенции:

- `@static/origin/<moduleId>`;
- `@static/cache/<moduleId>`.

Новая версия создаёт только то, что модуль явно отдаёт через `ProvidesDirectories`. Это гибче, но не совместимо автоматически со старыми модулями, где включение было через `config.params.directories`. При конвертации модулей надо явно добавить эти директории, если они нужны.

### Меню

Паритет есть:

- поддержан legacy-формат группы с `items`;
- поддержан новый формат `_meta.placements`;
- есть сортировка по `priority/groupPriority`;
- есть несколько menu locations;
- есть точечная пересборка меню.

Новая версия лучше тем, что меню компилируется из манифестов, а не собирается из текущего `Yii::$app->modules`.

Нюанс: старый `rebuildAdminMenu()` возвращал `false`, если меню пустое, и мог трактовать это как ошибку. Новая версия корректно записывает пустые меню для включённых locations.

### Компоненты, bootstrap, log channels

Паритет есть:

- `ProvidesComponents` заменяет `getComponentsConfig()`;
- `ProvidesBootstrap` заменяет `getBootstrap()`;
- `ProvidesLogChannels` заменяет `getLogChannels()`;
- конфликт компонентов проверяется до установки;
- log-канал самого `modman` поднимается и на холодном старте.

Изменение:

- старый `addBootstrap()` проверял `class_exists()` и `is_subclass_of(BootstrapInterface::class)`;
- новая версия такой проверки для bootstrap-классов в `ManifestFactory`/`Planner` явно не делает. Если модуль вернёт несуществующий или неправильный bootstrap-класс, ошибка проявится позже на уровне Yii bootstrap.

### Опции

Паритет есть:

- старый `getOptions()` заменён на `ProvidesOptions`;
- новая версия компилирует `moduleOptions.php`;
- кнопка “Настройки” в UI сохранена при наличии options.

### Проверки и dry-run

Новая версия лучше:

- dry-run стал `LifecyclePlan`, а не JSON во flash;
- права записи стали blockers;
- install/update/uninstall имеют отдельные планы;
- web action `check` ничего не меняет.

### UI и CLI

Новая версия функционально шире:

- список модулей и пакетов;
- фильтры по статусу, поиск, “только с обновлениями”;
- invalid/orphan/pending состояния;
- install/uninstall/update/reconcile/recompile/rebuild menus;
- console-драйверы поверх того же фасада.

## Корректность текущей кодовой базы

### Найденные риски

1. Проверка синтаксиса не выполнена из-за отсутствия `php` в окружении. Нельзя утверждать, что все файлы синтаксически корректны.

2. В `ModuleManager::modules()` системный модуль считается `system=true` только если `Yii::$app->hasModule($id)`. При этом `ConfigCompiler` всегда компилирует все `editable=false` модули. Если в каталоге появится `editable=false` пакет, не подключённый реально в приложение, compiler всё равно добавит его в modules config при recompile. Это может быть желаемой моделью “системные всегда активны”, но тогда UI и compiler используют разные критерии активности.

3. `DependencyResolver::assertCanInstall()` не учитывает системные модули вне registry. Для зависимостей на `modman` или другой системный модуль это может дать ложный blocker.

4. `LifecyclePlanner::detectConflicts()` не валидирует bootstrap-классы на существование и `BootstrapInterface`. Старый `modman_b` это делал.

5. `ReconcileHandler` для `Updating` просто возвращает статус `Installed`, не сверяя фактически применённые миграции, версию и checksum. После аварии в середине update возможна “успешная” запись старого состояния при частично применённых миграциях.

6. `ModuleMigrationRunner::up()` при обнаружении миграции в стандартной истории закрепляет владельца, но не возвращает эту версию в `$applied`. В registry `appliedMigrations` она не попадёт, хотя ownership-таблица будет корректной. Сейчас это не ломает uninstall, потому что он смотрит ownership, но поле `ModuleState::appliedMigrations` становится неполным.

7. Новая модель директорий требует `ProvidesDirectories`. Если старые модули полагались на `params.directories`, эта функциональность не сработает без ручной конвертации.

8. Старый `schema.php` и декларативная схема БД в `modman_b/config/schema.php` не перенесены. В самом старом сервисе фактическая установка уже использовала Yii migrations, а schema выглядела deprecated/неиспользуемой, поэтому это не выглядит критичной потерей.

9. `ModuleRegistry::fromArray()` использует `ModuleStatus::from()`. Если файл registry будет повреждён или содержать неизвестный статус, загрузка упадёт. Для системного файла это допустимо, но reconcile не сможет помочь при такой порче.

10. `ConfigCompiler::mergeNamed()` при принудительном `recompile()` оставляет первый компонент/log-channel и пишет warning. Если конфликт уже попал в registry исторически, recompile не падает. Это pragmatically хорошо для восстановления, но важно отслеживать warnings.

### Что выглядит корректным

- `Bootstrap` регистрирует DI до работы контроллеров и команд.
- `AtomicWriter` пишет через tmp + rename и инвалидирует opcache.
- `LifecycleLock` закрывает параллельные lifecycle-операции.
- `InstallHandler` пишет intent под lock, а не до lock.
- `UninstallHandler` помечает неудачное удаление как `failed`.
- `ConfigCompiler` всегда добавляет сам `modman` как системный модуль.
- `MenuCompiler` сохраняет старый и новый формат меню.
- `OperationReport` отделяет бизнес-логику от web flash.
- Web mutation actions используют POST и hidden `moduleId`.

## Потеря функциональности при переезде с `modman_b`

### Не потеряно

- установка модуля;
- удаление модуля;
- dry-run/проверка установки;
- rebuild admin menu;
- menu locations;
- legacy menu format;
- config/options/log/adminMenu вклад модуля;
- компоненты;
- bootstrap;
- log channels;
- обновление карты темы;
- opcache invalidation;
- проверка прав записи;
- миграции;
- логирование установки/удаления/миграций;
- запрет управления системным модулем;
- web UI;
- console rebuild menu.

### Улучшено

- атомарность записи;
- единый источник истины;
- rollback через registry + recompile;
- ownership миграций;
- update lifecycle;
- обратные зависимости при uninstall;
- reconcile зависших операций;
- отдельный фасад для web/CLI;
- обработка invalid/orphan/pending;
- composer installed source;
- semver через `composer/semver`.

### Потеряно или требует явной миграции

- автоматическое признание модулем по `extra.moduleClass/moduleId` без `extra.bescms`;
- поддержка старого `BaseModule` API без новых интерфейсов;
- авто-миграции по конвенции `{basePath}/migrations`;
- авто-директории через `config.params.directories`;
- валидация bootstrap-класса на `BootstrapInterface` до записи;
- события старого `BaseModule::EVENT_BEFORE_INSTALL/AFTER_INSTALL/...` на экземпляре модуля заменены новой шиной. Если в старых модулях были подписчики именно на эти события экземпляра, их надо перенести на `ModuleLifecycleDispatcher`.

## Рекомендации перед удалением `modman_b`

1. Запустить `php -l` внутри Docker/PHP контейнера для `app/modules/modman`.

2. Выбрать 1-3 реальных модуля и прогнать полный сценарий:
   - discovery;
   - check install;
   - install;
   - проверка `modulesConfigFile.php`, bootstrap, components, logChannels, options, menu;
   - проверка `{{%migration}}` и `{{%modman_migration}}`;
   - update с новой миграцией;
   - uninstall;
   - reconcile после искусственно созданных `installing/updating/removing/failed`.

3. До массовой конвертации решить, где будут жить контракты: оставить в `modman\contract` или перенести в `common\components\module`.

4. Для каждого старого модуля составить checklist конвертации:
   - добавить `extra.bescms.kind=module`;
   - реализовать `DeclaresModule`;
   - перенести `getConfig()` в `moduleConfig()`;
   - перенести `getDependencies()` в `ProvidesDependencies`;
   - перенести `getComponentsConfig()` в `ProvidesComponents`;
   - перенести `getBootstrap()` в `ProvidesBootstrap`;
   - перенести `getAdminMenu()` в `ProvidesAdminMenu`;
   - перенести `getOptions()` в `ProvidesOptions`;
   - перенести `getLogChannels()` в `ProvidesLogChannels`;
   - явно объявить миграции через `ProvidesMigrations`;
   - явно объявить директории через `ProvidesDirectories`.

5. Добавить проверку bootstrap-классов в `ManifestFactory` или `LifecyclePlanner`.

6. Уточнить модель системных модулей: либо считать `editable=false` всегда активными и учитывать их в dependencies/conflicts/UI одинаково, либо компилировать только фактически активные системные модули.

7. Усилить `ReconcileHandler` для `updating`: сверять ownership-миграции, checksum и версию, либо явно переводить модуль в `failed` при неоднозначности.

## Итог

`modman` уже покрывает старый `modman_b` по основной функциональности и архитектурно выглядит правильным направлением. Старый модуль можно удалять после практической обкатки и конвертации реальных модулей на новый контракт. До этой обкатки удаление `modman_b` рискованно не потому, что новая архитектура неполная, а потому что новая версия намеренно несовместима со старым способом объявления модулей.
