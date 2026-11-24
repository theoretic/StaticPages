# StaticPages

ProcessWire 3 autoload module: writes the rendered output of guest page views to static
`index.html` files under the site root, or below `staticBaseDir` (`index.xml`, `index.txt` and
listed root files such as `robots.txt` while `cacheNonHtml` is on), and deletes them again when
what they show changes.
It writes and deletes files on disk and can run shell commands: correctness and safety come
before elegance.

## Layout

| File | Role |
|---|---|
| `StaticPages.module.php` | Module class: hooks (stay on this object, site code finds them by `toObject`), public API (`cachePage()`, `noCache()`, the hookable `___allowCache()` and `___fileContent()`, `stripCsrf()`, `wipeCaches()`, `drain()`, `checkTrackedPaths()`, `defaultNonStaticDirs()`, `htaccessStatus()`, `writeHtaccess()`, `removeHtaccess()`, `expireAt()`, `expire()`), the `Pages::added`, `Fieldgroups::save`, `Templates::added` and `Templates::saved` hooks (the last three queue `modeAll`), expiry (`maxAgeFor()`, `expiryTag()`), `cacheHosts` (`cacheHostsList()`, `cacheHostSkip()`), the `any` counts on the form, the uninstall flag (`markUninstalling()`, `uninstalling()`), the name sets (`staticFileName($type)`, `knownFileNames()`, `scanFileNames()`, `outputTypes`), the root file list (`rootFilesArray()`), the switch-change wipe and the root file actions of a settings save, `rootPath()` (the site root: tracked paths, the default *Non-static directories*, the migration, the `.htaccess`) and `staticRoot()` (where copies live, what index paths are relative to: the site root, or the base directory), the base directory and the host key (`staticBaseDir()`, `checkBaseDir()`, `baseDirSyntax()`, `stockDenied`, `stockDeniedPath()`, `baseDirReady()`, `hostKeyOn()`, `staticHost()`), the no-slash skip (`noSlashSkip()`), config helpers shared by the parts, part accessors |
| `StaticPagesIndex.php` | Dependency index: the three tables, render dependencies (`pageLoaded()`, `pagesFound()`, `renderTags()`), the record side of a find (`selectorTags()`: `scopeTags()` with `narrowFinds` off, `narrowTags()` on, the fieldgroup-derived scope `derivedScopes()`), invalidation (`invalidatePage()`, `listingTags()`, `changeTags()`, `foreignTags()`, `whatTag()`), `fileCounts()`, `dueExpiryTags()`, schema check (`ensureTables()`, `indexSchema`), DB locks |
| `StaticPagesQueue.php` | Queue rows, the drain after the response, the generation stamp, the expiry sweep (`expire()`, `shutdownExpire()`, `expire.next`) |
| `StaticPagesPublisher.php` | Atomic write, CSRF stripping, cookie and header skips, the type of a render (`renderType()`, `typeOfMime()`) |
| `StaticPagesWiper.php` | Deleting: by index, by manifest, by scan (PHP, `find`, PowerShell), dry run, protected dirs, `insideRoot()`; root files: `takeOverRootFile()`, `giveBackRootFile()`, `deleteRootFileCopy()`, `recordedRootFiles()`; the forced scan's record pass (`recordedFiles()`, `manifestLines()`); an old copy of a no-slash page, `<x><name>` (`noSlashCopyDir()`); the default list's record lookup (`isRecordedCopyDir()`) |
| `StaticPagesTracker.php` | Tracked path hashes; static names and the marked base directory are no source files (`staticDirPrefix()`); the front-end check (`checkFrontend()`, `front.stamp`, `trackingIntervalFront`) |
| `StaticPagesMigration.php` | Lazy migration of older installs (`configVersion`) and adoption of unrecorded files |
| `StaticPagesHtaccess.php` | The root `.htaccess`, four layers: the generator (`rules()`, `apacheBlock()`: settings and `$config` in, text out, no disk, no database; typed and root file rules only while `cacheNonHtml` is on; with a base directory `baseRules()`, once per host of `$config->httpHosts` with the host key; the host condition of `cacheHosts()`), the reader (`status()`, `classify()`: the states and the heuristic for hand-written rules, writes nothing), the writer (`write()`, `remove()`: one block, the Replace prefix, backups, read-back, the check request), `rootRoute()` (whether the file routes a root file to its page), the manager (`check()` after a superuser admin request or a CLI run, `onInstall()`, `onSave()`, `onUninstall()`, the kept notices) |
| `StaticPages.config.php` | Config array. Included whenever core builds module info (refresh, install, cleared info cache): never touch the module from here (0.6.3 and 0.8.0 looped until memory ran out); defaults that need the module are filled in by `hookConfigInputfields()` |
| `StaticPages.info.php` | Module info, semver string |
| `tests/smoke.php` | CLI smoke test against a Bridge sandbox |
| `tests/fixtures/` | The stock `htaccess.txt` of 3.0.274 and anonymised hand-written rule sets: no site's host, path or name |

Parts extend `Wire`, get the module as `$this->sp`, are `require_once`d by their accessor.
Part methods are public so the smoke test can call them. No build step, no composer.

## Commands

```bash
D:/work/sites/modules/PHP-8.5/php -l StaticPages.module.php   # and every other .php
STATICPAGES_SMOKE_TMP=<a temp dir outside D:/work/sites> \
  D:/work/sites/modules/PHP-8.5/php tests/smoke.php D:/work/projects/processwire/modules/StaticPages-sandbox/atispro
```

The sandbox is `StaticPages-sandbox/atispro` (DB `atispro_bridge_sp`). The smoke test tests
whichever copy the install loads: swap this repo's files into the sandbox's
`app/modules/StaticPages` first (back up the original copy, restore it after). A CLI boot with
them swapped in writes `htaccess-*.seen` and `htaccess.reported` into its cache dir's
`StaticPages/`, a log line, the block into a stock root `.htaccess` with a backup
`.htaccess.sp-<YmdHis>.bak` beside it, and `htaccessNotice` into the module's config row. It
points the site root, cache and log dirs at its temp dir; it refuses webroots and temp dirs
under `D:/work/sites` or `Z:/home`. A CLI boot needs `$_SERVER['DOCUMENT_ROOT']` (site configs
read it). PW core: `D:/install/processwire/wire` (3.0.274); read it instead of guessing.

## Conventions

- `namespace ProcessWire;`, tabs, LF, `array()`, `$this->wire()->x`, `$this->_()`.
- Semver in `StaticPages.info.php` and `CHANGELOG.md`. Commits: Conventional Commits, English.
- Every new behaviour gets a `check()` in `tests/smoke.php`.

## Compatibility rules

- This code replaces copies of 0.3.1 to 0.9.1 and of the 012/020 lineages on about twenty
  sites. Today one local site links its module dir to this repo and the others use a copy of
  0.9.1; that may change, and every change has to keep working on all of them. Config keys,
  table names and layout (`StaticPages schema N` table comments), cache files
  (`site/assets/cache/StaticPages.manifest`, `site/assets/cache/StaticPages/` with
  `expire.next`, the earliest expiry time, deleted on uninstall) and static files on disk must
  keep working. A config change gets a step in
  `StaticPagesMigration::configChanges()` and a raise of `StaticPages::configVersion`; a table
  change a step in `migrateSchema()` and a raise of `schemaVersion`.
- Nothing may depend on `___upgrade()`: core skips it when the module file is renamed, and
  sites may never see a modules refresh. Migrations run lazily (`shutdownDrain()`, `drain()`).
- Site code uses: properties `nonStaticTemplates`, `nonStaticDirs`, `newDirPermissions`,
  `priority`, `isEnabled`; `stripCsrf($html)` (null on failure); `wipeCaches()`,
  `checkTrackedPaths(true)`, `drain()`; the `Page::render` hook found by `toObject instanceof
  StaticPages` and its priority (atispro `georestrictions.static.php`, kit `seoneo.inject.php`).
- Tags are on-disk format (`static_pages_tags`, `VARCHAR(40)`): the legacy `p:`, `t:`, `par:`,
  `tree:`, `any`, `*` keep their meaning and every save still queues them. Composite tags
  `<scope>/<what>` (scope `t:`, `par:`, `tree:`, `*`; what `f:<field id>`, `n:<name>`, `s`, `m`)
  and `x:<what>` are queued by every save, recorded only with `narrowFinds` on; `whatTag()` names
  both sides, `n:` names at most 22 characters so `tree:4294967295/n:<22>` fits 40. `e:<10 digit
  unix time>` is an expiry time, never queued by a save, taken by `expire()` only.
- Every delete goes through the static file name check, the protected dir check and
  `insideRoot()`; with a base directory the marker check instead of the protected dir check, which
  does not apply below it. A scan walks only `scanTargets()`.
- Two name sets: `knownFileNames()` (`index.html`, `index.xml`, `index.txt`: *Static file name*
  with its extension replaced, whatever `cacheNonHtml` says) is what a delete by record accepts;
  `scanFileNames()` (the HTML name, the typed ones only while `cacheNonHtml` is on) is what a scan
  matches, a static copy dir is recognised by and tracking ignores. With `cacheNonHtml` off every
  scan, default list and the block are 0.10.0's. The root level of a scan matches the HTML name
  only. A root file (`rootFiles`) is deleted by its index record only, directly in the static root
  (one host dir down with the host key), while listed and the switch is on.
- A manifest line never names a non-HTML file: non-HTML is never written without index tables,
  a manifest wipe and import take the HTML name only.
- The dot backup of a taken-over root file (`robots.txt` kept as `.robots.txt`) is on-disk
  format: never overwritten, renamed back only on give-back (name off the list, switch off,
  uninstall), never deleted, never touched by a wipe. With a base directory nothing renames one
  (uninstall too); only the save that switches to a base directory gives the web root's back.
- The marker file `.staticpages` in the base directory (`StaticPages::baseDirMarker`) is on-disk
  format: nothing is written into a base directory, or deleted from it, while it lacks the marker;
  only a settings save (an empty dir) or the creation of a missing dir writes it; nothing deletes
  it, uninstall and a change of `staticBaseDir` leave dir and marker. Tracked paths skip a marked
  base directory.
- A page whose template has `slashUrls` 0 (never the home page), every page below one, and a URL
  segment render with `slashUrlSegments` -1 are not cached (`noSlashSkip()`): a copy dir at such a
  URL loops between mod_dir's 301 and ProcessWire's. Root file copies are still written. An old
  copy `<x><name>` is deleted by record only, by path shape, with its empty dir `<x>/`.
- The root `.htaccess` is changed only between `# BEGIN StaticPages` and `# END StaticPages`
  (one block, immediately before `# PW-PAGENAME`). On an operator's Replace, hand-written lines
  get the prefix `# StaticPages-replaced: `, and a stock `DirectoryIndex` line is added after a
  prefixed one. Nothing else is touched and no line is ever deleted; `remove()` gives the file
  back byte for byte. Never from a front-end request; always after a backup. The markers and the
  prefix are on-disk format: keep them.
- Bookkeeping keys, absent by default and no field of the form: `indexSchema`, `htaccessNotice`
  and `uninstalling` are saved past the config hooks (`$modules->configs->saveConfig()`),
  `htaccessKeep` by the settings save that chose Keep. `uninstalling` goes with the module's row;
  it counts for `StaticPages::uninstallingMaxAge` seconds only.
