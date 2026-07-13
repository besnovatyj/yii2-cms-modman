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
     * @param string[] $composerBootstrap классы из `extra.bootstrap` (L1 — yii2-composer выполняет их
     *        КАЖДЫЙ запрос, пока пакет установлен, вне гейта modman); нормализовано к списку.
     * @param string $sourceReference SHA коммита из `source.reference` installed.json (есть только у
     *        composer-источника). Точный сигнал «код на диске сменился»; для dev-версий заменяет тег.
     * @param string $sourceUrl git-URL из `source.url` installed.json — из него выводятся owner/repo
     *        для запроса upstream-версии к GitHub API (см. {@see githubSlug()}).
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
        public array      $composerBootstrap = [],
        public string     $sourceReference = '',
        public string     $sourceUrl = '',
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

        // `extra.bootstrap` (L1) по конвенции yii2-composer — строка или список классов.
        $composerBootstrap = [];
        if (is_array($extra) && isset($extra['bootstrap'])) {
            $composerBootstrap = is_array($extra['bootstrap'])
                ? array_values(array_filter($extra['bootstrap'], 'is_string'))
                : (is_string($extra['bootstrap']) ? [$extra['bootstrap']] : []);
        }

        // `source` есть только в installed.json (composer-источник); в сыром compos.json пакета — нет.
        $source = is_array($data['source'] ?? null) ? $data['source'] : [];

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
            composerBootstrap: $composerBootstrap,
            sourceReference: (string)($source['reference'] ?? ''),
            sourceUrl: (string)($source['url'] ?? ''),
        );
    }

    /**
     * Короткий (7 символов) SHA коммита установленного кода — для отображения dev-версий.
     */
    public function shortReference(): string
    {
        return $this->sourceReference === '' ? '' : substr($this->sourceReference, 0, 7);
    }

    /**
     * `owner/repo` из git-URL источника (`source.url`) — для запроса upstream-версии к GitHub API.
     *
     * @return string|null null, если это не GitHub-URL (upstream-проверка неприменима)
     */
    public function githubSlug(): ?string
    {
        return \Besnovatyj\Modman\upstream\GitHubSlug::fromUrl($this->sourceUrl);
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
