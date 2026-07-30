<?php

declare(strict_types=1);

namespace viesrood\cachekit\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $cachingEnabled = false;

    public string $cachePath = '$CACHEKIT_CACHE_PATH';

    /** @var string[] */
    public array $includedUriPatterns = [];

    /** @var string[] */
    public array $excludedUriPatterns = [];

    /**
     * @var array<int, array{section?: string, refreshSelf?: bool|string|int, uriPatterns?: string|array}>
     */
    public array $sectionRules = [];

    public bool $warmAfterSave = true;

    public int $warmRequestTimeout = 30;

    protected function defineRules(): array
    {
        return [
            [['cachePath'], 'required'],
            [['warmRequestTimeout'], 'integer', 'min' => 1, 'max' => 300],
            [['includedUriPatterns', 'excludedUriPatterns', 'sectionRules'], 'safe'],
        ];
    }
}
