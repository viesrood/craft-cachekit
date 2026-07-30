<?php

declare(strict_types=1);

namespace viesrood\cachekit\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\helpers\UrlHelper;
use Throwable;
use viesrood\cachekit\Plugin;

class WarmUrisJob extends BaseJob
{
    public int $siteId;

    /** @var string[] */
    public array $uris = [];

    public function execute($queue): void
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId, true);
        if ($site === null) {
            return;
        }

        $client = Craft::createGuzzleClient([
            'timeout' => Plugin::getInstance()->getSettings()->warmRequestTimeout,
            'http_errors' => false,
            'verify' => false,
        ]);

        $total = max(1, count($this->uris));
        foreach (array_values(array_unique($this->uris)) as $index => $uri) {
            try {
                $response = $client->get(UrlHelper::siteUrl($uri, null, null, $site->id), [
                    'headers' => ['X-CacheKit-Refresh' => '1'],
                ]);
                if ($response->getStatusCode() >= 400) {
                    Craft::warning(
                        "CacheKit kon '$uri' niet opwarmen: HTTP {$response->getStatusCode()}",
                        __METHOD__,
                    );
                }
            } catch (Throwable $exception) {
                Craft::warning("CacheKit kon '$uri' niet opwarmen: {$exception->getMessage()}", __METHOD__);
            }
            $this->setProgress($queue, ($index + 1) / $total);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'CacheKit-pagina’s opwarmen';
    }
}
