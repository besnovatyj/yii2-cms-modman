<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var string $modalId
 * @var string $title
 * @var bool $ok
 * @var array<int, array{label: string, variant: string, items: string[]}> $groups
 */

use yii\helpers\Html;

$headerClass = $ok ? 'text-bg-success' : 'text-bg-danger';
$headerIcon = $ok ? 'bi-check-circle' : 'bi-exclamation-octagon';
?>

<?php // modal-dialog-scrollable — прокрутка внутри тела; modal-fullscreen-sm-down — во весь экран на мобилках ?>
<?php // Клик по фону закрывает окно (поведение Bootstrap по умолчанию — backdrop не static). ?>
<div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header <?= $headerClass ?>">
                <h5 class="modal-title" id="<?= $modalId ?>-label">
                    <i class="bi <?= $headerIcon ?> me-2"></i><?= Html::encode($title) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <?php foreach ($groups as $group): ?>
                    <?php $variant = (string)($group['variant'] ?? 'secondary'); ?>
                    <div class="mb-3">
                        <h6 class="text-<?= $variant === 'warning' ? 'warning-emphasis' : ($variant === 'danger' ? 'danger-emphasis' : 'body-secondary') ?> text-uppercase small fw-bold mb-2">
                            <?= Html::encode((string)($group['label'] ?? '')) ?>
                            <span class="badge rounded-pill text-bg-<?= Html::encode($variant) ?> ms-1"><?= count($group['items'] ?? []) ?></span>
                        </h6>
                        <ul class="list-group list-group-flush border rounded">
                            <?php foreach (($group['items'] ?? []) as $item): ?>
                                <li class="list-group-item py-2 small"><?= Html::encode((string)$item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>
