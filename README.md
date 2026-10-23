# CacheKit

CacheKit is a small static page cache for Craft CMS 5. Pages are stored as HTML
and can be served directly by Apache or Nginx. The plugin uses explicit section
and URI rules and keeps no database index of template queries.

## Installation

```bash
composer require viesrood/craft-cachekit
php craft plugin/install cachekit
```

Set an absolute, shared cache path:

```dotenv
CACHEKIT_CACHE_PATH=/home/site/public_html/cache/cachekit
```

The path has to resolve to the same location for web, queue and console
processes. Test that with:

```bash
php craft cachekit/cache/diagnose
```

## Apache

Add the following rules after your redirects and before the Craft front
controller:

```apache
RewriteRule ^cache/cachekit/ - [F,END]

RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP:X-CacheKit-Refresh} ^$
RewriteCond %{HTTP:Cookie} !(^|;\s*)[^=;]*_identity= [NC]
RewriteCond %{DOCUMENT_ROOT}/cache/cachekit/%{HTTP_HOST}/$1/index.html -f
RewriteRule ^(.*)$ cache/cachekit/%{HTTP_HOST}/$1/index.html [END,E=CACHEKIT_HIT:1]
```

And inside `mod_headers`:

```apache
<Files "index.html">
    Header always set X-CacheKit "HIT"
    Header always set Cache-Control "no-cache"
</Files>
```

## Managing the cache

Use the CacheKit utility in the Craft control panel to clear or refresh exact
URLs or wildcards. The same actions are available on the console:

```bash
php craft cachekit/cache/clear 'articles/*'
php craft cachekit/cache/refresh 'articles/*'
php craft cachekit/cache/warm
```

A wildcard spans multiple path segments. Query strings are never cached.

## CSRF on cached pages

A CSRF token is specific to a single visitor and must never be stored
statically. In your forms, replace `csrfInput()` with:

```twig
{{ craft.cachekit.csrfInput() }}
```

The bundled JavaScript hydrator fetches the token with `no-store` and fills in
the placeholders as soon as the DOM is ready.

If your own JavaScript would rather read the token from a meta tag, there is
also:

```twig
{{ craft.cachekit.csrfMeta() }}
```

Only put that on pages where such JavaScript actually runs. In a global layout
it costs an extra request to Craft on every single page, including pages
without a form, which is exactly what static caching was meant to avoid.

## Emergency mode

When a critical PHP or Craft vulnerability is announced, you may want PHP off
entirely while the public site stays up. Emergency mode does that: Apache
serves a complete static copy of the site (the *snapshot*) and never calls PHP,
for as long as a flag file exists.

The live cache is not good enough for this. It only holds pages that were
visited or warmed, an entry save thins it out, and every miss (a query string,
a logged-in cookie, an excluded URI) falls through to `index.php`. The snapshot
is built in one go, swapped in atomically, and ignores query strings and
cookies.

### Setup

```dotenv
# Under the web root, "cachekit" in the folder name, not the cache path.
CACHEKIT_SNAPSHOT_PATH=/home/site/public_html/cache/cachekit-snapshot
# Outside the web root, so nobody can create or remove it over HTTP.
CACHEKIT_EMERGENCY_FLAG=/home/site/cachekit-emergency
```

Build the snapshot and generate the Apache rules from your settings:

```bash
php craft cachekit/snapshot/build --report
php craft cachekit/snapshot/server-config
```

The output has two parts: rewrite rules that go inside `<IfModule mod_rewrite.c>`
right after your redirects and before the regular CacheKit rules, and an `<If>`
block (404 page and headers) that goes outside it. Paths are relative to
`%{DOCUMENT_ROOT}`, so the same block works locally and in production as long
as the folders sit in the same place relative to each other. Only Apache is
supported for now.

Keep the snapshot fresh with a nightly cron. It is silent on success and exits
non-zero on failure:

```cron
30 4 * * * php craft cachekit/snapshot/build
```

### What goes into the snapshot

- Every live entry whose URI the live cache would accept, plus
  `snapshotIncludeUriPatterns`: URIs that are excluded from the live cache but
  fine as static pages in an emergency, such as a contact page.
- `snapshotExtraUris` (default `robots.txt`, `sitemap.xml`). Non-HTML files are
  stored under their own name, so Apache sends the right content type.
- With `snapshotFollowSitemaps`, sub-sitemaps and every same-host page in the
  sitemaps, as far as the URI patterns allow. This picks up routes that have
  no entry.
- The site's 404 page, rendered once and served through `ErrorDocument` with a
  real 404 status.

A run in which more than 20% of the pages fail is discarded and the previous
snapshot stays in place. `--report` lists failed and excluded URLs.

Templates can tell they are rendered for the snapshot with
`craft.cachekit.isSnapshot()`, for example to show "the form is temporarily
unavailable" instead of a form. Snapshot pages carry
`<meta name="cachekit-snapshot">`; the CSRF hydrator then skips the token
request, marks forms with `data-cachekit-offline` and dispatches a
`cachekit:offline` event.

### Turning it on

1. If PHP can still run safely, build a fresh snapshot first:
   `php craft cachekit/snapshot/build`.
2. `touch /home/site/cachekit-emergency`. This works over SSH or from a
   hosting panel's file manager; it needs no PHP.
3. Disable the `php craft` cron jobs.
4. Check: `curl -I https://example.com/` shows `X-CacheKit: EMERGENCY`, and
   `curl -I https://example.com/index.php` returns 403.

To turn it off, remove the flag file, re-enable the cron jobs and run
`php craft cachekit/cache/clear '*'`.

While it is on, every `.php` file returns 403. Craft's CP and action triggers
plus `emergencyBlockedPrefixes` (default `api`) return 503 with `Retry-After`.
Real static files are served as usual, and anything else returns the
snapshot's 404. Forms, search, the control panel, queue and cron are down by
design.
