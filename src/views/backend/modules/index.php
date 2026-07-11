<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var Besnovatyj\Modman\forms\backend\search\ModuleSearch $search
 * @var yii\data\ArrayDataProvider $dataProvider
 * @var Besnovatyj\Modman\catalog\source\DiscoveredPackage[] $packages
 * @var array<string, Besnovatyj\Modman\registry\ModuleState> $pending
 */

use Besnovatyj\Backend\Widgets\pagination\LinkPager;
use Besnovatyj\Modman\forms\backend\search\ModuleSearch;
use Besnovatyj\Modman\widgets\OperationReportModal;
use yii\helpers\Html;

$sort = $dataProvider->getSort();
$modules = $dataProvider->getModels();

$this->title = 'Управление модулями';

/** Бейдж статуса модуля. */
$statusBadge = static function (string $status): string {
    $map = [
        'installed' => 'text-bg-success',
        'system' => 'text-bg-primary',
        'discovered' => 'text-bg-secondary',
        'invalid' => 'text-bg-danger',
        'failed' => 'text-bg-danger',
        'installing' => 'text-bg-warning',
        'updating' => 'text-bg-warning',
        'removing' => 'text-bg-warning',
    ];
    $class = $map[$status] ?? 'text-bg-secondary';
    return Html::tag('span', Html::encode($status), ['class' => "badge {$class}"]);
};

/** POST-кнопка действия (с CSRF и подтверждением). */
$postButton = static function (string $action, string $moduleId, string $label, string $btnClass, ?string $confirm = null): string {
    $form = Html::beginForm([$action], 'post', ['class' => 'd-inline']);
    $form .= Html::hiddenInput('moduleId', $moduleId);
    $form .= Html::submitButton($label, [
        'class' => "btn btn-sm {$btnClass}",
        'data' => $confirm !== null ? ['confirm' => $confirm] : [],
    ]);
    $form .= Html::endForm();
    return $form;
};
?>

<?= OperationReportModal::widget() ?>

<div class="modman-index">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h3 mb-0"><i class="bi bi-bricks me-2"></i><?= Html::encode($this->title) ?></h1>
        <div class="d-flex gap-2">
            <?= $postButton('recompile', '', 'Пересобрать конфиг', 'btn-outline-secondary') ?>
            <?= $postButton('rebuild-menus', '', 'Пересобрать меню', 'btn-outline-secondary') ?>
            <?php if ($pending !== []): ?>
                <?= $postButton('reconcile', '', 'Сверка (' . count($pending) . ')', 'btn-warning', 'Откатить незавершённые операции к чистому состоянию?') ?>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-muted small">
        Состояние модулей декларативно и единично; вся конфигурация приложения — производная и
        компилируется заново из реестра (compile-not-patch). Модуль на стадии тестирования.
    </p>

    <?php if ($pending !== []): ?>
        <div class="alert alert-warning">
            <strong>Незавершённые операции:</strong>
            <?= Html::encode(implode(', ', array_keys($pending))) ?>.
            Рекомендуется выполнить «Сверку».
        </div>
    <?php endif; ?>

    <?= Html::beginForm(['index'], 'get', ['class' => 'mb-3']) ?>
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <div class="input-group" style="max-width: 320px;">
                <?= Html::activeTextInput($search, 'q', ['class' => 'form-control', 'placeholder' => 'Поиск по id / пакету…']) ?>
                <?= Html::submitButton('<i class="bi bi-search"></i>', ['class' => 'btn btn-outline-primary']) ?>
            </div>
        </div>
        <div class="col-auto">
            <?= Html::dropDownList('status', $search->status, ModuleSearch::statusOptions(), [
                'class' => 'form-select',
                'onchange' => 'this.form.submit()',
            ]) ?>
        </div>
        <div class="col-auto">
            <div class="form-check">
                <?= Html::checkbox('updatesOnly', $search->updatesOnly, [
                    'class' => 'form-check-input',
                    'id' => 'updatesOnly',
                    'onchange' => 'this.form.submit()',
                ]) ?>
                <label class="form-check-label" for="updatesOnly">Только с обновлениями</label>
            </div>
        </div>
    </div>
    <?= Html::endForm() ?>

    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-modules" type="button">
                Модули <span class="badge text-bg-light"><?= $dataProvider->getTotalCount() ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-packages" type="button">
                Пакеты <span class="badge text-bg-light"><?= count($packages) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-3">
        <div class="tab-pane fade show active" id="tab-modules">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                    <tr>
                        <th><?= $sort->link('id', ['label' => 'ID']) ?></th>
                        <th>Пакет</th>
                        <th><?= $sort->link('status', ['label' => 'Статус']) ?></th>
                        <th><?= $sort->link('availableVersion', ['label' => 'Версия']) ?> (доступна / установлена)</th>
                        <th class="text-end">Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($modules as $m): ?>
                        <tr class="<?= $m->invalid ? 'table-warning' : '' ?>">
                            <td>
                                <?php if ($m->iconClass !== ''): ?>
                                    <i class="<?= Html::encode($m->iconClass) ?> me-1"></i>
                                <?php endif; ?>
                                <code><?= Html::encode($m->id) ?></code>
                                <?php if ($m->invalid && $m->invalidReason !== null): ?>
                                    <div class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i><?= Html::encode($m->invalidReason) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= Html::encode($m->package) ?></td>
                            <td>
                                <?= $statusBadge($m->status) ?>
                                <?php if ($m->system): ?>
                                    <span class="badge text-bg-dark" title="Системный модуль — управление из админки недоступно">системный</span>
                                <?php endif; ?>
                                <?php if ($m->orphan): ?>
                                    <span class="badge text-bg-dark" title="Пакет не найден в каталоге">orphan</span>
                                <?php endif; ?>
                                <?php if ($m->hasUpdate): ?>
                                    <span class="badge text-bg-info">обновление</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= Html::encode($m->availableVersion ?: '—') ?>
                                <span class="text-muted">/</span>
                                <?= Html::encode($m->installedVersion ?? '—') ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                    <?php if ($m->invalid): ?>
                                        <?= Html::button('Установить', [
                                            'class' => 'btn btn-sm btn-success',
                                            'disabled' => true,
                                            'title' => (string)$m->invalidReason,
                                        ]) ?>
                                    <?php else: ?>
                                        <?php if (!$m->orphan): ?>
                                            <?= Html::a('План', ['check', 'moduleId' => $m->id], ['class' => 'btn btn-sm btn-outline-info']) ?>
                                        <?php endif; ?>

                                        <?php if ($m->installed && $m->hasOptions): ?>
                                            <?= Html::a('Настройки', ['/Config/backend/config/index', 'category' => $m->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                                        <?php endif; ?>

                                        <?php if (!$m->installed && !$m->orphan && $m->editable): ?>
                                            <?= $postButton('install', $m->id, 'Установить', 'btn-success') ?>
                                        <?php endif; ?>

                                        <?php if ($m->installed && $m->hasUpdate): ?>
                                            <?= $postButton('update', $m->id, 'Обновить', 'btn-primary') ?>
                                        <?php endif; ?>

                                        <?php if ($m->installed && $m->editable): ?>
                                            <?= Html::a('План удаления', ['check', 'moduleId' => $m->id, 'op' => 'uninstall'], ['class' => 'btn btn-sm btn-outline-info']) ?>
                                            <?= $postButton('uninstall', $m->id, 'Удалить', 'btn-outline-danger', "Удалить модуль «{$m->id}»?") ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($modules === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Модули не найдены.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= LinkPager::widget([
                'pagination' => $dataProvider->getPagination(),
                'options' => ['class' => 'pagination pagination-sm mb-0'],
            ]) ?>
        </div>

        <div class="tab-pane fade" id="tab-packages">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                    <tr>
                        <th>Composer-пакет</th>
                        <th>Вид</th>
                        <th>Тип</th>
                        <th>moduleId</th>
                        <th>Версия</th>
                        <th>Лицензия</th>
                        <th>Источник</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($packages as $p): ?>
                        <tr>
                            <td>
                                <?= Html::encode($p->composerName) ?>
                                <?php if ($p->description !== ''): ?>
                                    <div class="text-muted small"><?= Html::encode($p->description) ?></div>
                                <?php endif; ?>
                                <?php if ($p->require !== []): ?>
                                    <details class="small mt-1">
                                        <summary class="text-muted">Зависимости (<?= count($p->require) ?>)</summary>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($p->require as $dep => $constraint): ?>
                                                <li><code><?= Html::encode((string)$dep) ?></code>: <?= Html::encode((string)$constraint) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= $p->isModule()
                                    ? '<span class="badge text-bg-primary">модуль</span>'
                                    : '<span class="badge text-bg-secondary">пакет</span>' ?></td>
                            <td class="small"><?= Html::encode($p->type) ?></td>
                            <td><?= $p->isModule() ? '<code>' . Html::encode((string)$p->moduleId) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><?= Html::encode($p->composerVersion ?: '—') ?></td>
                            <td class="small"><?= Html::encode($p->license ?: '—') ?></td>
                            <td><span class="badge text-bg-light"><?= Html::encode($p->sourceLabel) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($packages === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Пакеты не найдены.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
