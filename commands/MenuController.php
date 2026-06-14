<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\commands;

use modules\modman\compiler\ConfigCompiler;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Диагностика и обслуживание меню (аналог `modman/menu/*` из старого modman).
 *
 * Пересборка всех артефактов есть в `modman/modules/recompile`; здесь — точечные операции по меню:
 *  - `php yii modman/menu/info`    — состояние локаций меню (вкл/выкл, файл, существование, число пунктов);
 *  - `php yii modman/menu/rebuild` — перекомпилировать ТОЛЬКО артефакты меню.
 */
final class MenuController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly ConfigCompiler $compiler,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Диагностика локаций меню: включённость, путь файла, существование, число пунктов.
     */
    public function actionInfo(): int
    {
        $params = require dirname(__DIR__) . '/config/params.php';
        $locations = $params['menuLocations'] ?? [];

        if ($locations === []) {
            $this->stdout("Локации меню не настроены (params.menuLocations пуст).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-20s %-8s %-8s %-7s %s\n", 'LOCATION', 'ВКЛ', 'ЕСТЬ', 'ПУНКТ.', 'ФАЙЛ'), Console::BOLD);
        foreach ($locations as $location => $cfg) {
            $file = (string)Yii::getAlias($cfg['file'] ?? '', false);
            $exists = $file !== '' && is_file($file);
            $count = 0;
            if ($exists) {
                $data = @include $file;
                $count = is_array($data) ? count($data) : 0;
            }

            $line = sprintf(
                "%-20s %-8s %-8s %-7s %s\n",
                $location,
                ($cfg['enabled'] ?? false) ? 'да' : 'нет',
                $exists ? 'да' : 'нет',
                $exists ? (string)$count : '—',
                $file !== '' ? $file : '(алиас не разрешён)',
            );
            $exists ? $this->stdout($line, Console::FG_GREEN) : $this->stdout($line);
        }

        return ExitCode::OK;
    }

    /**
     * Перекомпилировать только артефакты меню (не трогая остальные).
     */
    public function actionRebuild(): int
    {
        $artifacts = $this->compiler->recompileMenus();
        $this->stdout("Меню перекомпилировано.\n", Console::FG_GREEN);
        foreach ($artifacts->warnings as $warning) {
            $this->stdout("! {$warning}\n", Console::FG_YELLOW);
        }
        return ExitCode::OK;
    }
}
