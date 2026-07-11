<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\catalog\source;

/**
 * «Сырой» обнаруженный пакет — данные composer.json + путь, без создания каких-либо объектов модуля.
 *
 * Источники {@see ModuleSource} возвращают именно такие DTO. Превращение в типизированный
 * {@see \Besnovatyj\Modman\catalog\ModuleManifest} — задача {@see \Besnovatyj\Modman\catalog\ManifestFactory}.
 */
final readonly class DiscoveredPackage
{
    /**
     * @param array<string,string> $require       зависимости composer (name => constraint)
     * @param array<string,string> $autoloadPsr4  PSR-4 префикс => относительный путь
     * @param array<string, string|string[]> $configPlugin  `extra.config-plugin` (group => file|files),
     *        конвенция yiisoft/config; читается modman'ом для merge-plan (плагин самого yiisoft/config
     *        отключён). Пути относительны корня пакета.
     */
    public function __construct(
        public string     $composerName,
        public string     $description,
        public string     $path,
        public string     $type,
        public string     $composerVersion,
        public string     $license,
        public ?string    $moduleClass,
        public ?string    $moduleId,
        public ?CmsMarker $cmsMarker,
        public array      $require,
        public array      $autoloadPsr4,
        public array      $configPlugin,
        public string     $sourceLabel,
    ) {}

    /**
     * Собирает DTO из распарсенного composer.json. Возвращает null, если это не похоже на пакет.
     */
    public static function fromComposerArray(array $data, string $path, string $sourceLabel): ?self
    {
        if (!isset($data['name']) || !is_string($data['name'])) {
            return null;
        }

        $extra = $data['extra'] ?? [];
        $moduleClass = (isset($extra['moduleClass']) && is_string($extra['moduleClass'])) ? $extra['moduleClass'] : null;
        $moduleId = (isset($extra['moduleId']) && is_string($extra['moduleId'])) ? $extra['moduleId'] : null;

        $psr4 = $data['autoload']['psr-4'] ?? [];
        $configPlugin = (is_array($extra) && isset($extra['config-plugin']) && is_array($extra['config-plugin']))
            ? $extra['config-plugin']
            : [];

        // Лицензия в composer.json может быть строкой или массивом (как у старого modman PackageInfo).
        $license = '';
        if (isset($data['license'])) {
            $license = is_array($data['license']) ? implode(', ', $data['license']) : (string)$data['license'];
        }

        return new self(
            composerName: $data['name'],
            description: (string)($data['description'] ?? ''),
            path: $path,
            type: (string)($data['type'] ?? ''),
            composerVersion: (string)($data['version'] ?? ''),
            license: $license,
            moduleClass: $moduleClass,
            moduleId: $moduleId,
            cmsMarker: is_array($extra) ? CmsMarker::fromExtra($extra) : null,
            require: is_array($data['require'] ?? null) ? $data['require'] : [],
            autoloadPsr4: is_array($psr4) ? $psr4 : [],
            configPlugin: $configPlugin,
            sourceLabel: $sourceLabel,
        );
    }

    /**
     * Принадлежит ли пакет данной CMS (есть маркер `extra.bescms`). Только такие пакеты менеджер
     * вообще показывает — чужие composer-зависимости из `vendor/` сюда не попадают.
     */
    public function isCmsPackage(): bool
    {
        return $this->cmsMarker !== null;
    }

    public function cmsKind(): ?CmsKind
    {
        return $this->cmsMarker?->kind;
    }

    /**
     * Объявлен ли пакет управляемым модулем (`extra.bescms.kind=module`).
     *
     * Это намерение, а не гарантия валидности: наличие/корректность moduleClass и контракта
     * проверяет {@see \Besnovatyj\Modman\catalog\ManifestFactory}.
     */
    public function isModule(): bool
    {
        return $this->cmsMarker?->kind === CmsKind::Module;
    }
}
