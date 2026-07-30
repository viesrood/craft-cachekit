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
