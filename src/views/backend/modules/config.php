<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Вьювер итогового собранного конфига (диагностика).
 *
 * @var yii\web\View $this
 * @var string[] $groups
 * @var string $group
 * @var array<string, string[]> $contributors  package => файлы (порядок слияния)
 * @var array<mixed>|string $assembled          собранный конфиг группы (или строка-ошибка)
 */

use yii\helpers\Html;

$this->title = 'Итоговый конфиг';

$json = is_array($assembled)
    ? json_encode($assembled, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;
?>

<div class="modman-config">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0"><i class="bi bi-file-earmark-code me-2"></i><?= Html::encode($this->title) ?></h1>
        <?= Html::a('<i class="bi bi-arrow-left me-1"></i>К модулям', ['index'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
    </div>

    <p class="text-muted small">
        То, что РЕАЛЬНО собирается движком конфигов для выбранной группы (то же, что исполняется в
        рантайме). Секреты замаскированы, замыкания/объекты показаны метками. Для поиска «виновника»
        сверяйте секцию «кто вкладывает» с итоговыми значениями.
    </p>

    <?= Html::beginForm(['config'], 'get', ['class' => 'mb-3']) ?>
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text">Группа</span>
            <?= Html::dropDownList('group', $group, array_combine($groups, $groups), [
                'class' => 'form-select',
                'onchange' => 'this.form.submit()',
            ]) ?>
        </div>
    <?= Html::endForm() ?>

    <?php if ($group === ''): ?>
        <div class="alert alert-warning">Группы конфига не найдены — возможно, merge-plan ещё не собран (нажмите «Пересобрать конфиг»).</div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="card">
                    <div class="card-header py-2">
                        <i class="bi bi-diagram-3 me-1"></i>Кто вкладывает в «<?= Html::encode($group) ?>»
                        <span class="badge text-bg-light"><?= count($contributors) ?></span>
                    </div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($contributors as $package => $files): ?>
                            <li class="list-group-item">
                                <div class="fw-semibold text-break"><?= Html::encode($package) ?></div>
                                <?php foreach ($files as $file): ?>
                                    <div class="text-muted text-break"><code><?= Html::encode($file) ?></code></div>
                                <?php endforeach; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($contributors === []): ?>
                            <li class="list-group-item text-muted">Нет вкладов.</li>
                        <?php endif; ?>
                    </ul>
                    <div class="card-footer text-muted small">
                        Порядок = порядок слияния (<code>vendor</code> раньше, <code>root</code> ядра — последним).
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card">
                    <div class="card-header py-2"><i class="bi bi-braces me-1"></i>Собранный результат</div>
                    <div class="card-body p-0">
                        <?php if ($json !== null): ?>
                            <pre class="mb-0 p-3" style="max-height: 70vh; overflow:auto;"><?= Html::encode($json) ?></pre>
                        <?php else: ?>
                            <div class="alert alert-warning m-3 mb-0"><?= Html::encode((string)$assembled) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
