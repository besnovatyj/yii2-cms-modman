<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\upstream;

use Throwable;
use Yii;
use yii\caching\CacheInterface;

/**
 * Узнаёт последнюю версию пакета по тегам репозитория GitHub (`/repos/{owner}/{repo}/tags`).
 *
 * Запрос идёт с БЭКЕНДА через curl — намеренно, а не из браузера: так токен GitHub не утекает в JS,
 * нет CORS и не нужен фронтовый бандл. Результат кэшируется в компоненте `cache` приложения (apcu),
 * поэтому повторный просмотр списка модулей не бьёт по API. Rate-limit GitHub (60 запросов/час без
 * токена, 5000 — с токеном) считывается из заголовков ответа и отдаётся в UI как уведомление.
 *
 * Сервис ничего не знает про модули — на входе `owner/repo`, на выходе {@see UpstreamVersion}.
 */
final class GitHubTagFetcher
{
    private const string API = 'https://api.github.com/repos/%s/tags?per_page=100';
    private const string CACHE_PREFIX = 'modman.upstream.';

    /**
     * @param CacheInterface|null $cache      кэш ответов (null — работаем без кэша)
     * @param int                 $ttl        TTL успешного ответа, сек
     * @param int                 $errorTtl   TTL ошибки/лимита, сек (короче — чтобы быстрее повторить)
     * @param int                 $timeout    таймаут HTTP-запроса, сек
     */
    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 3600,
        private readonly int $errorTtl = 300,
        private readonly int $timeout = 5,
    ) {}

    /**
     * @param string $slug  owner/repo
     * @param string $token GitHub PAT (пусто — анонимно)
     * @param bool   $force игнорировать кэш (кнопка «обновить»)
     */
    public function fetch(string $slug, string $token = '', bool $force = false): UpstreamVersion
    {
        $key = self::CACHE_PREFIX . md5($slug . '|' . ($token !== '' ? 'auth' : 'anon'));

        if (!$force && $this->cache !== null) {
            $hit = $this->cache->get($key);
            if ($hit instanceof UpstreamVersion) {
                return $hit->withCached(true);
            }
        }

        $result = $this->request($slug, $token);

        if ($this->cache !== null) {
            $this->cache->set($key, $result, $result->isResolved() ? $this->ttl : $this->errorTtl);
        }

        return $result;
    }

    /**
     * Живой HTTP-запрос к GitHub. Любой сбой сети/парсинга превращается в UpstreamVersion с error —
     * discovery модулей не должен падать из-за недоступности GitHub.
     */
    private function request(string $slug, string $token): UpstreamVersion
    {
        $authenticated = $token !== '';
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: besnovatyj-modman',
        ];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $responseHeaders = [];
        $ch = curl_init(sprintf(self::API, $slug));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        try {
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
        } catch (Throwable $e) {
            curl_close($ch);
            Yii::warning("upstream {$slug}: {$e->getMessage()}", 'modman/upstream');
            return new UpstreamVersion($slug, authenticated: $authenticated, error: 'Сбой запроса к GitHub.');
        }
        curl_close($ch);

        $remaining = isset($responseHeaders['x-ratelimit-remaining']) ? (int)$responseHeaders['x-ratelimit-remaining'] : null;
        $reset = isset($responseHeaders['x-ratelimit-reset']) ? (int)$responseHeaders['x-ratelimit-reset'] : null;

        // Исчерпан лимит: GitHub отвечает 403/429 при remaining=0.
        if (($status === 403 || $status === 429) && $remaining === 0) {
            return new UpstreamVersion($slug, rateLimited: true, rateReset: $reset, authenticated: $authenticated);
        }
        if ($status === 404) {
            return new UpstreamVersion($slug, authenticated: $authenticated, error: 'Репозиторий не найден или приватный.');
        }
        if ($curlError !== '' || $body === false || $status < 200 || $status >= 300) {
            $reason = $curlError !== '' ? $curlError : "HTTP {$status}";
            Yii::warning("upstream {$slug}: {$reason}", 'modman/upstream');
            return new UpstreamVersion($slug, authenticated: $authenticated, error: "Ошибка GitHub ({$reason}).");
        }

        $tags = json_decode((string)$body, true);
        if (!is_array($tags)) {
            return new UpstreamVersion($slug, authenticated: $authenticated, error: 'Невалидный ответ GitHub.');
        }

        return new UpstreamVersion(
            $slug,
            latestTag: $this->latestSemverTag($tags),
            authenticated: $authenticated,
        );
    }

    /**
     * Выбирает максимальный semver-тег из ответа `/tags`. Нерелизные имена (не начинающиеся с версии)
     * игнорируются; сравнение — через version_compare (понимает pre-release суффиксы).
     *
     * @param array<int, array{name?: string}> $tags
     */
    private function latestSemverTag(array $tags): ?string
    {
        $latest = null;
        foreach ($tags as $tag) {
            $name = is_array($tag) ? (string)($tag['name'] ?? '') : '';
            if ($name === '' || preg_match('~^v?\d+\.\d+~', $name) !== 1) {
                continue;
            }
            $normalized = ltrim($name, 'vV');
            if ($latest === null || version_compare($normalized, ltrim($latest, 'vV'), '>')) {
                $latest = $name;
            }
        }
        return $latest;
    }
}
