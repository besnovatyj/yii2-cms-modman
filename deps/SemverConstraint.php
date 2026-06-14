<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\deps;

use Composer\Semver\Semver;

/**
 * Проверка соответствия версии ограничению.
 *
 * Если доступен `composer/semver` (а он есть в дереве зависимостей CMS) — используется он, что даёт
 * полноценную поддержку `^`, `~`, диапазонов и OR-ограничений. Иначе — деградация до `version_compare`
 * с простыми операторами. Это исправляет самописный разбор версий старого modman.
 */
final class SemverConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (class_exists(Semver::class)) {
            return Semver::satisfies(self::normalize($version), $constraint);
        }

        // Fallback: один оператор или точное совпадение.
        if (preg_match('/^(>=|<=|>|<|=|==)?\s*(.+)$/', $constraint, $m) === 1) {
            $operator = $m[1] !== '' ? $m[1] : '=';
            $operator = $operator === '==' ? '=' : $operator;
            return version_compare(self::normalize($version), trim($m[2]), $operator);
        }

        return version_compare(self::normalize($version), $constraint, '=');
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
