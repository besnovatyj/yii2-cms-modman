<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\compiler;

/**
 * Результат чистой компиляции конфигурации — что должно быть записано в артефакты.
 *
 * Полностью детерминирован относительно (реестр × манифесты): при одинаковом входе — одинаковый
 * выход (ключи отсортированы в {@see ConfigCompiler}). Это и есть критерий «красоты» новой
 * архитектуры — повторная install/uninstall даёт побайтово те же артефакты.
 */
final readonly class CompiledArtifacts
{
    /**
     * @param array<string, array>  $modules         id => конфиг модуля
     * @param array<int, string>     $bootstrap       список bootstrap-классов
     * @param array<string, array>   $components      componentId => конфиг
     * @param array<string, array>   $logChannels     channelId => спека
     * @param array<string, array>   $options         id модуля => опции
     * @param array<string, array>   $menusByLocation location => дерево меню
     * @param array<string, string>  $viewSources     moduleId => алиасный путь views/ (+ ключ @app/views)
     * @param string[]               $warnings        нефатальные проблемы компиляции
     */
    public function __construct(
        public array $modules,
        public array $bootstrap,
        public array $components,
        public array $logChannels,
        public array $options,
        public array $menusByLocation,
        public array $viewSources = [],
        public array $warnings = [],
    ) {}
}
