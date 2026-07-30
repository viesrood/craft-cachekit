<?php

declare(strict_types=1);

namespace viesrood\cachekit\assets;

use craft\web\AssetBundle;

class CacheKitAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = dirname(__DIR__) . '/resources';
        $this->js = ['cachekit.js'];
        parent::init();
    }
}
