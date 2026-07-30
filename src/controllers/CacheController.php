<?php

declare(strict_types=1);

namespace viesrood\cachekit\controllers;

use Craft;
use craft\web\Controller;
use Throwable;
use viesrood\cachekit\Plugin;
use yii\web\Response;

class CacheController extends Controller
{
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('cachekit:manage');

        try {
            $targets = Plugin::getInstance()->getCache()->parseTargets(
                Craft::$app->getRequest()->getBodyParam('targets', ''),
            );
            $count = 0;
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $count += count(Plugin::getInstance()->getCache()->deletePatterns((int)$site->id, $targets));
            }
            Craft::$app->getSession()->setNotice("$count gecachte pagina('s) verwijderd.");
        } catch (Throwable $exception) {
            Craft::$app->getSession()->setError($exception->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionRefresh(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('cachekit:manage');

        try {
            $targets = Plugin::getInstance()->getCache()->parseTargets(
                Craft::$app->getRequest()->getBodyParam('targets', ''),
            );
            $count = 0;
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $count += count(Plugin::getInstance()->getInvalidation()->queuePatterns((int)$site->id, $targets));
            }
            Plugin::getInstance()->getInvalidation()->flush();
            Craft::$app->getSession()->setNotice("$count pagina('s) ingepland om te verversen.");
        } catch (Throwable $exception) {
            Craft::$app->getSession()->setError($exception->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionClearAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('cachekit:clearAll');
        Plugin::getInstance()->getCache()->clearAll();
        Craft::$app->getSession()->setNotice('De volledige CacheKit-cache is geleegd.');
        return $this->redirectToPostedUrl();
    }
}
