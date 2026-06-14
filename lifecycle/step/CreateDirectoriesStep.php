<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle\step;

use Besnovatyj\Helpers\FilesystemHelper;
use modules\modman\lifecycle\OperationContext;

/**
 * Создаёт директории, требуемые модулем на домене статики. Компенсация удаляет только то, что
 * создал именно этот шаг (зафиксировано в контексте).
 */
final class CreateDirectoriesStep implements LifecycleStep
{
    public function describe(OperationContext $context): string
    {
        return 'Создать директории модуля на домене статики';
    }

    public function shouldRun(OperationContext $context): bool
    {
        return $context->manifest !== null && $context->manifest->contributions->directories !== [];
    }

    public function execute(OperationContext $context): void
    {
        foreach ($context->manifest->contributions->directories as $directory) {
            $path = $directory->resolvedPath();
            if (!is_dir($path)) {
                FilesystemHelper::createDirectoryRecursively($path);
                $context->createdDirectories[] = $path;
                $context->report->info("Создана директория: {$path}");
            }
        }
    }

    public function compensate(OperationContext $context): void
    {
        foreach ($context->createdDirectories as $path) {
            if (is_dir($path)) {
                FilesystemHelper::deleteDirContents($path, true);
                $context->report->warning("Удалена созданная директория: {$path}");
            }
        }
        $context->createdDirectories = [];
    }
}
