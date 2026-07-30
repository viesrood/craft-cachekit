<?php

declare(strict_types=1);

namespace viesrood\cachekit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use viesrood\cachekit\Plugin;
use yii\console\ExitCode;

class CacheController extends Controller
{
    public function actionClear(string $targets = '*'): int
    {
        $patterns = Plugin::getInstance()->getCache()->parseTargets($targets);
        $count = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $count += count(Plugin::getInstance()->getCache()->deletePatterns((int)$site->id, $patterns));
        }
        $this->stdout("$count gecachte pagina('s) verwijderd.\n");
        return ExitCode::OK;
    }

    public function actionRefresh(string $targets = '*'): int
    {
        $patterns = Plugin::getInstance()->getCache()->parseTargets($targets);
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            Plugin::getInstance()->getInvalidation()->queuePatterns((int)$site->id, $patterns);
        }
        Plugin::getInstance()->getInvalidation()->flush();
        $this->stdout("Cacheverversing ingepland.\n");
        return ExitCode::OK;
    }

    public function actionWarm(): int
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            // Alleen de URI's ophalen; volledige elementen laden loopt op een
            // grote site onnodig het geheugen in.
            $uris = [];
            $found = Entry::find()
                ->siteId($site->id)
                ->status('live')
                ->uri(':notempty:')
                ->select(['elements_sites.uri'])
                ->asArray()
                ->column();

            foreach ($found as $uri) {
                if (is_string($uri) && Plugin::getInstance()->getCache()->isCacheableUri($uri)) {
                    $uris[] = $uri;
                }
            }
            Plugin::getInstance()->getInvalidation()->queuePatterns((int)$site->id, $uris);
        }
        Plugin::getInstance()->getInvalidation()->flush();
        $this->stdout("Cacheopwarming ingepland.\n");
        return ExitCode::OK;
    }

    public function actionDiagnose(): int
    {
        $cache = Plugin::getInstance()->getCache();
        $path = $cache->getBasePath();
        $webroot = Craft::getAlias('@webroot');
        $this->stdout("Cachepad: $path\n");
        $this->stdout('Binnen @webroot: ' . (str_starts_with($path, rtrim($webroot, '/') . '/') ? 'ja' : 'nee') . "\n");
        $this->stdout('Schrijfbaar: ' . ((is_dir($path) ? is_writable($path) : is_writable(dirname($path))) ? 'ja' : 'nee') . "\n");
        $this->stdout('Pagina’s: ' . $cache->countAll() . "\n");
        $this->stdout('Caching actief: ' . (Plugin::getInstance()->getSettings()->cachingEnabled ? 'ja' : 'nee') . "\n");
        return ExitCode::OK;
    }
}
