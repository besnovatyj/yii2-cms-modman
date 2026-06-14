<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog;

use modules\modmanNew\catalog\exception\ManifestException;
use modules\modmanNew\catalog\source\DiscoveredPackage;
use modules\modmanNew\catalog\source\ModuleSource;

/**
 * Каталог пакетов: агрегирует {@see ModuleSource}-источники, строит манифесты и кэширует результат.
 *
 * Это единственная точка discovery для всей системы. В отличие от старого modman, скан выполняется
 * один раз на запрос (ленивый кэш), а проблемные пакеты не валят список, а копятся в {@see warnings()}.
 */
final class PackageCatalog
{
    /** @var array<string, ModuleManifest>|null id => манифест (кэш) */
    private ?array $manifests = null;

    /** @var DiscoveredPackage[]|null все обнаруженные пакеты (для UI «пакеты») */
    private ?array $packages = null;

    /** @var string[] */
    private array $sourceWarnings = [];

    /** @var string[] */
    private array $manifestWarnings = [];

    /**
     * @param ModuleSource[]  $sources порядок важен: при дубликате moduleId побеждает первый источник
     * @param ManifestFactory $factory
     */
    public function __construct(
        private readonly array           $sources,
        private readonly ManifestFactory $factory,
    ) {}

    /**
     * Манифесты всех валидных модулей, ключ — moduleId, отсортировано по id.
     * @return array<string, ModuleManifest>
     */
    public function manifests(): array
    {
        $this->load();
        return $this->manifests;
    }

    public function findById(string $id): ?ModuleManifest
    {
        return $this->manifests()[$id] ?? null;
    }

    /**
     * Все обнаруженные пакеты (модули и не-модули) — для вкладки «пакеты» в UI.
     * @return DiscoveredPackage[]
     */
    public function packages(): array
    {
        $this->load();
        return $this->packages;
    }

    /**
     * Предупреждения discovery + сборки манифестов (для показа пользователю).
     * @return string[]
     */
    public function warnings(): array
    {
        $this->load();
        return array_merge($this->sourceWarnings, $this->manifestWarnings);
    }

    /**
     * Сбросить кэш (например, после установки нового пакета).
     */
    public function refresh(): void
    {
        $this->manifests = null;
        $this->packages = null;
        $this->sourceWarnings = [];
        $this->manifestWarnings = [];
    }

    private function load(): void
    {
        if ($this->manifests !== null) {
            return;
        }

        $manifests = [];
        $packages = [];
        $seenModuleIds = [];

        foreach ($this->sources as $source) {
            foreach ($source->discover() as $package) {
                $packages[] = $package;

                if (!$package->isModule()) {
                    continue;
                }

                if (isset($seenModuleIds[$package->moduleId])) {
                    $this->manifestWarnings[] = sprintf(
                        "Дубликат moduleId '%s': пакет '%s' пропущен (используется '%s').",
                        $package->moduleId,
                        $package->composerName,
                        $seenModuleIds[$package->moduleId],
                    );
                    continue;
                }

                try {
                    $manifest = $this->factory->fromPackage($package);
                } catch (ManifestException $e) {
                    $this->manifestWarnings[] = $e->getMessage();
                    continue;
                }

                $manifests[$manifest->id] = $manifest;
                $seenModuleIds[$package->moduleId] = $package->composerName;
            }

            foreach ($source->warnings() as $warning) {
                $this->sourceWarnings[] = "[{$source->label()}] {$warning}";
            }
        }

        ksort($manifests);

        $this->manifests = $manifests;
        $this->packages = $packages;
    }
}
