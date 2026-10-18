# StaticPages: audit, actualisation, Bridge

Written 2026-10-18 from a review of 0.7.1 (`02182d7`) and every site copy. Nothing here is
fixed yet; each item is a task.

## Status and copies

- Canonical: this repo, 0.7.1. Junctioned into atispro, era-remont, livingston, mantra-mama,
  mantra-papa, onmed, opus-design, republic-remont, testfly, voxhall-design.
- Plain copies, each an exact snapshot of a commit here; nothing to harvest:
  paraavis 0.6.3 (`6950ae4`), elbrusfly 0.6.1 (`9422e58`), peterbak, steelbak and
  tandem-chegem 0.6.0 (`3648bb9`), odnaco 0.3.1 (`7f69b71`).
- Older lineage, not in history: txm "020", and davinci "020" (txm plus `documentRootOk()`).
  Both are covered by `rootPath()` and the CLI guards here.
- atisprod "012" (2023-12-15) has no DOCUMENT_ROOT stop in its upward rmdir loop and can
  remove empty directories above the webroot. Replace it first.

## Audit

Safety:
- Full-scan wipe (`StaticPages.module:2018`, `:2058` in PHP, `:2078` Unix `find ... -delete`)
  deletes every `index.html` and every empty directory under any root directory not listed in
  `nonStaticDirs`. That list is a snapshot from the last config save, so a directory added
  later (`vendor/`, a hand-made landing page) is unprotected. Compute the protected set at
  wipe time, add a dry-run mode that logs what would go, and refuse any path outside
  `rootPath()` after `realpath()`.
- `shell_exec` and `proc_open powershell` (`:2073-2104`) build commands from config paths.
  Check every argument goes through `escapeshellarg`, and keep the PHP fallback as the
  default where `shell_exec` is disabled.
- `:284`: only GET and POST parameters block caching. Guest output that depends on a cookie or
  `Accept-Language` is frozen for everyone. Add a "never cache with cookie X" list and skip
  responses that set cookies (`headers_list()`).
- `stripCsrf` (`:369`) assumes the `@atispro/core 00-csrf.js` markup. Make the pattern a
  config value with that as default.

Performance:
- `ensureTables()` (`:881-961`) runs three `CREATE TABLE IF NOT EXISTS` and an
  `information_schema` query on the first cache write or save of every request; the "ready"
  flag lives only for the request. Keep the schema version in module config and check it on
  install and upgrade only.
- `hookFound` (`:163-184`) runs on every guest `find()`, and `getByPath` (`:712`) adds a
  query per path value in a selector. Batch, or record dependencies only while a render for
  the cache is running.
- `checkTrackedPaths()` in `ready()` walks tracked files at most every 10 s on admin requests.
  Acceptable; document it.

Bugs:
- `:2123`: `@$sz[$factor]` uses a float string offset; the `@` hides the deprecation.
- `:442`: stray `echo` inside an `ob_start()` buffer.
- `:820`, `:1590`: template compared by name (`'language'`). Use the object or id.
- `wire()` globals at `:250`, `:284`, `:287`, `:290`, and in the config file at `:13`, `:23`.

## Actualisation

- Rename to `StaticPages.module.php`.
- Split the 2126-line file into classes loaded in `init()`: index (tables, dependencies),
  queue and drain, publish (atomic write), wipe (scan, shell, PHP), tracking.
- `array()` style, `$this->wire()->x`, `.gitattributes` with `eol=lf`, `.editorconfig`.
- `CHANGELOG.md` (rebuild from the git log, starting 0.7.1), `CLAUDE.md`, and
  `tests/smoke.php`: publish a page; save it and check only its dependants go; wipe a
  temporary root that holds an unlisted directory, which must survive.
- Drop the dead commented permission and page block in `StaticPages.info.php`.
- README: headers such as CSP and nosniff cannot come from PHP for cached pages; they belong
  in `.htaccess` or nginx. This is why the archived `Headers` module never worked with caching.

## Bridge

Config screen only, core Inputfields. Native by construction. Bridge PLAN.md already records
the 0.6.3 config-time `$modules->get()` loop, fixed in 0.6.4 by checking `$modules->refreshing`.

## Verification

- `tests/smoke.php` against an atispro sandbox.
- A wipe dry-run on a copy of a real site lists only page `index.html` files.
- Query log of one guest request with a warm cache has no `CREATE TABLE` and no
  `information_schema`.
