<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * HTMX-фрагмент результата upstream-проверки одного модуля (внутренность ячейки #upstream-{id}).
 *
 * @var yii\web\View $this
 * @var Besnovatyj\Modman\upstream\UpstreamCheck $check
 */

use yii\helpers\Html;
use yii\helpers\Url;

$u = $check->upstream;

// Кнопка «обновить» (минуя кэш) — та же цель, что и исходная ячейка.
$refresh = Html::a('<i class="bi bi-arrow-clockwise"></i>', '#', [
    'class' => 'btn btn-sm btn-link p-0 ms-1 text-muted',
    'title' => 'Обновить, минуя кэш',
    'hx-get' => Url::to(['upstream', 'moduleId' => $check->moduleId, 'force' => 1]),
    'hx-target' => '#upstream-' . $check->moduleId,
    'hx-swap' => 'innerHTML',
]);
?>
<?php if ($u->error !== null): ?>
    <span class="text-muted small" title="<?= Html::encode($u->error) ?>"><i class="bi bi-dash-circle me-1"></i><?= Html::encode($u->error) ?></span>
<?php elseif ($u->rateLimited): ?>
    <span class="text-warning-emphasis small">
        <i class="bi bi-hourglass-split me-1"></i>Лимит GitHub исчерпан<?= $u->rateReset !== null ? ' до ' . Html::encode(Yii::$app->formatter->asTime($u->rateReset)) : '' ?>.
        <?php if (!$u->authenticated): ?> Задайте токен.<?php endif; ?>
    </span>
    <?= $refresh ?>
<?php elseif ($check->isNewer): ?>
    <span class="badge text-bg-warning" title="Установлена <?= Html::encode($check->installedVersion) ?>">
        <i class="bi bi-arrow-up-circle me-1"></i>доступна <?= Html::encode((string)$u->latestTag) ?>
    </span>
    <?= $refresh ?>
<?php elseif ($u->isResolved()): ?>
    <span class="badge text-bg-success" title="Последний тег: <?= Html::encode((string)$u->latestTag) ?>"><i class="bi bi-check-circle me-1"></i>актуально</span>
    <?= $refresh ?>
<?php else: ?>
    <span class="text-muted small"><i class="bi bi-question-circle me-1"></i>нет релизов</span>
    <?= $refresh ?>
<?php endif; ?>
<?php if ($u->cached && $u->isResolved()): ?><span class="text-muted small ms-1" title="Ответ из кэша">·&nbsp;кэш</span><?php endif; ?>
