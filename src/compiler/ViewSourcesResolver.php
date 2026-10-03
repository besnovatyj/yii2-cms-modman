<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

use ReflectionClass;
use ReflectionException;
use Yii;
use yii\helpers\FileHelper;

/**
 * Вычисляет алиасный путь к собственному каталогу `views/` модуля по его классу.
 *
 * Ключевая задача — отдать путь в виде АЛИАСА `@vendor/...`, а не абсолютной строки: модули ставятся
 * только composer'ом, и артефакт `moduleViewSources.php` тем самым
 * переносим между окружениями, а Yii при рендере резолвит алиас обратно в реальный путь
 * (`yii\base\Theme::applyTo()` прогоняет ключи карты через {@see Yii::getAlias()}).
 *
 * Путь берётся так же, как его вычислит Yii в рантайме — из файла класса модуля
 * (`Module::getViewPath()` = `dirname(reflection) . '/views'`), поэтому ключ карты гарантированно
 * совпадёт с путём, по которому Yii ищет view. Рефлексия здесь дёшева и происходит только при
 * recompile (install/uninstall), а НЕ на каждый запрос — в отличие от старого `Theme`.
 */
final class ViewSourcesResolver
{
    private const string VENDOR_ALIAS = '@vendor';

    /** Нормализованный realpath `@vendor`. */
    private readonly string $vendorRoot;

    public function __construct()
    {
        $vendor = Yii::getAlias(self::VENDOR_ALIAS);
        $real = realpath($vendor);
        $this->vendorRoot = FileHelper::normalizePath($real !== false ? $real : $vendor, '/');
    }

    /**
     * Алиасный путь к `views/` модуля или null, если класс недоступен для рефлексии.
     *
     * @param class-string $moduleClass FQCN класса модуля
     */
    public function sourceAlias(string $moduleClass): ?string
    {
        try {
            $file = (new ReflectionClass($moduleClass))->getFileName();
        } catch (ReflectionException) {
            return null;
        }
        if ($file === false) {
            return null;
        }

        $viewsDir = FileHelper::normalizePath(dirname($file) . '/views', '/');

        if (str_starts_with($viewsDir . '/', $this->vendorRoot . '/')) {
            return self::VENDOR_ALIAS . substr($viewsDir, strlen($this->vendorRoot));
        }

        // Класс модуля вне `@vendor` — абсолютный путь как есть (артефакт тогда непереносим).
        return $viewsDir;
    }
}
