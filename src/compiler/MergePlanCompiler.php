<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

use Besnovatyj\Modman\catalog\ModuleManifest;
use Besnovatyj\Modman\catalog\PackageCatalog;
use Besnovatyj\Modman\registry\ModuleRegistry;

/**
 * Компилирует merge-plan для движка {@see \Yiisoft\Config\Config} (Yii3) из реестра активных модулей.
 *
 * Заменяет сериализацию конфига (`ArrayExportHelper`) планом «пути + порядок»: сборка и слияние —
 * в рантайме `require`+merge, замыкания живут в PHP-файлах модулей и НЕ сериализуются. Плагин самого
 * yiisoft/config отключён (`allow-plugins: {"yiisoft/config": false}`), потому что он включал бы все
 * установленные пакеты мимо реестра — источник истины об активном наборе остаётся у modman.
 *
 * Формат артефакта (совместим с `new MergePlan(require ...)`): `[environment][group][package][] = file`.
 *  - `environment` — `'/'` (env-слой не используем; dev/prod даёт `app/init` через `*-local.php`);
 *  - `group` — приложение (`common`, `app-backend`, …), берётся из `extra.config-plugin` пакета;
 *  - `package` — composer-имя модуля, либо `'/'` для root-пакета (приложения);
 *  - `file` — путь конфиг-файла относительно корня пакета.
 *
 * БЕЗОПАСНОСТЬ: root-пакет добавляется в КАЖДУЮ группу ПОСЛЕДНИМ. Модель слоёв yiisoft/config
 * (vendor < root) + порядок => ядро перекрывает модули. Поэтому `as access.class` задаёт только ядро,
 * а модуль может лишь дополнить `as access.allowActions` — инвариант замка держится порядком, без
 * отдельной allowlist-политики на содержимое файлов (их modman не исполняет).
 */
final class MergePlanCompiler
{
    private const string ENVIRONMENT = '/';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PackageCatalog $catalog,
        private readonly AtomicWriter   $writer,
        private readonly string         $mergePlanPath,
        /** @var array<string, string|string[]> root config-plugin (group => file|files), из корневого composer.json */
        private readonly array          $rootConfigPlugin,
        private readonly string         $rootPackageName = '/',
    ) {}

    /**
     * Собрать и атомарно записать merge-plan. Идемпотентно.
     */
    public function recompile(): void
    {
        $this->writer->writeArray($this->mergePlanPath, $this->build());
    }

    /**
     * Чистая сборка плана (без записи).
     *
     * @return array<string, array<string, array<string, string[]>>> [env][group][package][]=file
     */
    public function build(): array
    {
        /** @var array<string, array<string, string[]>> $groups group => package => files */
        $groups = [];

        // 1) Вклады активных модулей (vendor-слой).
        foreach ($this->activeManifests() as $manifest) {
            foreach ($manifest->contributions->configPlugin as $group => $files) {
                foreach ((array)$files as $file) {
                    $groups[$group][$manifest->package][] = (string)$file;
                }
            }
        }

        // Детерминизм: пакеты внутри группы — по имени.
        foreach ($groups as &$byPackage) {
            ksort($byPackage);
        }
        unset($byPackage);

        // 2) Root-пакет (приложение) — ПОСЛЕДНИМ в каждой группе, чтобы перекрывать модули.
        foreach ($this->rootConfigPlugin as $group => $files) {
            $groups[$group][$this->rootPackageName] = array_values(array_map('strval', (array)$files));
        }

        ksort($groups);

        return [self::ENVIRONMENT => $groups];
    }

    /**
     * Активный набор модулей: системные (editable=false) всегда + согласованно установленные через реестр.
     * Логика совпадает с {@see ConfigCompiler} — единый критерий активности.
     *
     * @return array<string, ModuleManifest> id => манифест
     */
    private function activeManifests(): array
    {
        $result = [];

        foreach ($this->catalog->manifests() as $id => $manifest) {
            if (!$manifest->editable) {
                $result[$id] = $manifest;
            }
        }

        foreach ($this->registry->all() as $id => $state) {
            if (!$state->status->isActive() || isset($result[$id])) {
                continue;
            }
            $manifest = $this->catalog->findById($id);
            if ($manifest !== null) {
                $result[$id] = $manifest;
            }
        }

        return $result;
    }
}
