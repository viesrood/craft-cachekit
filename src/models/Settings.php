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

    /**
     * Map voor de noodkopie van de site. Moet onder de webroot liggen, want
     * Apache serveert hem rechtstreeks zodra de noodmodus aan staat.
     */
    public string $snapshotPath = '$CACHEKIT_SNAPSHOT_PATH';

    /**
     * Zolang dit bestand bestaat staat de noodmodus aan. Moet buiten de
     * webroot liggen, zodat niemand hem via het web kan zetten of weghalen.
     */
    public string $emergencyFlagPath = '$CACHEKIT_EMERGENCY_FLAG';

    /** @var string[] Routes zonder entry die ook in het snapshot moeten. */
    public array $snapshotExtraUris = ['robots.txt', 'sitemap.xml'];

    /** Volg de sitemaps uit de extra URI's naar sub-sitemaps en pagina's. */
    public bool $snapshotFollowSitemaps = true;

    /**
     * @var string[] Patronen die buiten de live cache vallen maar in de
     * noodmodus wel statisch mogen, zoals een contactpagina.
     */
    public array $snapshotIncludeUriPatterns = [];

    /**
     * @var string[] Padprefixen die in de noodmodus een 503 krijgen. De
     * cpTrigger en actionTrigger van Craft komen er altijd automatisch bij.
     */
    public array $emergencyBlockedPrefixes = ['api'];

    protected function defineRules(): array
    {
        return [
            [['cachePath'], 'required'],
            [['warmRequestTimeout'], 'integer', 'min' => 1, 'max' => 300],
            [['includedUriPatterns', 'excludedUriPatterns', 'sectionRules'], 'safe'],
            [['snapshotPath', 'emergencyFlagPath'], 'string'],
            [['snapshotExtraUris', 'snapshotIncludeUriPatterns', 'emergencyBlockedPrefixes'], 'safe'],
            [['snapshotFollowSitemaps'], 'boolean'],
        ];
    }
}
