<?php

declare(strict_types=1);

namespace viesrood\cachekit\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

class CsrfController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    public function actionToken(): Response
    {
        $response = $this->asJson([
            'param' => Craft::$app->getRequest()->csrfParam,
            'token' => Craft::$app->getRequest()->getCsrfToken(),
        ]);
        $response->getHeaders()->set('Cache-Control', 'no-store');
        return $response;
    }
}
