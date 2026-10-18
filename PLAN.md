# StaticPages: audit, actualisation, Bridge

Written 2026-10-18 from a review of 0.7.1 (`02182d7`) and every site copy. Worked through the
same day in 0.8.0; each task below says what it became. Line numbers refer to the 0.7.1 file.

## Status and copies

- Canonical: this repo, 0.8.0. Junctioned into atispro, era-remont, livingston, mantra-mama,
  mantra-papa, onmed, opus-design, republic-remont, testfly, voxhall-design.
- Plain copies, each an exact snapshot of a commit here; nothing to harvest:
  paraavis 0.6.3 (`6950ae4`), elbrusfly 0.6.1 (`9422e58`), peterbak, steelbak and
  tandem-chegem 0.6.0 (`3648bb9`), odnaco 0.3.1 (`7f69b71`).
- Older lineage, not in history: txm "020" (4 lines off `a6eef9d`, "021"), and davinci "020"
  (txm plus `documentRootOk()`). Both are covered by `rootPath()` and the CLI guards here.
- atisprod "012" (2023-12-15) has no DOCUMENT_ROOT stop in its upward rmdir loop and can
  remove empty directories above the webroot. Replace it first.
- **Done:** 0.8.0 migrates every one of these on its first admin request, CLI run or save,
  without a modules refresh (see `StaticPagesMigration`, README *Upgrading*). Checked on the
  atispro (0.6.2) and davinci (020) sandboxes.

## Audit

Safety:
- Full-scan wipe deletes every `index.html` and every empty directory under any root directory
  not listed in `nonStaticDirs`. **Done** (`5a4a262`): the protected set is computed at wipe
  time, a scan walks only root dirs that are static copies of first level pages, and logs the
  rest; every delete needs `realpath()` below `rootPath()`; dry run added (`a122685`).
- `shell_exec` and `proc_open powershell` build commands from config paths. **Done** (`927fbc0`,
  `5a4a262`): every argument was already escaped; the static file name, which reaches `find
  -name` and `-like` as a pattern, must now be a plain file name; the find scan falls back to
  the PHP walk when no shell runs. The PHP walk stays the default (*Use shell commands* off).
- `:284`: only GET and POST parameters block caching. **Done** (`bc9bc77`): *Never cache with
  these cookies*, and responses setting a non-session cookie or sending `Vary` (other than
  `Accept-Encoding`) are skipped. `Accept-Language` dependence without a `Vary` can not be
  detected: documented.
- `stripCsrf` (`:369`) assumes the `@atispro/core 00-csrf.js` markup. **Done** (`0696ab9`):
  *CSRF token tags* setting, that markup as default, same output byte for byte.
- Not in the audit, found on the way and fixed (`0f9c6db`): the force wipe box was stored checked
  on every install, so any later config save ran a full scan; a single config key stored by the
  module could have started it from a front end POST.

Performance:
- `ensureTables()` per request. **Done** (`6f5f8d7`): outcome kept as `indexSchema` in the module
  config; install checks again, a missing table (42S02) forces a new check. Table comments stay
  the authority for the layout. Verified: a warm request runs no `CREATE TABLE` and no
  `information_schema` (smoke test, query log).
- `hookFound` and `getByPath`. **Done** (`81441c0`): selectors are kept and resolved only when a
  file is recorded, each path once per request, at most 2000 finds per render.
- `checkTrackedPaths()` every 10 s on admin requests. Kept; documented in the README.

Bugs:
- `:2123` float string offset. **Done** (`87c2cfe`).
- `:442` stray `echo` inside `ob_start()`. **Done** (`d7578fd`).
- `:820`, `:1590`: template compared by name. **Done** for `:820` (`4674aa6`: `Language` class
  and the languages template). `:1590` kept on purpose: `$config->adminTemplates` is a list of
  template names, and the fallback only runs on cores before 3.0.142.
- `wire()` globals. **Done** (`12ac567`), config file included.
- Not in the audit (`0f9c6db`): an unset priority (012, 020 configs) hooked the cache at 0.

## Actualisation

- Rename to `StaticPages.module.php`. **Done** (`c18df35`). Core loads the new name without a
  refresh (checked on both sandboxes), but skips `___upgrade()`: nothing depends on it.
- Split into classes. **Done** (`ad2df67`): `StaticPagesIndex`, `StaticPagesQueue`,
  `StaticPagesPublisher`, `StaticPagesWiper`, `StaticPagesTracker`, later
  `StaticPagesMigration`; required by lazy accessors (also usable before `init()`, a disabled
  module loads none). Behaviour-preserving: identical results against the monolith.
- `array()` style (`f4c03c9`), `$this->wire()->x` (`12ac567`), `.gitattributes` with `eol=lf`,
  `.editorconfig` (`5d69681`). **Done.**
- `CHANGELOG.md`, `CLAUDE.md`, `tests/smoke.php` (publish; save removes only dependants; wipe of
  a temporary root with an unlisted dir that survives; dry run; migration). **Done.**
- Drop the dead commented block in `StaticPages.info.php`. **Done.**
- README on headers (CSP, nosniff belong in `.htaccess`/nginx). **Done.**

## Bridge

Config screen only, core Inputfields. Native by construction; the new settings (*Dry run*,
*Never cache with these cookies*, *CSRF token tags*) are core checkbox and textarea fields. The
0.6.4 `$modules->refreshing` guard in the config file is kept.

## Verification

- `tests/smoke.php` against the atispro and davinci sandboxes: all checks pass.
- A wipe dry-run on the atispro sandbox (a copy of the real site) lists only `/index.html` and
  leaves `app/` alone, where 0.7.1 would have walked `app/` and removed its 57 empty dirs.
- Query log of a warm request: no `CREATE TABLE`, no `information_schema` (smoke test).

## Left

- Switching the plain copies and the 012/020 sites to the junction is a deployment step, not
  done here. Production servers get a plain copy and should run one dry run first.
