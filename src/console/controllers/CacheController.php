<?php

declare(strict_types=1);

namespace viesrood\cachekit\console\controllers;

use Craft;
use craft\console\Controller;
use Throwable;
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
            $uris = [];
            foreach (Plugin::getInstance()->getCache()->liveEntryUris((int)$site->id) as $uri) {
                if (Plugin::getInstance()->getCache()->isCacheableUri($uri)) {
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

        $snapshot = Plugin::getInstance()->getSnapshot();
        try {
            $this->stdout("\nSnapshotpad: {$snapshot->getPath()}\n");
            $this->stdout("Vlagbestand: {$snapshot->getFlagPath()}\n");
        } catch (Throwable $exception) {
            $this->stdout("\nNoodmodus niet ingesteld: {$exception->getMessage()}\n");
            return ExitCode::OK;
        }
        $manifest = $snapshot->getManifest();
        $this->stdout('Snapshot gebouwd: ' . ($manifest['builtAt'] ?? 'nog nooit') . "\n");
        if ($manifest !== null) {
            $this->stdout("Snapshotpagina's: {$manifest['pages']}\n");
        }
        $this->stdout('Noodmodus actief: ' . ($snapshot->isEmergencyActive() ? 'JA' : 'nee') . "\n");
        return ExitCode::OK;
    }
}
