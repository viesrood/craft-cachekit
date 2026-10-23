<?php

declare(strict_types=1);

namespace viesrood\cachekit\console\controllers;

use craft\console\Controller;
use Throwable;
use viesrood\cachekit\Plugin;
use yii\console\ExitCode;

/**
 * De noodkopie: een statische versie van de site die Apache zonder PHP
 * serveert zolang het vlagbestand bestaat.
 */
class SnapshotController extends Controller
{
    /** Toon na een geslaagde build ook welke pagina's ontbreken. */
    public bool $report = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'build') {
            $options[] = 'report';
        }
        return $options;
    }

    /**
     * Bouwt het snapshot. Zwijgt bij succes, zodat hij als cronregel geen
     * logbestand laat aangroeien; fouten gaan naar stderr met exit-code 1.
     */
    public function actionBuild(): int
    {
        try {
            $result = Plugin::getInstance()->getSnapshot()->build();
        } catch (Throwable $exception) {
            $this->stderr('CacheKit-snapshot mislukt: ' . $exception->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->report) {
            $this->stdout("Snapshot: {$result['path']}\n");
            $this->stdout("Pagina's: {$result['pages']}, overige bestanden: {$result['files']}\n");
            foreach ($result['failures'] as $failure) {
                $this->stdout("  mislukt:   {$failure['url']} ({$failure['reason']})\n");
            }
            foreach ($result['missing'] as $missing) {
                if ($missing['reason'] === 'uitgesloten') {
                    $this->stdout("  ontbreekt: {$missing['url']} (uitgesloten)\n");
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Print het .htaccess-blok voor de noodmodus, afgeleid uit de instellingen.
     */
    public function actionServerConfig(): int
    {
        try {
            $config = Plugin::getInstance()->getSnapshot()->apacheConfig();
        } catch (Throwable $exception) {
            $this->stderr($exception->getMessage() . "\n");
            return ExitCode::CONFIG;
        }

        $this->stdout("# 1. Binnen <IfModule mod_rewrite.c>, direct na de redirects en vóór de\n");
        $this->stdout("#    gewone CacheKit-regels:\n\n");
        $this->stdout($config['rewrite'] . "\n\n");
        $this->stdout("# 2. Buiten <IfModule mod_rewrite.c>, ergens in dezelfde .htaccess:\n\n");
        $this->stdout($config['outside'] . "\n");
        return ExitCode::OK;
    }
}
