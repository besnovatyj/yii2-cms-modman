<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\commands;

use modules\modman\lifecycle\OperationReport;
use modules\modman\lifecycle\plan\LifecyclePlan;
use modules\modman\ModuleManager;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Консольный драйвер системы управления модулями.
 *
 * Тонкая обёртка над тем же {@see ModuleManager}, что и web-контроллёр — демонстрация UI-агностичного
 * сервисного слоя (исправление связки «сервис ↔ session flash» старого modman). Пригодно для CI/Ansible.
 *
 *  - `php yii modman/modules/list`
 *  - `php yii modman/modules/check <moduleId>`
 *  - `php yii modman/modules/install <moduleId>`
 *  - `php yii modman/modules/uninstall <moduleId>`
 *  - `php yii modman/modules/update <moduleId>`
 *  - `php yii modman/modules/reconcile`
 *  - `php yii modman/modules/sync [--adoptAll]`
 *  - `php yii modman/modules/recompile`
 */
final class ModulesController extends Controller
{
    /**
     * Усыновить как installed ВСЕ обнаруженные editable-модули, даже без доказательств установки
     * (нет применённых миграций и записи в реестре). Крайняя мера восстановления для `sync`.
     *
     * Нетипизировано намеренно: Yii console присваивает значение опции строкой ('1'), приведение
     * к bool делаем явно в {@see actionSync()}.
     */
    public $adoptAll = false;

    public function __construct(
        $id,
        $module,
        private readonly ModuleManager $manager,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function options($actionID): array
    {
        return $actionID === 'sync' ? ['adoptAll'] : [];
    }

    public function optionAliases(): array
    {
        return ['a' => 'adoptAll'];
    }

    public function actionList(): int
    {
        foreach ($this->manager->warnings() as $warning) {
            $this->stdout("! {$warning}\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf("%-22s %-12s %-10s %-10s\n", 'ID', 'СТАТУС', 'ДОСТУПНА', 'УСТАН.'), Console::BOLD);
        foreach ($this->manager->modules() as $m) {
            $this->stdout(sprintf(
                "%-22s %-12s %-10s %-10s\n",
                $m->id,
                $m->status . ($m->hasUpdate ? '*' : ''),
                $m->availableVersion ?: '—',
                $m->installedVersion ?? '—',
            ));
            if ($m->invalid && $m->invalidReason !== null) {
                $this->stdout("  ↳ {$m->invalidReason}\n", Console::FG_YELLOW);
            }
        }
        return ExitCode::OK;
    }

    public function actionCheck(string $moduleId): int
    {
        return $this->printPlan($this->manager->check($moduleId));
    }

    public function actionInstall(string $moduleId): int
    {
        return $this->printReport($this->manager->install($moduleId));
    }

    public function actionUninstall(string $moduleId): int
    {
        return $this->printReport($this->manager->uninstall($moduleId));
    }

    public function actionUpdate(string $moduleId): int
    {
        return $this->printReport($this->manager->update($moduleId));
    }

    public function actionReconcile(): int
    {
        return $this->printReport($this->manager->reconcile());
    }

    /**
     * Пересобрать `modules-state.php` из фактического состояния (каталог + история миграций) и
     * перекомпилировать артефакты. Восстановление реестра без ручной правки.
     *
     * Флаг `--adoptAll` (`-a`) усыновляет все обнаруженные editable-модули, даже без доказательств
     * установки.
     */
    public function actionSync(): int
    {
        return $this->printReport($this->manager->sync((bool)$this->adoptAll));
    }

    public function actionRecompile(): int
    {
        $artifacts = $this->manager->recompile();
        $this->stdout("Конфигурация перекомпилирована.\n", Console::FG_GREEN);
        foreach ($artifacts->warnings as $warning) {
            $this->stdout("! {$warning}\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }

    private function printPlan(LifecyclePlan $plan): int
    {
        $this->stdout("План «{$plan->type->value}» для «{$plan->moduleId}» (v{$plan->version}):\n", Console::BOLD);
        foreach ($plan->steps as $i => $step) {
            $this->stdout('  ' . ($i + 1) . ". {$step->title}" . ($step->detail !== '' ? " — {$step->detail}" : '') . "\n");
        }
        foreach ($plan->warnings as $w) {
            $this->stdout("  ! {$w}\n", Console::FG_YELLOW);
        }
        foreach ($plan->blockers as $b) {
            $this->stdout("  ✗ {$b}\n", Console::FG_RED);
        }
        if ($plan->isFeasible()) {
            $this->stdout("Итог: выполнимо.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stdout("Итог: невыполнимо.\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    private function printReport(OperationReport $report): int
    {
        foreach ($report->steps() as $m) {
            $this->stdout("  • {$m}\n");
        }
        foreach ($report->infos() as $m) {
            $this->stdout("  {$m}\n", Console::FG_GREEN);
        }
        foreach ($report->warnings() as $m) {
            $this->stdout("  ! {$m}\n", Console::FG_YELLOW);
        }
        foreach ($report->errors() as $m) {
            $this->stdout("  ✗ {$m}\n", Console::FG_RED);
        }

        if ($report->isSuccessful()) {
            $this->stdout("OK\n", Console::FG_GREEN, Console::BOLD);
            return ExitCode::OK;
        }
        $this->stdout("FAILED\n", Console::FG_RED, Console::BOLD);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
