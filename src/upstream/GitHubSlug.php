<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\upstream;

/**
 * Извлекает `owner/repo` из git-URL пакета (`source.url` composer'а) для запросов к GitHub API.
 *
 * Единая точка разбора — чтобы {@see \Besnovatyj\Modman\catalog\source\DiscoveredPackage} и
 * {@see \Besnovatyj\Modman\catalog\ModuleManifest} не дублировали регулярку.
 */
final class GitHubSlug
{
    /**
     * Понимает https и scp-подобный ssh:
     *   https://github.com/besnovatyj/yii2-cms-shop.git → besnovatyj/yii2-cms-shop
     *   git@github.com:besnovatyj/yii2-cms-shop.git     → besnovatyj/yii2-cms-shop
     *
     * @return string|null null, если это не GitHub-URL (upstream-проверка неприменима)
     */
    public static function fromUrl(string $url): ?string
    {
        if ($url === '' || !str_contains($url, 'github.com')) {
            return null;
        }
        if (preg_match('~github\.com[:/]+([^/]+)/(.+?)(?:\.git)?/?$~i', $url, $m) !== 1) {
            return null;
        }
        return "{$m[1]}/{$m[2]}";
    }
}
