<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace modules\modmanNew\controllers\backend;

use modules\modmanNew\forms\backend\search\ModuleSearch;
use modules\modmanNew\lifecycle\OperationReport;
use modules\modmanNew\ModuleManager;
use Throwable;
use Yii;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

/**
 * Backend-интерфейс новой системы управления модулями.
 *
 * Контроллёр тонкий: вся логика — в {@see ModuleManager} и хендлерах. Здесь только разбор запроса,
 * показ {@see OperationReport} через flash и рендер. Мутации — только POST (с CSRF из форм).
 */
final class ModulesController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly ModuleManager $manager,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'install' => ['POST'],
                    'uninstall' => ['POST'],
                    'update' => ['POST'],
                    'reconcile' => ['POST'],
                    'recompile' => ['POST'],
                ],
            ],
        ]);
    }

    public function actionIndex(): string
    {
        $search = new ModuleSearch();
        $search->load(Yii::$app->request->queryParams);

        foreach ($this->manager->warnings() as $warning) {
            Yii::$app->session->addFlash('warning', $warning);
        }

        return $this->render('index', [
            'search' => $search,
            'modules' => $search->filterModules($this->manager->modules()),
            'packages' => $search->filterPackages($this->manager->packages()),
            'pending' => $this->manager->pending(),
        ]);
    }

    public function actionCheck(string $moduleId): string
    {
        return $this->render('plan', [
            'plan' => $this->manager->check($moduleId),
        ]);
    }

    public function actionInstall(): Response
    {
        $this->flashReport($this->manager->install($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionUninstall(): Response
    {
        $this->flashReport($this->manager->uninstall($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionUpdate(): Response
    {
        $this->flashReport($this->manager->update($this->requireModuleId()));
        return $this->redirect(['index']);
    }

    public function actionReconcile(): Response
    {
        $this->flashReport($this->manager->reconcile());
        return $this->redirect(['index']);
    }

    public function actionRecompile(): Response
    {
        try {
            $artifacts = $this->manager->recompile();
            Yii::$app->session->addFlash('success', 'Конфигурация перекомпилирована из реестра.');
            foreach ($artifacts->warnings as $warning) {
                Yii::$app->session->addFlash('warning', $warning);
            }
        } catch (Throwable $e) {
            Yii::$app->errorHandler->logException($e);
            Yii::$app->session->addFlash('error', 'Ошибка перекомпиляции: ' . $e->getMessage());
        }
        return $this->redirect(['index']);
    }

    /**
     * Достаёт обязательный moduleId из тела POST-запроса (формы кладут его в hiddenInput).
     *
     * Важно: web-контроллёр Yii биндит аргументы экшена из query-параметров, а не из тела POST,
     * поэтому мутирующие действия читают moduleId отсюда, а не через параметр метода.
     *
     * @throws BadRequestHttpException если параметр отсутствует
     */
    private function requireModuleId(): string
    {
        $moduleId = (string)Yii::$app->request->post('moduleId', '');
        if ($moduleId === '') {
            throw new BadRequestHttpException('Отсутствует обязательный параметр moduleId.');
        }
        return $moduleId;
    }

    /**
     * Перекладывает отчёт операции во flash-сообщения (UI-агностичный сервис → представление).
     */
    private function flashReport(OperationReport $report): void
    {
        $session = Yii::$app->session;

        foreach ($report->steps() as $message) {
            $session->addFlash('info', '• ' . $message);
        }
        foreach ($report->infos() as $message) {
            $session->addFlash($report->isSuccessful() ? 'success' : 'info', $message);
        }
        foreach ($report->warnings() as $message) {
            $session->addFlash('warning', $message);
        }
        foreach ($report->errors() as $message) {
            $session->addFlash('error', $message);
        }
    }
}
