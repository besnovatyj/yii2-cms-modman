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
 * Ключевая задача — отдать путь в виде АЛИАСА (`@modules/...`, `@root/packages/...` в dev или
 * `@vendor/...` в prod), а не абсолютной строки: артефакт `moduleViewSources.php` тем самым
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
    /**
     * Корни для нормализации, в порядке приоритета. Порядок важен: в dev пакеты симлинкуются в
     * `@vendor`, но realpath приземляется в `@root/packages` — его и надо сматчить раньше `@vendor`.
     *
     * @var array<string, string|null> alias => realpath корня (null, если алиас/каталог отсутствует)
     */
    private readonly array $roots;

    public function __construct()
    {
        $this->roots = [
            '@modules' => $this->realRoot('@modules'),
            '@root/packages' => $this->realRoot('@root/packages'),
            '@vendor' => $this->realRoot('@vendor'),
        ];
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

        foreach ($this->roots as $alias => $root) {
            if ($root !== null && str_starts_with($viewsDir . '/', $root . '/')) {
                return $alias . substr($viewsDir, strlen($root));
            }
        }

        // Не под известным корнем — отдаём абсолютный путь как есть (крайний случай).
        return $viewsDir;
    }

    /**
     * Нормализованный realpath корня. Если каталога нет (например, `@root/packages` в prod) —
     * берём резолв алиаса без realpath, чтобы сравнение всё равно было детерминированным.
     */
    private function realRoot(string $alias): ?string
    {
        $path = Yii::getAlias($alias, false);
        if ($path === false) {
            return null;
        }
        $real = realpath($path);

        return FileHelper::normalizePath($real !== false ? $real : $path, '/');
    }
}
