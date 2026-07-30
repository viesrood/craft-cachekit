<?php

declare(strict_types=1);

namespace viesrood\cachekit\variables;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use Twig\Markup;
use viesrood\cachekit\assets\CacheKitAsset;

class CacheKitVariable
{
    public function csrfInput(): Markup
    {
        $this->registerAsset();
        return Template::raw(Html::hiddenInput(
            Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            '',
            ['data-cachekit-csrf' => true],
        ));
    }

    public function csrfMeta(): Markup
    {
        $this->registerAsset();
        $name = Html::tag('meta', '', [
            'name' => 'csrf-token-name',
            'content' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'data-cachekit-csrf-meta' => 'name',
        ]);
        $token = Html::tag('meta', '', [
            'name' => 'csrf-token',
            'content' => '',
            'data-cachekit-csrf-meta' => 'token',
        ]);
        return Template::raw($name . "\n" . $token);
    }

    private function registerAsset(): void
    {
        Craft::$app->getView()->registerAssetBundle(CacheKitAsset::class);
    }
}
