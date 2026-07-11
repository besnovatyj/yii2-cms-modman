# Переезд modman: `app/modules/modman` → composer-пакет `besnovatyj/yii2-cms-modman`

Короткая инструкция по cutover. Пакет уже приведён к раскладке vendor: `src/`,
namespace `Besnovatyj\Modman\`, `moduleId = Modman`. Старый модуль сохранён как
`app/modules/modman.zip` — держим до полной проверки.

modman — **kernel-tier**: ядро зависит от него всегда (как от `kernel`/`contracts`).
«Вынос в пакет» ≠ «сделать опциональным». Это переезд кода + одна bootstrap-строка.

## Шаги

1. **composer.** Path-репозиторий `./packages/besnovatyj/*` уже подключён в корневом
   `app/composer.json`. Добавить в `require`:
   ```json
   "besnovatyj/yii2-cms-modman": "^1.0"
   ```
   и `composer update besnovatyj/yii2-cms-modman` (symlink в `vendor/`).
   Убедиться, что старого автолоада `modules\modman` больше нет (он шёл через алиас
   `@modules`, не через composer — правок в composer для его снятия не требуется).

2. **Bootstrap (единственная жёсткая ссылка ядра на класс modman).**
   `app/common/config/main.php`, массив `bootstrap`:
   ```php
   // было
   \modules\modman\Bootstrap::class,
   // стало
   \Besnovatyj\Modman\Bootstrap::class,
   ```
   Эта строка остаётся в ядре намеренно: modman обязан подняться раньше, чем сможет
   скомпилировать конфиги, — обнаружить себя, чтобы себя же забутстрапить, он не может.

3. **Лог-канал.** `app/common/config/log.php:80`:
   ```php
   // было
   $modmanChannelFile = Yii::getAlias('@modules/modman/config/log.php');
   // стало — брать из класса модуля (единый источник, не зависит от пути установки)
   $modmanChannelFile = \Besnovatyj\Modman\Module::logChannels();
   ```
   Категория лога осталась `modman/*` (строчная) — строка `'except' => ['modman/*', ...]`
   в этом же файле менять НЕ нужно. Имя файла `monolog-modman.log` тоже без изменений.

4. **moduleId `modman` → `Modman`.** Идентификатор модуля стал с большой буквы (конвенция:
   все id модулей капитализированы, как `User`). Следствия в приложении:
   - веб-роут: `/modman/backend/modules/index` → `/Modman/backend/modules/index`
     (проверить пункт меню/ссылки, если где-то в ядре зашит старый путь);
   - консоль: `php yii modman/...` → `php yii Modman/...`;
   - вручную регистрировать модуль в `modules` НЕ нужно: `Bootstrap` саморегистрирует
     `Modman` (см. шаг 6 и «Холодный старт» в README) — работает и в web, и в console.

5. **Discovery / реестр.** modman находит сам себя источником `FilesystemModuleSource`
   (скан `@root/packages/besnovatyj`, см. `src/config/params.php`) либо, после переезда в
   `vendor/`, — `ComposerInstalledModuleSource` (маркер `extra.bescms`). Дубликата не
   будет: старый модуль заархивирован в `.zip` (не директория). Алиас/скан `@modules`
   остаётся для локальных проектных модулей.

6. **Пересборка артефактов (разрыв chicken-and-egg).** Старый `modulesConfigFile.php`
   ещё содержит мёртвый `'modman' => modules\modman\Module`, а руками артефакт править
   нельзя. Ничего страшного: `Bootstrap` саморегистрирует `Modman` независимо от артефакта
   (мёртвый `modman` — lazy, не инстанцируется, пока по нему не пойти), поэтому консольная
   команда доступна сразу. Пересобрать артефакты из каталога:
   ```
   php yii Modman/modules/recompile
   ```
   После этого `modulesConfigFile.php` содержит уже `Modman`, старый `modman` исчезает.

## Проверка

```
# синтаксис
docker compose exec php sh -c 'find /home/node/app/packages/besnovatyj/modman/src -name "*.php" -print0 | xargs -0 -n1 -P4 php -l'

# менеджер жив
php yii Modman/modules/list

# веб
/Modman/backend/modules/index
```

## Итоговый инвариант

Ядро зависит ровно от трёх пакетов: `besnovatyj/yii2-cms-contracts`,
`besnovatyj/yii2-cms-kernel`, `besnovatyj/yii2-cms-modman`. Из ядра на modman ведёт
одна bootstrap-строка (шаг 2) + чтение канала лога (шаг 3) — оба через публичный
`Besnovatyj\Modman\`. Всё остальное modman тянет через discovery/компиляцию.

После полной проверки — удалить `app/modules/modman.zip`.
