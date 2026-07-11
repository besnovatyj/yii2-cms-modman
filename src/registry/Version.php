<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\registry;

use InvalidArgumentException;

/**
 * Семантическая версия модуля как value-объект.
 *
 * Сравнение делегируется PHP-функции version_compare (понимает 1.2.3, 1.2.3-RC1 и т.п.).
 * Проверка ограничений (^, ~, диапазоны) — отдельная ответственность
 * {@see \Besnovatyj\Modman\deps\SemverConstraint}, чтобы не тащить сюда логику composer/semver.
 */
final readonly class Version
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Version string must not be empty.');
        }
        $this->value = ltrim($value, 'vV');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return version_compare($this->value, $other->value, '==');
    }

    public function isGreaterThan(self $other): bool
    {
        return version_compare($this->value, $other->value, '>');
    }

    public function isLowerThan(self $other): bool
    {
        return version_compare($this->value, $other->value, '<');
    }

    /**
     * @return int -1|0|1
     */
    public function compare(self $other): int
    {
        return version_compare($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
