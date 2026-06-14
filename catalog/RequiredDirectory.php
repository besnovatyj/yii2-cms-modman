<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\catalog;

use Yii;

/**
 * Директория, необходимая модулю на домене статики.
 *
 * Путь может содержать Yii-alias (например, '@static/origin/Blog'); {@see resolvedPath()}
 * возвращает абсолютный путь.
 */
final readonly class RequiredDirectory
{
    public function __construct(
        public string $path,
        public int    $mode = 0775,
    ) {}

    public function resolvedPath(): string
    {
        return Yii::getAlias($this->path);
    }
}
