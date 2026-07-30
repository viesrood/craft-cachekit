<?php

declare(strict_types=1);

namespace viesrood\cachekit\services;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\models\Site;
use RuntimeException;
use Throwable;
use viesrood\cachekit\Plugin;

class CacheService
{
    public function getBasePath(): string
    {
        $configured = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->cachePath));
        if ($configured === '') {
            throw new RuntimeException('CACHEKIT_CACHE_PATH is niet ingesteld.');
        }

        $path = rtrim(FileHelper::normalizePath(Craft::getAlias($configured)), '/');

        // Bewust op de mapnaam zelf, niet op het hele pad: clearAll() verwijdert
        // deze map met inhoud, dus een toevallige "cachekit" hogerop in het pad
        // mag nooit een andere map tot doelwit maken.
        if (!str_contains(strtolower(basename($path)), 'cachekit')) {
            throw new RuntimeException('Het CacheKit-pad moet een map met "cachekit" in de naam zijn.');
        }

        return $path;
    }

    public function getSitePath(int $siteId): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        if (!$site instanceof Site) {
            throw new RuntimeException("Onbekende site-ID: $siteId.");
        }

        $host = parse_url(Craft::getAlias($site->getBaseUrl()), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException("De basis-URL van site $siteId heeft geen geldige host.");
        }

        return $this->getBasePath() . '/' . $this->sanitizeHost($host);
    }

    public function getFilePath(int $siteId, string $uri): string
    {
        $uri = $this->normalizeUri($uri);
        $relative = $uri === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $uri)));
        $path = FileHelper::normalizePath($this->getSitePath($siteId) . $relative . '/index.html');
        $sitePath = $this->getSitePath($siteId) . '/';

        if (!str_starts_with($path, $sitePath)) {
            throw new RuntimeException('Ongeldig cachepad.');
        }

        return $path;
    }

    public function save(int $siteId, string $uri, string $content): void
    {
        $filePath = $this->getFilePath($siteId, $uri);
        FileHelper::createDirectory(dirname($filePath));

        $content = preg_replace('/<!-- Cached by (?:Blitz|CacheKit).*?-->\s*$/s', '', $content) ?? $content;
        $content .= sprintf("\n<!-- Cached by CacheKit on %s -->", date(DATE_ATOM));

        $temporary = tempnam(dirname($filePath), '.cachekit-');
        if ($temporary === false) {
            throw new RuntimeException('Kon geen tijdelijk cachebestand maken.');
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException('Kon het cachebestand niet schrijven.');
            }
            @chmod($temporary, 0664);
            if (!rename($temporary, $filePath)) {
                throw new RuntimeException('Kon het cachebestand niet atomisch vervangen.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param string[] $patterns
     * @return string[] URI's die bestonden en zijn verwijderd
     */
    public function deletePatterns(int $siteId, array $patterns): array
    {
        $patterns = array_values(array_unique(array_map([$this, 'normalizeTarget'], $patterns)));
        $deleted = [];

        foreach ($this->listUris($siteId) as $uri) {
            foreach ($patterns as $pattern) {
                if ($this->matches($uri, $pattern)) {
                    $file = $this->getFilePath($siteId, $uri);
                    if (is_file($file) && @unlink($file)) {
                        $deleted[] = $uri;
                        $this->removeEmptyParents(dirname($file), $this->getSitePath($siteId));
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($deleted));
    }

    public function clearAll(): void
    {
        $basePath = $this->getBasePath();
        if (!is_dir($basePath)) {
            return;
        }

        FileHelper::removeDirectory($basePath);
    }

    /**
     * @return string[]
     */
    public function listUris(int $siteId): array
    {
        $sitePath = $this->getSitePath($siteId);
        if (!is_dir($sitePath)) {
            return [];
        }

        $uris = [];
        foreach (FileHelper::findFiles($sitePath, ['only' => ['index.html']]) as $file) {
            $relative = trim(substr(FileHelper::normalizePath(dirname($file)), strlen($sitePath)), '/');
            $segments = $relative === '' ? [] : explode('/', $relative);
            $uris[] = implode('/', array_map('rawurldecode', $segments));
        }

        sort($uris);
        return $uris;
    }

    public function countAll(): int
    {
        $count = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $count += count($this->listUris((int)$site->id));
        }
        return $count;
    }

    public function isCacheableUri(string $uri): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        // Een bezoeker mag alles in een URL zetten. Een pad dat niet te
        // normaliseren valt is simpelweg niet cachebaar, geen fout.
        try {
            $uri = $this->normalizeUri($uri);
        } catch (RuntimeException) {
            return false;
        }

        foreach ($this->patterns($settings->excludedUriPatterns) as $pattern) {
            if ($this->matches($uri, $pattern)) {
                return false;
            }
        }

        foreach ($this->patterns($settings->includedUriPatterns) as $pattern) {
            if ($this->matches($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeTarget(string $target): string
    {
        $target = trim($target);
        if (preg_match('#^https?://#i', $target)) {
            $host = parse_url($target, PHP_URL_HOST);
            $allowedHosts = array_map(
                static fn(Site $site): ?string => parse_url(Craft::getAlias($site->getBaseUrl()), PHP_URL_HOST),
                Craft::$app->getSites()->getAllSites(),
            );
            if (!is_string($host) || !in_array($host, $allowedHosts, true)) {
                throw new RuntimeException('Alleen URL’s van geconfigureerde Craft-sites zijn toegestaan.');
            }
            $target = (string)(parse_url($target, PHP_URL_PATH) ?? '');
        }

        if (str_contains($target, '?') || str_contains($target, '#')) {
            throw new RuntimeException('Querystrings en fragmenten zijn niet toegestaan in cachepatronen.');
        }

        $wildcard = str_contains($target, '*');
        $target = $this->normalizeUri($target, $wildcard);
        return $target;
    }

    public function normalizeUri(string $uri, bool $allowWildcard = false): string
    {
        $uri = rawurldecode(trim($uri));
        $uri = trim($uri, '/');
        if ($uri === '__home__') {
            return '';
        }
        if (str_contains($uri, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $uri)) {
            throw new RuntimeException('Ongeldige URI.');
        }
        if (!$allowWildcard && str_contains($uri, '*')) {
            throw new RuntimeException('Wildcards zijn hier niet toegestaan.');
        }

        return $uri;
    }

    public function matches(string $uri, string $pattern): bool
    {
        $uri = $this->normalizeUri($uri);
        $pattern = $this->normalizeTarget($pattern);
        $quoted = preg_quote($pattern, '#');
        $regex = '#^' . str_replace('\*', '.*', $quoted) . '$#u';
        return (bool)preg_match($regex, $uri);
    }

    /**
     * @return string[]
     */
    public function parseTargets(string|array $targets): array
    {
        if (is_string($targets)) {
            $targets = preg_split('/[\r\n,]+/', $targets) ?: [];
        }

        $parsed = [];
        foreach ($targets as $target) {
            if (!is_scalar($target) || trim((string)$target) === '') {
                continue;
            }
            $parsed[] = $this->normalizeTarget((string)$target);
        }

        return array_values(array_unique($parsed));
    }

    /**
     * @return string[]
     */
    private function patterns(array $patterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            if (is_array($pattern)) {
                $pattern = $pattern['uriPattern'] ?? '';
            }
            if (is_scalar($pattern) && trim((string)$pattern) !== '') {
                $result[] = $this->normalizeTarget((string)$pattern);
            }
        }
        return $result;
    }

    private function sanitizeHost(string $host): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9.-]/i', '', $host));
    }

    private function removeEmptyParents(string $directory, string $stopAt): void
    {
        $directory = FileHelper::normalizePath($directory);
        $stopAt = FileHelper::normalizePath($stopAt);
        while ($directory !== $stopAt && str_starts_with($directory, $stopAt . '/')) {
            try {
                if (!is_dir($directory) || (new \FilesystemIterator($directory))->valid()) {
                    break;
                }
                @rmdir($directory);
            } catch (Throwable) {
                break;
            }
            $directory = dirname($directory);
        }
    }
}
