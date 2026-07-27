<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\compiler;

/**
 * Результат чистой компиляции оставшихся артефактов ({@see ConfigCompiler}).
 *
 * После переезда на yiisoft/config Yii2-конфиг приложения собирается движком по merge-plan, поэтому
 * здесь только то, что компилятор ещё производит: registry-gated лог-каналы, опции и источники
 * представлений. Детерминирован относительно (реестр × манифесты) — ключи отсортированы в компиляторе.
 */
final readonly class CompiledArtifacts
{
    /**
     * @param array<string, array>   $logChannels channelId => спека (registry-gated лог-каналы)
     * @param array<string, array>   $options     id модуля => опции
     * @param array<string, string>  $viewSources moduleId => алиасный путь views/ (+ ключ @app/views)
     * @param array<string, array>   $dashboardWidgets widgetId => плоский дескриптор плитки главной админки
     * @param string[]               $warnings    нефатальные проблемы компиляции
     */
    public function __construct(
        public array $logChannels = [],
        public array $options = [],
        public array $viewSources = [],
        public array $dashboardWidgets = [],
        public array $warnings = [],
    ) {}
}
