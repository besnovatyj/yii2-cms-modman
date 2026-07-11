<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var Besnovatyj\Modman\lifecycle\plan\LifecyclePlan $plan
 */

use yii\helpers\Html;

$this->title = "План операции «{$plan->type->value}» — {$plan->moduleId}";
?>

<div class="modman-plan">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('← К списку', ['index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>

    <div class="alert <?= $plan->isFeasible() ? 'alert-success' : 'alert-danger' ?>">
        <?= $plan->isFeasible()
            ? '<i class="bi bi-check-circle me-1"></i> Операция выполнима.'
            : '<i class="bi bi-x-circle me-1"></i> Операция невыполнима — см. блокеры ниже.' ?>
        <span class="ms-2 text-muted">версия: <?= Html::encode($plan->version ?: '—') ?></span>
    </div>

    <?php if ($plan->blockers !== []): ?>
        <div class="card border-danger mb-3">
            <div class="card-header bg-danger text-white">Блокеры</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($plan->blockers as $b): ?>
                    <li class="list-group-item"><?= Html::encode($b) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($plan->warnings !== []): ?>
        <div class="card border-warning mb-3">
            <div class="card-header bg-warning">Предупреждения</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($plan->warnings as $w): ?>
                    <li class="list-group-item"><?= Html::encode($w) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Шаги (dry-run, ничего не изменено)</div>
        <ol class="list-group list-group-flush list-group-numbered">
            <?php foreach ($plan->steps as $step): ?>
                <li class="list-group-item">
                    <strong><?= Html::encode($step->title) ?></strong>
                    <?php if ($step->detail !== ''): ?>
                        <div class="text-muted small"><?= Html::encode($step->detail) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <?php if ($plan->isFeasible() && $plan->type->value === 'install'): ?>
        <?= Html::beginForm(['install'], 'post') ?>
        <?= Html::hiddenInput('moduleId', $plan->moduleId) ?>
        <?= Html::submitButton('<i class="bi bi-download me-1"></i> Установить', ['class' => 'btn btn-success']) ?>
        <?= Html::endForm() ?>
    <?php elseif ($plan->isFeasible() && $plan->type->value === 'update'): ?>
        <?= Html::beginForm(['update'], 'post') ?>
        <?= Html::hiddenInput('moduleId', $plan->moduleId) ?>
        <?= Html::submitButton('<i class="bi bi-arrow-up-circle me-1"></i> Обновить', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
    <?php elseif ($plan->isFeasible() && $plan->type->value === 'uninstall'): ?>
        <?= Html::beginForm(['uninstall'], 'post') ?>
        <?= Html::hiddenInput('moduleId', $plan->moduleId) ?>
        <?= Html::submitButton('<i class="bi bi-trash me-1"></i> Удалить', [
            'class' => 'btn btn-outline-danger',
            'data' => ['confirm' => "Удалить модуль «{$plan->moduleId}»?"],
        ]) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
