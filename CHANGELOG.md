# Changelog

## 0.10.0 (2026-11-17)

The module looks after the web server rules that serve its static files itself: on Apache it
keeps one block of rules in the site's root `.htaccess`. Until now the README asked for rules
written by hand.

### What happens on an existing site
After the update, the module reads the root `.htaccess` once. That happens after the response of
the first admin request of a **superuser**, or in the first CLI script run, whichever comes first.
A visitor's or an editor's request never reads or writes the file. What happens depends on the
state the file is in:

- **Stock ProcessWire rules, nothing served by hand (`absent`)**: the StaticPages block is added
  immediately before the `# PW-PAGENAME` line, after a backup `.htaccess.sp-<YmdHis>.bak` next to
  it. One line goes to the `static-pages` log and a notice to the next superuser admin page. From
  then on the static copies are served. A web root under git shows the change of `.htaccess`.
- **Rules written by hand for the static files (`candidate`)**, as most sites have them: nothing
  is written. One log line, and a warning on the next superuser admin page, naming the lines and
  the settings page. There, *Hand-written rules found* offers *Keep them* (nothing is written, no
  more warnings until the rules change) or *Replace them with the StaticPages block*: after a
  backup, the hand-written lines are commented out with the prefix `# StaticPages-replaced: `,
  nothing is deleted, and the block is written.
- **No `.htaccess` (nginx, rules in the virtual host), a file that cannot be written, broken
  block markers, or a file that is no ProcessWire `.htaccess`**: nothing is written; one log line,
  and the state on the settings form.
- **A site that chooses the language by host name** must add its own rules before updating
  (README, *Multilingual sites*) and choose *Keep them*. Without them its file is `absent`, and the
  block would serve the second host the other language's copies.

At the time of this release one site loads the module straight from this repository. The others
run a copy of 0.9.1 and meet all this when that copy is updated. This may change.

### Added
- **The StaticPages block** in the root `.htaccess`, made from the settings. It serves a page's
  copy to a guest's `GET` or `HEAD` without a query string. A `POST`, a query string and the
  login cookie go to ProcessWire (the README's old rules served the copy to a `POST` and to a logged-in
  visitor). A
  directory without a copy goes to ProcessWire instead of 403, and a directory with an `index.php`
  of its own (an `/api/` endpoint) keeps the stock answers. Proven on Apache 2.4 at the web root
  and in a subdirectory.
- **The check**: after a superuser's admin request and a CLI run, three `stat()` calls while
  nothing changed. A missing block is added, and an outdated one (a module update, a changed
  *Static file name*, a hand edit inside it) is written again. Hand-written StaticPages-like rules
  are reported and never touched without the operator's choice. Every write keeps a backup (the
  five newest), is read back, and from a web request is followed by one request for the home
  page; a 5xx puts the backup back. A notice from the check waits in the module config
  (`htaccessNotice`) until a superuser's next admin page. The *Keep* choice is stored as a hash of
  the rules kept (`htaccessKeep`).
- **The settings form** shows the state (*Root .htaccess*), the hand-written rules found with
  their line numbers, the block (*The StaticPages block*) and the one-shot choice *Hand-written
  rules found*. *Dry run* also keeps a save from writing the `.htaccess`, and logs the new text.
- **Deploy scripts**: `htaccessStatus()`, `writeHtaccess($dryRun = false, $replace = false)`,
  `removeHtaccess($dryRun = false)`.
- **README**: *Server configuration* rewritten around the block. It now covers the states, what
  counts as a hand-written rule, Replace and Keep, the backups, web roots under git, and an nginx
  example. *Multilingual sites* has a hand-written example for languages chosen by host name.

### Changed
- **Uninstalling** first marks the module as being uninstalled (`uninstalling` in its config,
  gone with its row): from then on no request writes a static copy. It then takes the block and
  the `# StaticPages-replaced: ` prefixes out of the `.htaccess` after a backup, which gives
  hand-written rules back byte for byte. Next it deletes the static copies the index (or the
  manifest) records, and last it drops the tables. Copies nothing recorded stay. An uninstall
  that throws takes the mark back; one that dies halfway stops caching for an hour at most, and
  the next admin request after that removes the mark.

### Tests
- `tests/smoke.php` checks the generator, the reader and the heuristic on the stock file and on
  anonymised hand-written rule sets, and every state. It covers Replace, Keep and the refusals, the
  backups and the restore, the check after admin, editor, visitor and CLI requests, the kept
  notices, and an uninstall from an admin request and from a CLI run: no block written back, no
  copy and no unrecorded file left, nothing cached while it runs.

## 0.9.1 (2026-11-16)

### Fixed
- **A save after `drain()` in the same request queued nothing.** The queue remembered for the
  whole PHP process what it had queued and that it had bumped the generation stamp, so a bulk
  save loop inserts each tag once. A deploy script or CLI tool that queued all files (or some
  tags), called `$modules->get('StaticPages')->drain()` and then saved pages queued nothing for
  those saves and left their static files stale. `drain()` forgets that memory: the next save
  queues its row and changes the generation stamp again, so a render that read the database
  before that save does not publish. Saves with no drain between them still queue each tag once.

### Tests
- `tests/smoke.php` saves a page after `drain()` in a request that queued all files, and saves it
  again after the next `drain()`: each save queues its row, changes the generation stamp and
  loses its file to the drain. The checks after it no longer reset the queue by hand.

## 0.9.0 (2026-11-15)

Additions asked for in [issue #1](https://github.com/theoretic/StaticPages/issues/1). Nothing
changes for a site that uses none of them: no config key, table or file changes meaning.

### Added
- **`noCache()` and the `allowCache` hook.** A render depending on something no URL shows
  (session state, a URL segment, a request flag) can be kept out of the cache from template code,
  `$modules->get('StaticPages')->noCache('cart in session')` (`noCache(false)` clears it), or by a
  hook on `StaticPages::allowCache(Page $page)` returning false. Both are asked only about a render
  every built-in check of the request accepted, before anything is created on disk: site code can
  veto, never force a file. Each refusal is logged with *Log skipped pages* on.
- **The `fileContent` hook.** `StaticPages::fileContent(Page $page, $html)` gets the markup after
  *CSRF token tags* were stripped and returns what is written, so the stored copy can differ from
  what the visitor got: a CSP nonce swapped for a server side include, content neutralised for
  everyone, an incomplete render refused. The render's return value is never touched. A return
  that is not a non-empty string keeps the render out of the cache, with no file and no dir left.
- **Never invalidate on save of these templates** (fieldset *Wipe*): a save, deletion, trashing,
  restore, sort or move of a page of a listed template queues nothing. No file is deleted, the
  generation stamp stays (renders running meanwhile still write their files), no drain runs. A
  repeater item counts as a page of its owner's template. For orders, form entries, log pages:
  every such save used to delete every file whose render ran a find with no template or parent
  limit. A cached page that shows a page of a listed template stays stale, and so do the page's
  own copy and, after a move or a rename, the copies of its descendants; see *Templates whose
  saves invalidate nothing* in the README. A save that moves a page into a listed template from
  one that is not listed still invalidates once. Deleting a listed page whose template carries a
  repeater field, or a template change that drops a repeater field, still queues one row for
  ProcessWire's repeater container page: the generation stamp changes once and one drain runs. A
  template also in *Wipe everything on save of these templates* still wipes everything, and the
  settings save warns about it.

### Tests
- `tests/smoke.php` runs to its end on a sandbox with compiled modules: the module info and
  module install checks run under the install's own root and cache dir (under the temporary root
  core re-included a compiled module and died), the fixture pages and the module config are
  restored from a shutdown function when the script dies early, and the first line prints the
  version of the files under test.

## 0.8.2 (2026-11-15)

### Changes
- **The settings form is grouped in fieldsets:** *General* (Priority), *Static* (permissions for
  new dirs, static file name), *Non-static and exclusions* (non-static dirs and templates, tracked
  paths, no-cache cookies), *System* (CSRF token tags, shell commands, dry run, skip logging) and
  *Wipe* (module config saves, single field saves, wipe-all templates and fields). *Force static
  pages deletion on module save* and *Enable this module* stay ungrouped at the bottom. Config
  keys and stored values are unchanged; fieldset names start with `_` and are never stored.

## 0.8.1 (2026-10-20)

### Fixes
- **A module install ran out of memory.** Core includes `StaticPages.config.php` whenever it
  builds module info: a refresh, a module install, a cleared info cache. The config file asked
  for the module to compute the *Non-static directories* default, which builds the module's info
  again, endlessly. The 0.6.4 guard (`$modules->refreshing`) only covered a refresh, so
  installing any module on a site with 0.8.0 exhausted memory (found on a fresh copy of atis.pro:
  `Modules::install('FieldtypeSelector')` → `clearModuleInfoCache()` → … → `StaticPages.config.php`
  → `$modules->get('StaticPages')` → …, 1 GB). The config file no longer touches the module.
- **An empty *Non-static directories* protected nothing.** Without a stored value the config
  file's default reached the module as `''` (0.8.0: during a refresh), and `nonStaticDirsArray()`
  read that as an empty list, so a wipe in that request had no protected dirs. Empty now means
  the default list, as "never saved" always did (the field is required, so it is never saved
  empty).
- The settings form gets the default list from a hook on `Modules::getModuleConfigInputfields`,
  which runs only when the form is built.

## 0.8.0 (2026-10-18)

Drop-in for every earlier version, including the 012 and 020 copies that never were in this
repo: stored config, tables and static files on disk carry over, see *Upgrading* in the README.

### Safety
- A full disk scan only walks root dirs that are static copies of pages: named like a first
  level page (in any language) and holding nothing but static files. It used to walk every root
  dir missing from *Non-static directories*, a snapshot of the root from the last config save,
  and deleted each `index.html` and each empty dir in a dir added since (`vendor/`, a hand made
  landing page, a Bridge sandbox's `app/`). The dirs left alone are logged.
- Every delete (index, manifest, scan) needs `realpath()` to put the path strictly below the
  site root; empty dir pruning stops at the first dir that is not.
- *Static file name* must be a plain file name. It reaches `find -name` and PowerShell `-like`
  as a pattern: `*` would have deleted everything. Otherwise nothing is cached or wiped by name.
- `cachePage()` writes nothing below a listed dir (no wipe would delete it) and refuses `.` and
  `..` path segments.
- *Non-static directories*: leading and trailing slashes ignored, case-insensitive on Windows,
  a line holding blanks (`site vendor wire`) protects each word too.
- The `find` scan falls back to the PHP walk when `shell_exec` is disabled or no shell ran,
  instead of dying or reporting a scan that never happened.
- *Force static pages deletion on module save* is one shot: it was stored checked and every
  later save of the form ran a full scan.
- Single config keys the module stores itself never trigger the force wipe, whatever the request
  posted.

### Added
- Dry run: `wipeCaches($forceScan, $dryRun)` and a *Dry run* checkbox. Same paths and checks as
  the real wipe, nothing deleted, every file and dir listed in the `static-pages` log.
  `wipeCaches()` returns its counts.
- *Never cache with these cookies* (wildcards). Responses setting a cookie other than
  ProcessWire's session cookies, or sending `Vary` on anything but `Accept-Encoding`, are not
  cached either.
- *CSRF token tags*: the regexes `stripCsrf()` uses, the `@atispro/core` markup as default
  (byte for byte the 0.7.1 output). An invalid line stops caching and is named on save.
- Automatic migration of older installs, without `___upgrade()` (core skips it when the module
  file is renamed) and without a modules refresh: drops `rmDirPasses`, stores priority 1000 where
  none was stored, removes page dirs from *Non-static directories*, records static files on disk
  nothing knew about (012 and 020 kept no manifest) for the next save to remove.
- `tests/smoke.php`, `CLAUDE.md`, this changelog, `.editorconfig`.

### Fixed
- An unset priority (012, 020) hooked the cache at 0, ahead of every output transformer. It
  defaults to 1000.
- `wire()` globals replaced by `$this->wire()`, in the config file too.
- `readableFilesize()` used a float string offset hidden by `@`.
- `wipeCaches()` echoed its notice into an output buffer that took in any other output.
- A language page is told by its class and the languages template, not by the name `language`.
- `stripCsrf()` dropped a whole tag when PCRE failed inside it.

### Performance
- The index check (three `CREATE TABLE IF NOT EXISTS` and an `information_schema` query on the
  first write of every request) runs once; its outcome is kept in the module config
  (`indexSchema`). A missing table found later makes the next request check again.
- Find selectors are turned into dependency tags only when a file is recorded, a path once per
  request, instead of on every guest `find()`.

### Changed
- `StaticPages.module` is `StaticPages.module.php`, split into parts loaded on first use:
  `StaticPagesIndex`, `StaticPagesQueue`, `StaticPagesPublisher`, `StaticPagesWiper`,
  `StaticPagesTracker`, `StaticPagesMigration`. Hooks stay on the module object.
- `array()` style, LF pinned in `.gitattributes`. The dead permission block in the info file
  is gone.

## 0.7.1 (2026-10-17)
- A save removes only the static files whose render depended on the saved page: render
  dependencies recorded in `static_pages_files` / `static_pages_tags`, a queue
  (`static_pages_queue`) drained after the response, a generation stamp against renders racing
  a save. *Wipe everything on save of these templates / change of these fields*.

## 0.6.4 (2026-10-16)
- A modules refresh no longer runs out of memory: the config file skips `$modules->get()` on
  itself while `$modules->refreshing`.

## 0.6.3 (2026-10-14)
- The default *Non-static directories* leaves out page dirs, and a save warns about listed page
  dirs. Shell wipes protect root dirs only, as the PHP wipes do.

## 0.6.2 (2026-10-01)
- The config screen survives a missing or unreadable webroot.

## 0.6.1 (2026-09-09)
- Static files are published atomically (temporary name, `rename()`); every skip can be logged
  (*Log skipped pages*).

## 0.6.0 (2026-09-09)
- Tracked path changes, wipe on single field save, wipe on config save of listed modules.

## 0.3.1 (2026-08-26)
- CSRF tokens are emptied in cached markup (`stripCsrf()`).

## 0.3.0 (2026-08-13)
- Wipe guards repaired, manifest based deletion instead of a scan on every save.

## 021 (2026-07-28)
- Configuration reworked, cache paths from `$config->paths->root` instead of `DOCUMENT_ROOT`.
  The "020" copies on txm and davinci are this lineage (davinci adds a `DOCUMENT_ROOT` guard).

## 012 (2024-07-30)
- Initial version: static file per rendered page, every save wipes everything. atisprod runs a
  copy of this lineage.
