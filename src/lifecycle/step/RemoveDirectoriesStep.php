<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\lifecycle\step;

use Besnovatyj\Helpers\FilesystemHelper;
use Besnovatyj\Modman\lifecycle\OperationContext;

/**
 * Удаляет директории модуля на домене статики (для uninstall).
 */
final class RemoveDirectoriesStep implements LifecycleStep
{
    public function describe(OperationContext $context): string
    {
        return 'Удалить директории модуля на домене статики';
    }

    public function shouldRun(OperationContext $context): bool
    {
        return $context->manifest !== null && $context->manifest->contributions->directories !== [];
    }

    public function execute(OperationContext $context): void
    {
        foreach ($context->manifest->contributions->directories as $directory) {
            $path = $directory->resolvedPath();
            if (is_dir($path)) {
                FilesystemHelper::deleteDirContents($path, true);
                $context->report->info("Удалена директория: {$path}");
            }
        }
    }

    public function compensate(OperationContext $context): void
    {
        $context->report->warning('Удалённые директории автоматически не восстанавливаются.');
    }
}
