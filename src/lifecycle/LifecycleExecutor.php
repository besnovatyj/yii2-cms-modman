<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\Modman\lifecycle;

use Besnovatyj\Modman\compiler\ConfigCompiler;
use Besnovatyj\Modman\lifecycle\step\LifecycleStep;
use Throwable;
use Yii;

/**
 * Выполняет последовательность шагов под блокировкой с принципом commit-at-end и компенсацией.
 *
 * Поток:
 *  1. под мьютексом выполняются шаги (необратимые вне-реестровые эффекты: миграции, директории);
 *  2. commit-at-end: запись реестра — последний авторитетный шаг (до него «установлен» не виден);
 *  3. recompile: производные конфиги собираются заново из реестра (идемпотентная проекция).
 *
 * При сбое: компенсация выполненных шагов в обратном порядке, откат изменения реестра, повторный
 * recompile из восстановленного реестра. Исключение НЕ пробрасывается — результат фиксируется в
 * {@see OperationReport} (драйвер сам решает, как показать).
 */
final class LifecycleExecutor
{
    public function __construct(
        private readonly LifecycleLock  $lock,
        private readonly ConfigCompiler $compiler,
    ) {}

    /**
     * @param LifecycleStep[]                  $steps
     * @param callable(OperationContext):void  $commit   commit-at-end: финальная запись реестра
     * @param callable(OperationContext):void  $rollback откат изменения реестра при сбое
     * @param (callable(OperationContext):void)|null $intent write-ahead intent (маркер «операция
     *        началась»): выполняется ПЕРВЫМ, но уже ПОД блокировкой — иначе два параллельных запроса
     *        успевали изменить реестр до взятия мьютекса. При сбое откатывается через $rollback.
     */
    public function execute(OperationContext $context, array $steps, callable $commit, callable $rollback, ?callable $intent = null): void
    {
        $this->lock->withLock(function () use ($context, $steps, $commit, $rollback, $intent): void {
            Yii::info("► Старт операции «{$context->type->value}» над модулем '{$context->moduleId}'.", 'modman/lifecycle');
            $executed = [];
            try {
                if ($intent !== null) {
                    $intent($context);
                }
                foreach ($steps as $step) {
                    if (!$step->shouldRun($context)) {
                        continue;
                    }
                    $description = $step->describe($context);
                    $context->report->step($description);
                    Yii::info("[{$context->moduleId}] шаг: {$description}", 'modman/lifecycle');
                    $step->execute($context);
                    $executed[] = $step;
                }

                $commit($context);              // commit-at-end
                $this->compiler->recompile();   // производная проекция (в т.ч. manifest источников представлений)

                Yii::info("✔ Операция «{$context->type->value}» над '{$context->moduleId}' завершена.", 'modman/lifecycle');
            } catch (Throwable $e) {
                Yii::error("Операция {$context->type->value} над '{$context->moduleId}' прервана: {$e->getMessage()}", 'modman/lifecycle');
                $context->report->error($e->getMessage());

                $this->compensate($executed, $context);

                try {
                    $rollback($context);
                } catch (Throwable $re) {
                    $context->report->error('Сбой отката реестра: ' . $re->getMessage());
                }

                $this->safeRecompile($context);

                Yii::warning("✖ Операция «{$context->type->value}» над '{$context->moduleId}' откачена.", 'modman/lifecycle');
            }
        });
        // Полную сводку отчёта (infos/warnings/errors) в канал `modman/*` выгружает фасад
        // {@see \Besnovatyj\Modman\ModuleManager} — единой точкой для ВСЕХ операций, включая те, что
        // не дошли до executor'а (блокеры плана: «модуль не установлен», конфликт компонентов и т.п.).
    }

    /**
     * @param LifecycleStep[] $executed
     */
    private function compensate(array $executed, OperationContext $context): void
    {
        foreach (array_reverse($executed) as $step) {
            try {
                $step->compensate($context);
            } catch (Throwable $e) {
                $context->report->error('Сбой компенсации шага «' . $step->describe($context) . '»: ' . $e->getMessage());
            }
        }
    }

    private function safeRecompile(OperationContext $context): void
    {
        try {
            $this->compiler->recompile();
        } catch (Throwable $e) {
            $context->report->error('Сбой перекомпиляции после отката: ' . $e->getMessage());
        }
    }
}
