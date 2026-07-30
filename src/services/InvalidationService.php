<?php

declare(strict_types=1);

namespace viesrood\cachekit\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use Throwable;
use viesrood\cachekit\jobs\WarmUrisJob;
use viesrood\cachekit\Plugin;

class InvalidationService
{
    /** @var array<string, string> */
    private array $oldUris = [];

    /** @var array<int, array<string, bool>> */
    private array $pending = [];

    public function rememberOldUri(Entry $entry): void
    {
        if (!$entry->id || !$entry->siteId) {
            return;
        }

        $uri = (new Query())
            ->select(['uri'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
            ->scalar();

        if (is_string($uri)) {
            $this->oldUris[$this->key($entry)] = $uri;
        }
    }

    /**
     * Cache-invalidatie mag een entry-save nooit laten mislukken; een niet
     * geleegde cache is te repareren, een geblokkeerde redactie niet. In
     * devMode gooien we wel door.
     */
    public function invalidate(Entry $entry, bool $deleted = false): void
    {
        try {
            $this->doInvalidate($entry, $deleted);
        } catch (Throwable $exception) {
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $exception;
            }
            Craft::warning(
                "CacheKit kon de cache voor entry {$entry->id} niet legen: {$exception->getMessage()}",
                'cachekit',
            );
        }
    }

    private function doInvalidate(Entry $entry, bool $deleted): void
    {
        if (ElementHelper::isDraftOrRevision($entry) || !$entry->siteId) {
            return;
        }

        $rule = $this->ruleFor($entry);
        if ($rule === null) {
            return;
        }

        $patterns = Plugin::getInstance()->getCache()->parseTargets($rule['uriPatterns'] ?? []);
        $selfUris = [];
        if (!empty($rule['refreshSelf'])) {
            if (is_string($entry->uri)) {
                $selfUris[] = Plugin::getInstance()->getCache()->normalizeTarget($entry->uri);
            }
            $oldUri = $this->oldUris[$this->key($entry)] ?? null;
            if (is_string($oldUri)) {
                $selfUris[] = Plugin::getInstance()->getCache()->normalizeTarget($oldUri);
            }
        }

        $patterns = array_values(array_unique(array_merge($patterns, $selfUris)));
        $deletedUris = Plugin::getInstance()->getCache()->deletePatterns((int)$entry->siteId, $patterns);
        $warmUris = array_merge($deletedUris, $this->explicitTargets($patterns));

        if (!$deleted && !empty($rule['refreshSelf']) && is_string($entry->uri)) {
            $warmUris[] = Plugin::getInstance()->getCache()->normalizeTarget($entry->uri);
        }
        if ($deleted || !$entry->enabled || !$entry->getEnabledForSite()) {
            $warmUris = array_values(array_diff($warmUris, $selfUris));
        }

        foreach (array_unique($warmUris) as $uri) {
            $this->pending[(int)$entry->siteId][$uri] = true;
        }
    }

    public function queuePatterns(int $siteId, array $patterns): array
    {
        $patterns = Plugin::getInstance()->getCache()->parseTargets($patterns);
        $deleted = Plugin::getInstance()->getCache()->deletePatterns($siteId, $patterns);
        $uris = array_values(array_unique(array_merge($deleted, $this->explicitTargets($patterns))));
        foreach ($uris as $uri) {
            $this->pending[$siteId][$uri] = true;
        }
        return $uris;
    }

    /**
     * Draait via onAfterRequest, dus buiten elke aanroeper om: hier mag niets
     * meer ontsnappen.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        try {
            if (!Plugin::getInstance()->getSettings()->warmAfterSave) {
                return;
            }

            foreach ($pending as $siteId => $uris) {
                if ($uris !== []) {
                    Queue::push(new WarmUrisJob([
                        'siteId' => (int)$siteId,
                        'uris' => array_keys($uris),
                    ]));
                }
            }
        } catch (Throwable $exception) {
            Craft::warning('CacheKit kon het opwarmen niet inplannen: ' . $exception->getMessage(), 'cachekit');
        }
    }

    private function ruleFor(Entry $entry): ?array
    {
        $section = $entry->getSection();
        if ($section === null) {
            return null;
        }
        foreach (Plugin::getInstance()->getSettings()->sectionRules as $rule) {
            if (($rule['section'] ?? null) === $section->handle) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * @param string[] $patterns
     * @return string[]
     */
    private function explicitTargets(array $patterns): array
    {
        return array_values(array_filter($patterns, static fn(string $uri): bool => !str_contains($uri, '*')));
    }

    private function key(Entry $entry): string
    {
        return $entry->id . ':' . $entry->siteId;
    }
}
