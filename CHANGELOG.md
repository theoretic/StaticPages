# Changelog

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
