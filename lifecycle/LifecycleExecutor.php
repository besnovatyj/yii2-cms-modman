<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modman\lifecycle;

use common\components\theme\Theme;
use modules\modman\compiler\ConfigCompiler;
use modules\modman\lifecycle\step\LifecycleStep;
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
                $this->compiler->recompile();   // производная проекция
                $this->renewThemePathMap($context);

                $this->logReport($context, "✔ Операция «{$context->type->value}» над '{$context->moduleId}' завершена.");
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
                $this->renewThemePathMap($context);

                $this->logReport($context, "✖ Операция «{$context->type->value}» над '{$context->moduleId}' откачена.");
            }
        });
    }

    /**
     * Обновляет карту представлений темы после изменения набора установленных модулей (как старый
     * `Theme::renewPathMap()`): иначе темизированные view модуля могут не появиться/не исчезнуть до
     * отдельного обновления. Это деривативная проекция, как и recompile, поэтому здесь, после неё.
     * Сбой темизации не должен валить операцию — ловим и пишем в лог.
     */
    private function renewThemePathMap(OperationContext $context): void
    {
        try {
            new Theme()->renewPathMap();
        } catch (Throwable $e) {
            $context->report->warning('Не удалось обновить карту представлений темы: ' . $e->getMessage());
            Yii::warning("[{$context->moduleId}] карта представлений темы не обновлена: {$e->getMessage()}", 'modman/lifecycle');
        }
    }

    /**
     * Выгружает накопленный {@see OperationReport} в канал лога `modman/*` — чтобы детальный отчёт
     * (созданные/удалённые директории, сводка миграций, предупреждения и ошибки) лёг в файл модуля,
     * а не остался только во flash. Уровень сообщения соответствует его роли.
     */
    private function logReport(OperationContext $context, string $header): void
    {
        $report = $context->report;
        Yii::info($header, 'modman/lifecycle');

        foreach ($report->infos() as $message) {
            Yii::info("[{$context->moduleId}] {$message}", 'modman/lifecycle');
        }
        foreach ($report->warnings() as $message) {
            Yii::warning("[{$context->moduleId}] {$message}", 'modman/lifecycle');
        }
        foreach ($report->errors() as $message) {
            Yii::error("[{$context->moduleId}] {$message}", 'modman/lifecycle');
        }
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
