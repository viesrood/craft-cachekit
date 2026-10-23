<?php

declare(strict_types=1);

namespace viesrood\cachekit\services;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Request;
use GuzzleHttp\Client;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use viesrood\cachekit\Plugin;

/**
 * De noodkopie van de site: een volledige, statische versie die Apache zonder
 * PHP kan serveren zodra het vlagbestand bestaat.
 *
 * Bewust een aparte map naast de live cache. Die wordt bij elke entry-save
 * uitgedund en is dus nooit compleet; het snapshot wordt in één keer gebouwd
 * en daarna atomair omgewisseld, zodat er nooit een halve kopie klaarstaat.
 */
class SnapshotService
{
    public const HEADER = 'X-CacheKit-Snapshot';
    public const NOT_FOUND_FILE = '_cachekit-404.html';
    public const MANIFEST_FILE = '_manifest.json';

    /** Een run met meer mislukte pagina's dan dit wordt verworpen. */
    private const MAX_FAILURE_RATIO = 0.2;

    /** Diepte van sitemapindexen die we volgen, tegen lussen. */
    private const MAX_SITEMAP_DEPTH = 3;

    private ?Client $client = null;

    public static function isSnapshotRequest(): bool
    {
        $request = Craft::$app->getRequest();
        return $request instanceof Request && $request->getHeaders()->has(self::HEADER);
    }

    public function isConfigured(): bool
    {
        try {
            $this->getPath();
            $this->getFlagPath();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function getPath(): string
    {
        $path = $this->resolvePath(Plugin::getInstance()->getSettings()->snapshotPath, 'CACHEKIT_SNAPSHOT_PATH');

        // Zelfde guard als bij de live cache: de map wordt bij elke build met
        // inhoud vervangen, dus hij mag nooit per ongeluk iets anders zijn.
        if (!str_contains(strtolower(basename($path)), 'cachekit')) {
            throw new RuntimeException('Het snapshotpad moet een map met "cachekit" in de naam zijn.');
        }
        if ($path === Plugin::getInstance()->getCache()->getBasePath()) {
            throw new RuntimeException('Het snapshotpad mag niet gelijk zijn aan het cachepad.');
        }
        if (!$this->isInside($path, $this->getWebroot())) {
            throw new RuntimeException('Het snapshotpad moet onder de webroot liggen, anders kan Apache er niet bij.');
        }

        return $path;
    }

    public function getFlagPath(): string
    {
        $path = $this->resolvePath(Plugin::getInstance()->getSettings()->emergencyFlagPath, 'CACHEKIT_EMERGENCY_FLAG');
        if ($this->isInside($path, $this->getWebroot())) {
            throw new RuntimeException('Het vlagbestand moet buiten de webroot liggen.');
        }
        return $path;
    }

    public function isEmergencyActive(): bool
    {
        try {
            return is_file($this->getFlagPath());
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getManifest(): ?array
    {
        try {
            $file = $this->getPath() . '/' . self::MANIFEST_FILE;
        } catch (RuntimeException) {
            return null;
        }
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Bouwt het snapshot in een tijdelijke map en wisselt die pas om als het
     * resultaat bruikbaar is. Bij een te slechte run blijft het oude snapshot
     * staan en volgt een exception.
     *
     * @return array{pages: int, files: int, failures: array<int, array{url: string, reason: string}>, missing: array<int, array{url: string, reason: string}>, path: string}
     */
    public function build(): array
    {
        $target = $this->getPath();
        $temporary = $target . '.tmp';
        $previous = $target . '.old';

        foreach ([$temporary, $previous] as $leftover) {
            if (is_dir($leftover)) {
                FileHelper::removeDirectory($leftover);
            }
        }
        FileHelper::createDirectory($temporary);

        $pages = 0;
        $files = 0;
        $failures = [];
        $missing = [];

        try {
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $result = $this->buildSite($site, $temporary);
                $pages += $result['pages'];
                $files += $result['files'];
                array_push($failures, ...$result['failures']);
                array_push($missing, ...$result['missing']);
            }

            $attempted = $pages + count($failures);
            if ($pages === 0) {
                throw new RuntimeException('Het snapshot bevat geen enkele pagina; het oude snapshot blijft staan.');
            }
            if (count($failures) / max(1, $attempted) > self::MAX_FAILURE_RATIO) {
                throw new RuntimeException(sprintf(
                    'Te veel mislukte pagina\'s (%d van %d); het oude snapshot blijft staan.',
                    count($failures),
                    $attempted,
                ));
            }

            $this->writeFile($temporary . '/' . self::MANIFEST_FILE, (string)json_encode([
                'builtAt' => date(DATE_ATOM),
                'craftVersion' => Craft::$app->getVersion(),
                'pages' => $pages,
                'files' => $files,
                'failures' => $failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (is_dir($target) && !rename($target, $previous)) {
                throw new RuntimeException('Kon het oude snapshot niet opzij zetten.');
            }
            if (!rename($temporary, $target)) {
                // Het oude snapshot terugzetten: liever een oude kopie dan geen.
                if (is_dir($previous)) {
                    @rename($previous, $target);
                }
                throw new RuntimeException('Kon het nieuwe snapshot niet op zijn plek zetten.');
            }
        } finally {
            if (is_dir($temporary)) {
                FileHelper::removeDirectory($temporary);
            }
            if (is_dir($previous)) {
                FileHelper::removeDirectory($previous);
            }
        }

        return [
            'pages' => $pages,
            'files' => $files,
            'failures' => $failures,
            'missing' => $missing,
            'path' => $target,
        ];
    }

    /**
     * Het blok voor de .htaccess, afgeleid uit de instellingen. Paden staan
     * relatief aan %{DOCUMENT_ROOT}, zodat hetzelfde blok lokaal en op
     * productie werkt zolang de mappen daar dezelfde onderlinge ligging hebben.
     *
     * @return array{rewrite: string, outside: string}
     */
    public function apacheConfig(): array
    {
        $webroot = $this->getWebroot();
        $snapshot = $this->relativePath($webroot, $this->getPath());
        $flag = '%{DOCUMENT_ROOT}/' . $this->relativePath($webroot, $this->getFlagPath());
        $snapshotRegex = preg_quote($snapshot, '#');
        $blocked = implode('|', array_map(static fn(string $prefix): string => preg_quote($prefix, '#'), $this->blockedPrefixes()));

        $rewrite = <<<APACHE
    # BEGIN CacheKit emergency
    # Gegenereerd door `php craft cachekit/snapshot/server-config`. Hoort direct
    # na de HTTPS- en hostredirects en vóór de gewone CacheKit-regels. Zolang
    # het vlagbestand bestaat draait er geen PHP meer: alles komt uit het
    # snapshot, querystrings en cookies worden genegeerd.

    # Het snapshot zelf is nooit rechtstreeks op te vragen.
    RewriteCond %{THE_REQUEST} ^\S+\s/+{$snapshotRegex}(/|\s|\?)
    RewriteRule ^ - [F,END]

    # Interne doorverwijzing naar de 404-pagina van het snapshot doorlaten.
    RewriteCond %{ENV:REDIRECT_STATUS} !^$
    RewriteRule ^{$snapshotRegex}/ - [END]

    RewriteCond {$flag} -f
    RewriteRule \.(php\d?|phtml|phar)(/|$) - [F,NC,END]

    RewriteCond {$flag} -f
    RewriteRule ^({$blocked})(/|$) - [R=503,END]

    RewriteCond {$flag} -f
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [END]

    RewriteCond {$flag} -f
    RewriteCond %{DOCUMENT_ROOT}/{$snapshot}/%{HTTP_HOST}/$1 -f
    RewriteRule ^(.+)$ {$snapshot}/%{HTTP_HOST}/$1 [END,E=CACHEKIT_EMERGENCY:1]

    RewriteCond {$flag} -f
    RewriteCond %{DOCUMENT_ROOT}/{$snapshot}/%{HTTP_HOST}/$1/index.html -f
    RewriteRule ^(.*)$ {$snapshot}/%{HTTP_HOST}/$1/index.html [END,E=CACHEKIT_EMERGENCY:1]

    RewriteCond {$flag} -f
    RewriteRule ^ - [R=404,END]
    # END CacheKit emergency
APACHE;

        $notFound = self::NOT_FOUND_FILE;
        $outside = <<<APACHE
# BEGIN CacheKit emergency (buiten mod_rewrite)
<If "-f '{$flag}'">
    ErrorDocument 404 /{$snapshot}/%{HTTP_HOST}/{$notFound}
    <IfModule mod_headers.c>
        Header always set X-CacheKit "EMERGENCY"
        Header always set Cache-Control "no-cache" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
        Header always set Retry-After "3600" "expr=%{REQUEST_STATUS} == 503"
    </IfModule>
</If>
# END CacheKit emergency
APACHE;

        return ['rewrite' => $rewrite, 'outside' => $outside];
    }

    /**
     * @return string[]
     */
    public function blockedPrefixes(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $prefixes = Plugin::getInstance()->getSettings()->emergencyBlockedPrefixes;
        $prefixes[] = $general->actionTrigger;
        if ($general->cpTrigger !== null) {
            $prefixes[] = $general->cpTrigger;
        }

        $clean = [];
        foreach ($this->values($prefixes) as $prefix) {
            $prefix = trim($prefix, "/ \t");
            if ($prefix !== '') {
                $clean[] = $prefix;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * @return array{pages: int, files: int, failures: array<int, array{url: string, reason: string}>, missing: array<int, array{url: string, reason: string}>}
     */
    private function buildSite(Site $site, string $root): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $cache = Plugin::getInstance()->getCache();
        $baseUrl = rtrim((string)Craft::getAlias($site->getBaseUrl()), '/') . '/';
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        if ($host === '') {
            throw new RuntimeException("De basis-URL van site {$site->handle} heeft geen geldige host.");
        }
        $hostRoot = $root . '/' . $cache->sanitizeHost($host);

        $pages = [];
        $missing = [];
        foreach ($cache->liveEntryUris((int)$site->id) as $uri) {
            $url = UrlHelper::siteUrl($uri === '__home__' ? '' : $uri, null, null, $site->id);
            if ($this->isAllowedUri($uri)) {
                $pages[$this->pathOf($url)] = $url;
            } else {
                $missing[] = ['url' => $url, 'reason' => 'uitgesloten'];
            }
        }

        $files = 0;
        $failures = [];
        $sitemaps = [];
        foreach ($this->values($settings->snapshotExtraUris) as $uri) {
            $url = UrlHelper::siteUrl(trim($uri, '/'), null, null, $site->id);
            $outcome = $this->snapshotUrl($url, $hostRoot, true);
            if ($outcome['ok']) {
                if ($outcome['html']) {
                    $pages[$this->pathOf($url)] = null;
                } else {
                    $files++;
                }
                if ($outcome['xml'] !== null) {
                    $sitemaps[] = $outcome['xml'];
                }
            } else {
                $failures[] = ['url' => $url, 'reason' => $outcome['reason']];
            }
        }

        if ($settings->snapshotFollowSitemaps) {
            foreach ($this->sitemapPages($sitemaps, $baseUrl, $hostRoot, $files, $failures) as $url) {
                $relative = substr($url, strlen($baseUrl));
                if ($this->isAllowedUri($relative)) {
                    $pages[$this->pathOf($url)] ??= $url;
                }
            }
        }

        $count = 0;
        foreach ($pages as $path => $url) {
            if ($url === null) {
                // Een extra URI die HTML bleek; die staat er al.
                $count++;
                continue;
            }
            $outcome = $this->snapshotUrl($url, $hostRoot, false);
            if ($outcome['ok']) {
                $count++;
            } else {
                $failures[] = ['url' => $url, 'reason' => $outcome['reason']];
                $missing[] = ['url' => $url, 'reason' => $outcome['reason']];
            }
        }

        $this->snapshotNotFound($baseUrl, $hostRoot, $failures);

        return ['pages' => $count, 'files' => $files, 'failures' => $failures, 'missing' => $missing];
    }

    /**
     * @return array{ok: bool, reason: string, html: bool, xml: ?SimpleXMLElement}
     */
    private function snapshotUrl(string $url, string $hostRoot, bool $allowFiles): array
    {
        $result = ['ok' => false, 'reason' => '', 'html' => false, 'xml' => null];

        try {
            $response = $this->client()->get($url, ['headers' => $this->headers()]);
        } catch (Throwable $exception) {
            $result['reason'] = $exception->getMessage();
            return $result;
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            $result['reason'] = "HTTP $status";
            return $result;
        }

        $body = (string)$response->getBody();
        $type = strtolower($response->getHeaderLine('Content-Type'));
        $path = $this->pathOf($url);

        if (str_starts_with($type, 'text/html')) {
            $this->writeFile($this->htmlFile($hostRoot, $path), $this->markHtml($body));
            $result['ok'] = true;
            $result['html'] = true;
            return $result;
        }

        // Alleen met een extensie: dan kiest Apache zelf het juiste
        // content-type. Een extensieloze niet-HTML-URL zou als HTML uitgaan.
        if (!$allowFiles || pathinfo($path, PATHINFO_EXTENSION) === '') {
            $result['reason'] = "geen HTML ($type)";
            return $result;
        }

        $this->writeFile($this->safeJoin($hostRoot, $path), $body);
        $result['ok'] = true;
        if (str_contains($type, 'xml')) {
            $result['xml'] = $this->parseXml($body);
        }
        return $result;
    }

    /**
     * Loopt sitemapindexen af (die worden zelf ook opgeslagen) en geeft de
     * pagina-URL's van de eigen site terug.
     *
     * @param SimpleXMLElement[] $sitemaps
     * @param array<int, array{url: string, reason: string}> $failures
     * @return string[]
     */
    private function sitemapPages(array $sitemaps, string $baseUrl, string $hostRoot, int &$files, array &$failures): array
    {
        $pages = [];
        $seen = [];
        $queue = array_map(static fn(SimpleXMLElement $xml): array => [$xml, 0], $sitemaps);

        while ($queue !== []) {
            [$xml, $depth] = array_shift($queue);
            $isIndex = strtolower($xml->getName()) === 'sitemapindex';

            foreach ($xml->xpath('//*[local-name()="loc"]') ?: [] as $loc) {
                $url = trim((string)$loc);
                if (!str_starts_with($url, $baseUrl) || str_contains($url, '?') || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                if (!$isIndex) {
                    $pages[] = $url;
                    continue;
                }
                if ($depth >= self::MAX_SITEMAP_DEPTH) {
                    continue;
                }
                $outcome = $this->snapshotUrl($url, $hostRoot, true);
                if (!$outcome['ok']) {
                    $failures[] = ['url' => $url, 'reason' => $outcome['reason']];
                    continue;
                }
                $files++;
                if ($outcome['xml'] !== null) {
                    $queue[] = [$outcome['xml'], $depth + 1];
                }
            }
        }

        return $pages;
    }

    /**
     * De 404-pagina van de site, eenmalig gerenderd met een URL die niet
     * bestaat. Apache toont hem via ErrorDocument, mét status 404.
     *
     * @param array<int, array{url: string, reason: string}> $failures
     */
    private function snapshotNotFound(string $baseUrl, string $hostRoot, array &$failures): void
    {
        $url = $baseUrl . 'cachekit-snapshot-404-' . bin2hex(random_bytes(4));
        try {
            $response = $this->client()->get($url, ['headers' => $this->headers()]);
            $type = strtolower($response->getHeaderLine('Content-Type'));
            if ($response->getStatusCode() === 404 && str_starts_with($type, 'text/html')) {
                $this->writeFile($hostRoot . '/' . self::NOT_FOUND_FILE, $this->markHtml((string)$response->getBody()));
                return;
            }
            $reason = 'verwachtte een 404 in HTML, kreeg HTTP ' . $response->getStatusCode();
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();
        }
        // Geen harde fout: zonder eigen 404 valt Apache terug op zijn standaardpagina.
        Craft::warning("CacheKit kon de 404-pagina niet in het snapshot zetten: $reason", 'cachekit');
    }

    private function isAllowedUri(string $uri): bool
    {
        $cache = Plugin::getInstance()->getCache();
        return $cache->isCacheableUri($uri)
            || $cache->matchesAny($uri, Plugin::getInstance()->getSettings()->snapshotIncludeUriPatterns);
    }

    /**
     * Markeert de pagina als snapshot, zodat de CSRF-hydrator weet dat hij
     * niet naar Craft hoeft te vragen.
     */
    private function markHtml(string $html): string
    {
        $meta = sprintf('<meta name="cachekit-snapshot" content="%s">', date(DATE_ATOM));
        $marked = preg_replace('#</head>#i', $meta . '</head>', $html, 1, $count);
        if (!is_string($marked) || $count === 0) {
            $marked = $html;
        }
        $marked = preg_replace('/<!-- Cached by (?:Blitz|CacheKit).*?-->\s*$/s', '', $marked) ?? $marked;
        return $marked . sprintf("\n<!-- CacheKit snapshot %s -->", date(DATE_ATOM));
    }

    private function htmlFile(string $hostRoot, string $path): string
    {
        return $path === '' ? $hostRoot . '/index.html' : $this->safeJoin($hostRoot, $path) . '/index.html';
    }

    /**
     * Apache matcht het gedecodeerde pad, dus de bestanden krijgen ook de
     * gedecodeerde naam. normalizeUri() houdt '..' en nulbytes buiten.
     */
    private function safeJoin(string $hostRoot, string $path): string
    {
        $path = Plugin::getInstance()->getCache()->normalizeUri($path);
        $file = FileHelper::normalizePath($hostRoot . '/' . $path);
        if (!str_starts_with($file, $hostRoot . '/')) {
            throw new RuntimeException('Ongeldig snapshotpad.');
        }
        return $file;
    }

    private function pathOf(string $url): string
    {
        return trim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
    }

    private function parseXml(string $body): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
            return $xml instanceof SimpleXMLElement ? $xml : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function writeFile(string $file, string $content): void
    {
        FileHelper::createDirectory(dirname($file));
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException("Kon $file niet schrijven.");
        }
        @chmod($file, 0664);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'X-CacheKit-Refresh' => '1',
            self::HEADER => '1',
        ];
    }

    private function client(): Client
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => Plugin::getInstance()->getSettings()->warmRequestTimeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => false,
        ]);
    }

    private function resolvePath(string $configured, string $envName): string
    {
        $value = trim((string)App::parseEnv($configured));
        if ($value === '' || str_starts_with($value, '$')) {
            throw new RuntimeException("$envName is niet ingesteld.");
        }
        $path = rtrim(FileHelper::normalizePath(Craft::getAlias($value)), '/');
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException("$envName moet een absoluut pad zijn.");
        }
        return $path;
    }

    private function getWebroot(): string
    {
        return rtrim(FileHelper::normalizePath((string)Craft::getAlias('@webroot')), '/');
    }

    private function isInside(string $path, string $directory): bool
    {
        return str_starts_with($path . '/', $directory . '/') && $path !== $directory;
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = array_values(array_filter(explode('/', $from), 'strlen'));
        $toParts = array_values(array_filter(explode('/', $to), 'strlen'));
        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }
        return implode('/', array_merge(array_fill(0, count($fromParts), '..'), $toParts));
    }

    /**
     * Lijstinstellingen komen uit config als strings en uit de CP als
     * tabelrijen; beide vormen accepteren.
     *
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function values(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['uriPattern'] ?? '';
            }
            if (is_scalar($value) && trim((string)$value) !== '') {
                $result[] = trim((string)$value);
            }
        }
        return $result;
    }
}
