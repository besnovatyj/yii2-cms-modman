<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\catalog;

use modules\modman\catalog\exception\ManifestException;
use modules\modman\catalog\source\DiscoveredPackage;
use modules\modman\catalog\source\ModuleSource;
use Yii;

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

    /** @var DiscoveredPackage[]|null CMS-пакеты (для UI «пакеты»); чужие composer-зависимости сюда не попадают */
    private ?array $packages = null;

    /** @var InvalidModule[]|null CMS-модули с ошибкой конфигурации (показываются строкой с причиной) */
    private ?array $invalids = null;

    /** @var string[] инфраструктурные предупреждения источников (директория не найдена и т.п.) */
    private array $sourceWarnings = [];

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
     * CMS-пакеты (модули и не-модули) — для вкладки «пакеты» в UI. Только помеченные `extra.bescms`.
     * @return DiscoveredPackage[]
     */
    public function packages(): array
    {
        $this->load();
        return $this->packages;
    }

    /**
     * CMS-модули с ошибкой конфигурации — для показа строкой рядом с причиной (не flash).
     * @return InvalidModule[]
     */
    public function invalids(): array
    {
        $this->load();
        return $this->invalids;
    }

    /**
     * Инфраструктурные предупреждения источников (для показа пользователю flash'ем).
     *
     * Сюда попадают только реально требующие внимания вещи уровня discovery (директория сканирования
     * не найдена, installed.json нечитаем). Проблемы отдельных модулей идут не сюда, а в
     * {@see invalids()} (строкой в UI) и в канал лога `modman/*`.
     *
     * @return string[]
     */
    public function warnings(): array
    {
        $this->load();
        return $this->sourceWarnings;
    }

    /**
     * Сбросить кэш (например, после установки нового пакета).
     */
    public function refresh(): void
    {
        $this->manifests = null;
        $this->packages = null;
        $this->invalids = null;
        $this->sourceWarnings = [];
    }

    private function load(): void
    {
        if ($this->manifests !== null) {
            return;
        }

        $manifests = [];
        $packages = [];
        $this->invalids = [];
        $seenModuleIds = [];
        $seenPackages = [];

        foreach ($this->sources as $source) {
            foreach ($source->discover() as $package) {
                // Чужой composer-пакет (нет маркера extra.bescms) — менеджер его не показывает вовсе.
                if (!$package->isCmsPackage()) {
                    continue;
                }

                // Один и тот же пакет может прийти из нескольких источников (filesystem + installed.json,
                // т.к. в dev пакеты symlink'нуты как path-репозитории). Это не дубликат — побеждает первый
                // источник, копию тихо пропускаем (никаких предупреждений).
                if (isset($seenPackages[$package->composerName])) {
                    continue;
                }
                $seenPackages[$package->composerName] = true;

                $packages[] = $package;

                if (!$package->isModule()) {
                    continue;
                }

                try {
                    $manifest = $this->factory->fromPackage($package);
                } catch (ManifestException $e) {
                    $this->addInvalid($package, $e->getMessage());
                    continue;
                }

                // Два РАЗНЫХ пакета на один moduleId — настоящий конфликт, показываем как невалидный.
                if (isset($seenModuleIds[$manifest->id])) {
                    $this->addInvalid(
                        $package,
                        sprintf("Дубликат moduleId '%s' — уже используется пакетом '%s'.", $manifest->id, $seenModuleIds[$manifest->id]),
                        $manifest->id,
                    );
                    continue;
                }

                $manifests[$manifest->id] = $manifest;
                $seenModuleIds[$manifest->id] = $package->composerName;
            }

            foreach ($source->warnings() as $warning) {
                $this->sourceWarnings[] = "[{$source->label()}] {$warning}";
            }
        }

        ksort($manifests);

        $this->manifests = $manifests;
        $this->packages = $packages;
    }

    /**
     * Регистрирует невалидный CMS-модуль: запись для UI (строка с причиной) + след в логе для диагностики.
     * Намеренно НЕ во flash — нет смысла «кричать» о неустановившейся кнопке, причина видна рядом с модулем.
     */
    private function addInvalid(DiscoveredPackage $package, string $reason, ?string $declaredId = null): void
    {
        $this->invalids[] = new InvalidModule(
            package: $package->composerName,
            declaredId: $declaredId ?? $package->moduleId,
            reason: $reason,
        );
        Yii::warning("{$package->composerName}: {$reason}", 'modman/discovery');
    }
}
