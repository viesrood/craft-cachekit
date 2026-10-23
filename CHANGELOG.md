# Changelog

## 1.1.1 - 2026-10-23

- The snapshot path error names the web root it checked against and points to
  `CRAFT_WEB_ROOT`, which the console needs on hosts that do not set it.

## 1.1.0 - 2026-10-23

- Emergency mode: `cachekit/snapshot/build` writes a complete static copy of
  the site (entries, extra URIs, sitemaps, the 404 page) and swaps it in
  atomically, and `cachekit/snapshot/server-config` generates the Apache rules
  that serve it without PHP while a flag file exists.
- `craft.cachekit.isSnapshot()` for templates, and the CSRF hydrator skips the
  token request on snapshot pages.
- `cachekit/cache/diagnose` and the utility show the snapshot's age and whether
  emergency mode is active; the utility can queue a new build.

## 1.0.0 - 2026-07-30

- First release. Static page caching for Craft CMS 5: pages are written to disk
  as HTML so Apache or Nginx can serve them without booting Craft.
- Explicit section and URI rules decide what gets cached and what gets
  invalidated on an entry save, so there is no database index of template
  queries to keep in sync.
- Cache utility in the control panel and a console controller
  (`cachekit/cache/clear`, `refresh`, `warm`, `diagnose`) that both take exact
  URIs and wildcards.
- Queue-driven warming after a save, so a cleared page is rebuilt before the
  first visitor arrives.
- `craft.cachekit.csrfInput()` and `craft.cachekit.csrfMeta()` with a
  JavaScript hydrator, so forms keep working on a statically cached page.
