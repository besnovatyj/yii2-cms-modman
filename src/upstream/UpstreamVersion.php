<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\upstream;

/**
 * Результат запроса последней доступной версии пакета к GitHub — value-объект для UI/кэша.
 *
 * Один тип на все исходы: удача (тег найден), «нет релизов», лимит запросов исчерпан, ошибка сети.
 * Сериализуем (readonly-скаляры) — кладётся как есть в кэш приложения.
 */
final readonly class UpstreamVersion
{
    /**
     * @param string      $slug         owner/repo, к которому шёл запрос
     * @param string|null $latestTag    последний semver-тег (например, 'v1.0.5') или null
     * @param bool        $rateLimited  лимит GitHub исчерпан (без токена — 60 запросов/час на IP)
     * @param int|null    $rateReset    unix-время сброса лимита (из заголовка X-RateLimit-Reset)
     * @param bool        $authenticated запрос выполнялся с токеном
     * @param string|null $error        текст ошибки (repo не найден/приватный, сеть, невалидный JSON)
     * @param bool        $cached       ответ отдан из кэша (для пометки в UI)
     */
    public function __construct(
        public string  $slug,
        public ?string $latestTag = null,
        public bool    $rateLimited = false,
        public ?int    $rateReset = null,
        public bool    $authenticated = false,
        public ?string $error = null,
        public bool    $cached = false,
    ) {}

    public function withCached(bool $cached): self
    {
        return new self(
            $this->slug, $this->latestTag, $this->rateLimited,
            $this->rateReset, $this->authenticated, $this->error, $cached,
        );
    }

    /**
     * Успешно ли получен тег (в отличие от лимита/ошибки/отсутствия релизов).
     */
    public function isResolved(): bool
    {
        return $this->latestTag !== null;
    }
}
