<?php

declare(strict_types=1);

namespace viesrood\cachekit\utilities;

use Craft;
use craft\base\Utility;
use Throwable;
use viesrood\cachekit\Plugin;

class CacheUtility extends Utility
{
    public static function displayName(): string
    {
        return 'CacheKit';
    }

    public static function id(): string
    {
        return 'cachekit';
    }

    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    public static function contentHtml(): string
    {
        $path = '';
        $error = null;
        $count = 0;
        try {
            $path = Plugin::getInstance()->getCache()->getBasePath();
            $count = Plugin::getInstance()->getCache()->countAll();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return Craft::$app->getView()->renderTemplate('cachekit/utility', [
            'path' => $path,
            'count' => $count,
            'error' => $error,
        ]);
    }
}
