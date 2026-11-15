# StaticPages

ProcessWire 3 autoload module: writes the rendered output of guest page views to static
`index.html` files under the site root, and deletes them again when what they show changes.
It writes and deletes files on disk and can run shell commands: correctness and safety come
before elegance.

## Layout

| File | Role |
|---|---|
| `StaticPages.module.php` | Module class: hooks (stay on this object, site code finds them by `toObject`), public API (`cachePage()`, `noCache()`, the hookable `___allowCache()` and `___fileContent()`, `stripCsrf()`, `wipeCaches()`, `drain()`, `checkTrackedPaths()`, `defaultNonStaticDirs()`), config helpers shared by the parts, part accessors |
| `StaticPagesIndex.php` | Dependency index: the three tables, render dependencies (`pageLoaded()`, `pagesFound()`, `renderTags()`), invalidation (`invalidatePage()`), schema check (`ensureTables()`, `indexSchema`), DB locks |
| `StaticPagesQueue.php` | Queue rows, the drain after the response, the generation stamp |
| `StaticPagesPublisher.php` | Atomic write, CSRF stripping, cookie and header skips |
| `StaticPagesWiper.php` | Deleting: by index, by manifest, by scan (PHP, `find`, PowerShell), dry run, protected dirs, `insideRoot()` |
| `StaticPagesTracker.php` | Tracked path hashes |
| `StaticPagesMigration.php` | Lazy migration of older installs (`configVersion`) and adoption of unrecorded files |
| `StaticPages.config.php` | Config array. Included whenever core builds module info (refresh, install, cleared info cache): never touch the module from here (0.6.3 and 0.8.0 looped until memory ran out); defaults that need the module are filled in by `hookConfigInputfields()` |
| `StaticPages.info.php` | Module info, semver string |
| `tests/smoke.php` | CLI smoke test against a Bridge sandbox |

Parts extend `Wire`, get the module as `$this->sp`, are `require_once`d by their accessor.
Part methods are public so the smoke test can call them. No build step, no composer.

## Commands

```bash
D:/work/sites/modules/PHP-8.5/php -l StaticPages.module.php   # and every other .php
STATICPAGES_SMOKE_TMP=<a temp dir outside D:/work/sites> \
  D:/work/sites/modules/PHP-8.5/php tests/smoke.php D:/work/projects/processwire/bridge/sandbox/atispro
```

The smoke test tests whichever copy the install loads: swap this repo's files into the
sandbox's `app/modules/StaticPages` first (back up the original copy, restore it after). It
points the site root, cache and log dirs at its temp dir; it refuses webroots and temp dirs
under `D:/work/sites` or `Z:/home`. A CLI boot needs `$_SERVER['DOCUMENT_ROOT']` (site configs
read it). PW core: `D:/install/processwire/wire` (3.0.274); read it instead of guessing.

## Conventions

- `namespace ProcessWire;`, tabs, LF, `array()`, `$this->wire()->x`, `$this->_()`.
- Semver in `StaticPages.info.php` and `CHANGELOG.md`. Commits: Conventional Commits, English.
- Every new behaviour gets a `check()` in `tests/smoke.php`.

## Compatibility rules

- The repo is junctioned into ~20 sites replacing copies of 0.3.1 to 0.7.1 and the 012/020
  lineages. Config keys, table names and layout (`StaticPages schema N` table comments), cache
  files (`site/assets/cache/StaticPages.manifest`, `site/assets/cache/StaticPages/`) and static
  files on disk must keep working. A config change gets a step in
  `StaticPagesMigration::configChanges()` and a raise of `StaticPages::configVersion`; a table
  change a step in `migrateSchema()` and a raise of `schemaVersion`.
- Nothing may depend on `___upgrade()`: core skips it when the module file is renamed, and
  sites may never see a modules refresh. Migrations run lazily (`shutdownDrain()`, `drain()`).
- Site code uses: properties `nonStaticTemplates`, `nonStaticDirs`, `newDirPermissions`,
  `priority`, `isEnabled`; `stripCsrf($html)` (null on failure); `wipeCaches()`,
  `checkTrackedPaths(true)`, `drain()`; the `Page::render` hook found by `toObject instanceof
  StaticPages` and its priority (atispro `georestrictions.static.php`, kit `seoneo.inject.php`).
- Every delete goes through the static file name check, the protected dir check and
  `insideRoot()`. A scan walks only `scanTargets()`.
