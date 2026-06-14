<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\catalog\source;

use Throwable;
use Yii;

/**
 * Источник обнаружения через реестр Composer (`vendor/composer/installed.json`).
 *
 * Это «продакшен-путь»: менеджер работает с тем, что реально установлено composer'ом, не завися от
 * структуры директорий. Каждый установленный пакет уже содержит type/extra/autoload в installed.json.
 */
final class ComposerInstalledModuleSource implements ModuleSource
{
    /** @var string[] */
    private array $warnings = [];

    /**
     * @param string $installedJsonPath Путь (или alias) к vendor/composer/installed.json
     */
    public function __construct(
        private readonly string $installedJsonPath = '@vendor/composer/installed.json',
    ) {}

    public function label(): string
    {
        return 'composer';
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function discover(): array
    {
        $path = Yii::getAlias($this->installedJsonPath, false);
        if ($path === false || !is_file($path)) {
            $this->warnings[] = "installed.json не найден: {$this->installedJsonPath}";
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->warnings[] = "installed.json не читается: {$path}";
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->warnings[] = 'Невалидный JSON в installed.json (' . json_last_error_msg() . ')';
            return [];
        }

        // Composer 2: {"packages": [...]}; путь установки относителен vendor/composer/.
        $packages = $data['packages'] ?? $data;
        $baseDir = dirname($path);

        $result = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            try {
                $installPath = isset($pkg['install-path'])
                    ? $this->normalizePath($baseDir . DIRECTORY_SEPARATOR . $pkg['install-path'])
                    : $baseDir;

                $package = DiscoveredPackage::fromComposerArray($pkg, $installPath, $this->label());
                if ($package !== null) {
                    $result[] = $package;
                }
            } catch (Throwable $e) {
                Yii::error($e, 'modman/' . __METHOD__);
                $this->warnings[] = "Пакет installed.json пропущен: " . ($pkg['name'] ?? '?') . " — {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * Нормализует путь с '..'/'.' без обращения к ФС (realpath может не сработать на симлинках).
     */
    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
