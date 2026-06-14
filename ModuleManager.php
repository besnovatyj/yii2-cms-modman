<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew;

use modules\modmanNew\catalog\PackageCatalog;
use modules\modmanNew\catalog\source\DiscoveredPackage;
use modules\modmanNew\compiler\CompiledArtifacts;
use modules\modmanNew\compiler\ConfigCompiler;
use modules\modmanNew\lifecycle\handler\CheckHandler;
use modules\modmanNew\lifecycle\handler\InstallHandler;
use modules\modmanNew\lifecycle\handler\ReconcileHandler;
use modules\modmanNew\lifecycle\handler\UninstallHandler;
use modules\modmanNew\lifecycle\handler\UpdateHandler;
use modules\modmanNew\lifecycle\OperationReport;
use modules\modmanNew\lifecycle\plan\LifecyclePlan;
use modules\modmanNew\registry\ModuleRegistry;
use modules\modmanNew\registry\ModuleState;
use modules\modmanNew\registry\ModuleStatus;

/**
 * Фасад системы управления модулями — единый публичный API для драйверов (web-контроллёр, console).
 *
 * Тонкая обёртка над хендлерами и каталогом/реестром: драйверы остаются без бизнес-логики, а сама
 * логика одинаково доступна из web, CLI, очереди и Ansible (благодаря {@see OperationReport}).
 */
final class ModuleManager
{
    public function __construct(
        private readonly PackageCatalog    $catalog,
        private readonly ModuleRegistry    $registry,
        private readonly CheckHandler      $checkHandler,
        private readonly InstallHandler    $installHandler,
        private readonly UninstallHandler  $uninstallHandler,
        private readonly UpdateHandler     $updateHandler,
        private readonly ReconcileHandler  $reconcileHandler,
        private readonly ConfigCompiler    $compiler,
    ) {}

    public function check(string $moduleId): LifecyclePlan
    {
        return $this->checkHandler->check($moduleId);
    }

    public function install(string $moduleId): OperationReport
    {
        $report = $this->installHandler->install($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function uninstall(string $moduleId): OperationReport
    {
        $report = $this->uninstallHandler->uninstall($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function update(string $moduleId): OperationReport
    {
        $report = $this->updateHandler->update($moduleId);
        $this->catalog->refresh();
        return $report;
    }

    public function reconcile(): OperationReport
    {
        return $this->reconcileHandler->reconcileAll();
    }

    /**
     * Принудительно перекомпилировать артефакты из текущего реестра (кнопка «пересобрать»).
     */
    public function recompile(): CompiledArtifacts
    {
        return $this->compiler->recompile();
    }

    /**
     * Модули для UI: манифесты каталога, наложенные на реестр, плюс «осиротевшие» записи реестра.
     *
     * @return ModuleView[]
     */
    public function modules(): array
    {
        $views = [];
        $manifests = $this->catalog->manifests();

        foreach ($manifests as $id => $manifest) {
            $state = $this->registry->get($id);
            $installed = $state?->status->isActive() ?? false;
            // Системный модуль (editable=false): часть ядра, ставится установочным скриптом, из админки
            // неприкасаем. Сам менеджер — такой же: бутстрапится приложением вручную, поэтому показываем
            // его «активным/системным», а не «доступен к установке», и без кнопок install/uninstall.
            $system = !$manifest->editable;
            $hasUpdate = $installed
                && ($manifest->version->isGreaterThan($state->version) || $manifest->checksum !== $state->manifestChecksum);

            $views[] = new ModuleView(
                id: $id,
                package: $manifest->package,
                availableVersion: $manifest->version->value,
                installedVersion: $state?->version->value,
                status: $state?->status->value ?? ($system ? 'system' : ModuleStatus::Discovered->value),
                editable: $manifest->editable,
                installed: $installed,
                hasUpdate: $hasUpdate,
                iconClass: $manifest->iconClass,
                hasOptions: $manifest->contributions->options !== [],
                system: $system,
            );
        }

        // CMS-модули с ошибкой конфигурации — строкой с причиной и без активной кнопки установки.
        foreach ($this->catalog->invalids() as $invalid) {
            $views[] = new ModuleView(
                id: $invalid->declaredId ?? $invalid->package,
                package: $invalid->package,
                availableVersion: '',
                installedVersion: null,
                status: 'invalid',
                editable: false,
                installed: false,
                hasUpdate: false,
                invalid: true,
                invalidReason: $invalid->reason,
            );
        }

        // Записи реестра, для которых пропал пакет (осиротевшие) — показываем для диагностики/reconcile.
        foreach ($this->registry->all() as $id => $state) {
            if (!isset($manifests[$id])) {
                $views[] = new ModuleView(
                    id: $id,
                    package: $state->package,
                    availableVersion: '',
                    installedVersion: $state->version->value,
                    status: $state->status->value,
                    editable: false,
                    installed: $state->status->isActive(),
                    hasUpdate: false,
                    orphan: true,
                );
            }
        }

        usort($views, static fn(ModuleView $a, ModuleView $b): int => strcmp($a->id, $b->id));
        return $views;
    }

    /**
     * @return DiscoveredPackage[]
     */
    public function packages(): array
    {
        return $this->catalog->packages();
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->catalog->warnings();
    }

    /**
     * @return array<string, ModuleState>
     */
    public function pending(): array
    {
        return $this->reconcileHandler->pending();
    }
}
