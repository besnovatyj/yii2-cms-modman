<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\lifecycle\step;

use modules\modmanNew\lifecycle\OperationContext;

/**
 * Типизированный шаг операции с компенсацией (паттерн Saga).
 *
 * Каждый необратимый вне-реестровый эффект (миграции, директории) оформлен как шаг, умеющий
 * откатить себя ({@see compensate()}). Производные конфиги шагами НЕ являются — они перекомпилируются
 * исполнителем из реестра, поэтому компенсаций для них не нужно.
 */
interface LifecycleStep
{
    /**
     * Короткое человекочитаемое описание (для плана и отчёта).
     */
    public function describe(OperationContext $context): string;

    /**
     * Нужно ли выполнять шаг для данной операции.
     */
    public function shouldRun(OperationContext $context): bool;

    /**
     * Выполнить шаг. Накопленные для компенсации данные пишутся в $context.
     */
    public function execute(OperationContext $context): void;

    /**
     * Откатить эффект шага при сбое последующих шагов.
     */
    public function compensate(OperationContext $context): void;
}
