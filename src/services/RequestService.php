<?php

declare(strict_types=1);

namespace viesrood\cachekit\services;

use Craft;
use craft\web\Request;
use craft\web\Response;
use Throwable;
use viesrood\cachekit\Plugin;

class RequestService
{
    public function isCacheableRequest(): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->cachingEnabled) {
            return false;
        }

        $request = Craft::$app->getRequest();
        if (
            !$request instanceof Request
            || !$request->getIsSiteRequest()
            || !$request->getIsGet()
            || $request->getIsActionRequest()
            || $request->getIsAjax()
            || $this->hasPublicQueryString($request)
            || Craft::$app->getUser()->getIdentity() !== null
        ) {
            return false;
        }

        return Plugin::getInstance()->getCache()->isCacheableUri($request->getPathInfo());
    }

    /**
     * Een onbruikbaar cachepad of een kapot patroon mag nooit de pagina zelf
     * slopen: zonder caching werkt de site gewoon. In devMode gooien we wel
     * door, zodat een configuratiefout lokaal meteen zichtbaar is.
     */
    public function cacheResponse(Response $response): void
    {
        try {
            $this->doCacheResponse($response);
        } catch (Throwable $exception) {
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $exception;
            }
            Craft::warning('CacheKit kon de respons niet cachen: ' . $exception->getMessage(), 'cachekit');
        }
    }

    private function doCacheResponse(Response $response): void
    {
        if (!$this->isCacheableRequest() || $response->getStatusCode() !== 200) {
            return;
        }

        $contentType = $response->getContentType();
        if ($contentType !== null && $contentType !== 'text/html') {
            return;
        }

        $content = $response->content;
        if (!is_string($content) || trim($content) === '') {
            return;
        }

        $request = Craft::$app->getRequest();
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        Plugin::getInstance()->getCache()->save($siteId, $request->getPathInfo(), $content);

        $response->getHeaders()->set('X-CacheKit', 'MISS');
        $response->getHeaders()->set('Cache-Control', 'no-cache');
        $response->getHeaders()->remove('Pragma');
        $response->getHeaders()->remove('Expires');
    }

    private function hasPublicQueryString(Request $request): bool
    {
        $params = $request->getQueryParams();
        unset($params[Craft::$app->getConfig()->getGeneral()->pathParam]);
        return $params !== [];
    }
}
