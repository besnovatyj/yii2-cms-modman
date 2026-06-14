<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\catalog\source;

use DirectoryIterator;
use Throwable;
use Yii;

/**
 * Источник обнаружения через скан директорий файловой системы.
 *
 * Каждая поддиректория сканируемого каталога, содержащая composer.json в корне, считается пакетом.
 * Скан устойчив к ошибкам: битый пакет не валит весь список, а попадает в {@see warnings()}.
 * Подходит для dev (символьно-слинкованные path-пакеты, локальные модули).
 */
final class FilesystemModuleSource implements ModuleSource
{
    /** @var string[] Абсолютные пути сканируемых директорий */
    private array $resolvedDirs = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param string[] $scanDirs Пути или алиасы директорий с пакетами
     */
    public function __construct(array $scanDirs)
    {
        foreach ($scanDirs as $dir) {
            $resolved = Yii::getAlias($dir, false);
            if ($resolved !== false && is_dir($resolved)) {
                $this->resolvedDirs[] = $resolved;
            } else {
                $this->warnings[] = "Директория сканирования не найдена: {$dir}";
            }
        }
    }

    public function label(): string
    {
        return 'filesystem';
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function discover(): array
    {
        $packages = [];

        foreach ($this->resolvedDirs as $dir) {
            foreach (new DirectoryIterator($dir) as $entry) {
                if ($entry->isDot() || !$entry->isDir()) {
                    continue;
                }

                $composerJson = $entry->getPathname() . DIRECTORY_SEPARATOR . 'composer.json';
                if (!is_file($composerJson)) {
                    continue;
                }

                try {
                    $package = $this->readPackage($composerJson, $entry->getPathname());
                    if ($package !== null) {
                        $packages[] = $package;
                    }
                } catch (Throwable $e) {
                    Yii::error($e, 'modmanNew/' . __METHOD__);
                    $this->warnings[] = "Пакет пропущен из-за ошибки: {$entry->getPathname()} — {$e->getMessage()}";
                }
            }
        }

        return $packages;
    }

    private function readPackage(string $composerJsonPath, string $path): ?DiscoveredPackage
    {
        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            $this->warnings[] = "composer.json не читается: {$composerJsonPath}";
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->warnings[] = "Невалидный JSON в composer.json: {$composerJsonPath} (" . json_last_error_msg() . ')';
            return null;
        }

        return DiscoveredPackage::fromComposerArray($data, $path, $this->label());
    }
}
