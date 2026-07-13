<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\upstream;

/**
 * Итог upstream-проверки одного модуля для представления: ответ GitHub + сравнение с версией на диске.
 *
 * Собирается {@see \Besnovatyj\Modman\ModuleManager::checkUpstream()} — контроллёр только рендерит,
 * не считая версий сам.
 */
final readonly class UpstreamCheck
{
    public function __construct(
        public string          $moduleId,
        public string          $installedVersion,
        public UpstreamVersion $upstream,
        public bool            $isNewer,
    ) {}
}
