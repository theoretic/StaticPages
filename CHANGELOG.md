# Changelog

## 0.13.0 (2026-11-24)

A save can be matched to the finds it touches by the fields they filter and sort on, static
files can expire after a time, tracked paths can be checked on front-end requests, and caching
can be limited to listed hosts. Every new setting is off or empty by default.

### What happens on an existing site
With the new settings at their defaults:

- **Changed: creating a page always deletes the listings it may enter.** The module hooks
  `Pages::added` and takes a new page for a structural save. Core hands `Pages::saved` whatever
  the new page's change list held, possibly nothing, and an empty list queued nothing: a listing
  limited to the page's template or parent kept its old result until something else deleted it.
  Now every creation queues the page's listing tags (`t:`, `par:`, `tree:` and `any`), for an
  unpublished page as well, but for a page of a system template. A template listed in *Never
  invalidate on save of these templates* still queues nothing for its own pages.
- **Changed: saving a fieldgroup deletes every static file**, as a tracked file change does. The
  admin saves one when the fields of a template are added, removed or reordered, when a template
  is created or renamed, and when a field is added to templates from the field editor; so does
  `$fieldgroup->save()`. A new template (`Templates::added`) and a template given another
  fieldgroup (`Templates::saved`) wipe everything too. *Narrow finds by the fields they use*
  needs it (a field added to a template moves its pages into finds recorded for the templates
  carrying that field); it happens with the box checked or not.
- **Changed: each save queues more tags.** Besides the tags it queued before, every one of them
  still queued, a save queues `<scope>/<what>` and `x:<what>` tags (README *Narrow finds by the
  fields they use*). With the box unchecked no file carries such a tag, so the same files are
  deleted as before; the queue rows are longer.
- **Changed: the settings form shows *Indexed static files*:** how many files the index records,
  how many of them carry `any` and how many `*`. One query, on the settings form only, and only
  with the index tables. With *Log skipped pages* on, every file written whose render ran a find
  the module does not understand logs `any <path>: a find this module does not understand,
  selector "<selector>"`, once per selector; with *Narrow finds* off that is every find not
  limited to a template, a parent or an ancestor.
- **Changed: `drain()` also runs `expire()`**, which deletes the static files whose expiry time
  has come: none without *Maximum age* or `expireAt()`.
- **Unchanged**: the StaticPages block in the root `.htaccess`, byte for byte the one 0.10.0 to
  0.12.0 write: nothing is rewritten on the update, and a site that chose *Keep them* has nothing
  to add by hand. The tags recorded for a file, the names of the copies, the tables
  (`StaticPages schema 1`) and the config keys do not change; there is nothing to migrate. A
  request with nothing due to expire reads one small file, or finds none, and runs no query.

### Added
- **Narrow finds by the fields they use** (`narrowFinds`, off). Checked, a find is recorded by
  the fields and properties it filters and sorts on, behind its template, parent or ancestor
  limit, and a save deletes the file only when it changed one of them, or created, deleted,
  trashed, restored or moved a page, or changed its status or template. A find asking a field for
  a non-empty value (`featured=1`) is recorded for the templates carrying that field, for twelve
  core fieldtypes. A find the module does not understand counts as one without limits (`any`); a
  page the render showed still deletes the file on any save of it. Files recorded before the box
  changed keep their tags. The README has the tag grammar and a table of examples.
- **Maximum age** (`maxAge`, empty): one `<template name> <seconds>` per line, `*` for every
  template. A static file of a listed template is deleted once it is that old. Template code can
  ask for an earlier time with `$modules->get('StaticPages')->expireAt($time)`; a time not in the
  future keeps the render out of the cache (`expireAt() asked for a time not in the future`). The
  time is recorded as the tag `e:<10 digit unix time>`; `expire()` deletes the files whose time
  has come. Static hits run no PHP: a file goes with the first request PHP handles after its time,
  or with `drain()`, which a cron job can call to make expiry exact. The earliest time is kept in
  `site/assets/cache/StaticPages/expire.next`, which uninstalling deletes. Without the index
  tables a render with an expiry time is not cached (`no index tables: a render with an expiry
  time is not cached with the manifest`).
- **Track changes on front-end requests** (`trackOnFrontend`, off). Checked, the paths of *Track
  changes in these paths* are also checked after the response of a front-end request PHP handles,
  at most once a minute (stamp `site/assets/cache/StaticPages/front.stamp`). A change wipes
  everything in that same request and logs `Tracked path changed (front-end request): <patterns>`;
  no notice is shown.
- **Cache only these hosts** (`cacheHosts`, empty): one host per line, `example.com` or
  `example.com:8080`, letter case aside. A render is cached only when `$config->httpHost` is
  listed, asked before *Static files per host*, else skipped (`the host "<host>" ($config->httpHost)
  is not listed in Cache only these hosts`). The block serves a copy only to a request whose `Host`
  header is listed, and, with *Cache XML and plain text responses* checked, sends a request for a
  file of *Replace these root files with pages* from any other host to its page. Only plain host
  names go into the block; a list holding none has no copy served at all. Changing the list
  deletes every copy on that save, *Dry run* checked or not.
- Public API: `expireAt($timestamp)`, `expire($now = null)`.
- **README**: *How a save finds the files* with the tag grammar and a table of examples, *Narrow
  finds by the fields they use*, *Expiry* with a cron example, *Cache only these hosts*, and
  front-end checks under *Limits of path tracking*.

### Fixed
- **A root file named like an old no-slash copy** (`/sitemap_index.xml`) was deleted by its record
  also while unlisted or with *Cache XML and plain text responses* off; now only while listed and on.
- **A drain with the base directory's marker missing dropped the queue**, deleting nothing: the
  files stayed stale after the marker was back. It now keeps the rows, logs once per drain
  (`the drain (queue kept) deleted nothing: ...`), and the first drain after the marker is back
  takes them.
- **A tracked path covering the site root (`*.txt`) counted the module's own root files**: the
  recorded copy `/robots.txt` and its backup `/.robots.txt`, a wipe after every render of that
  page. Both are left out now; a hand-made file the index does not record still counts.
- **The *Root .htaccess* texts were entity encoded twice on multi-language installs**: a quote
  showed as `&#039;` (`ProcessWire&#039;s`). Each text is now encoded once, with or without
  LanguageSupport.

### Tests
- `tests/smoke.php` checks the tags a save queues (the `Pages::added` path, repeater items, a
  template or parent change), the selector analysis behind *Narrow finds* with a table of
  selectors and end to end saves, the fieldgroup-derived scope with a probe of core's finds, the
  hooks that wipe everything, the `any` counts and log line, expiry (`expireAt()`, *Maximum age*,
  `expire()`, `expire.next`, root and typed files), the front-end path check, and *Cache only these
  hosts* on the write side and in the block.

## 0.12.0 (2026-11-24)

Static copies can be written below one directory instead of the web root, and per host name.
Three fixes: a forced scan, the default *Non-static directories*, and pages without a trailing
slash. Both new settings are off by default.

### What happens on an existing site
With the new settings at their defaults:

- **Changed: a page whose template has no trailing slash, and every page below it, is no longer
  cached** (*Should page URLs end with a slash?* set to no). Nor is a render with URL segments
  whose template says no to *Should URL segments end with a trailing slash?*. *Log skipped pages*
  says one of:
  - `its template <name> has no trailing slash: a copy dir at its URL would loop between the web server's slash redirect and ProcessWire's`
  - `it is below <path>, whose template <name> has no trailing slash: a copy dir at that URL would loop between the web server's slash redirect and ProcessWire's`
  - `its template <name> has no trailing slash after URL segments: a copy dir at that URL would loop between the web server's slash redirect and ProcessWire's`

  0.3.1 to 0.11.0 cached them, and once the page's copy directory existed Apache answered its URL
  with a redirect loop: mod_dir sent `/a/b` to `/a/b/`, ProcessWire sent `/a/b/` back to `/a/b`,
  for `GET`, `HEAD`, `POST`, a query string and the login cookie alike. Copies written by older
  versions stay until a save of a page they depend on or a wipe deletes them, and the loop stays
  with them: wipe once after the update on a site with such templates.
- **Changed: a forced scan and the default *Non-static directories* delete copies they left
  before**, see *Fixed*.
- **Unchanged**: the StaticPages block in the root `.htaccess`, byte for byte the one 0.10.0 and
  0.11.0 write. Nothing is rewritten on the update, and a site that chose *Keep them* has nothing
  to add by hand. The names of the copies, the tables and the config keys do not change either;
  there is nothing to migrate.

### Added
- **Static base directory** (`staticBaseDir`, empty). A path relative to the web root,
  `site/assets/static` say: every copy, the home page's and the root files' included, is written
  below it instead of the web root, and the block serves it from there. The module writes into it
  and deletes from it only while it holds the marker file `.staticpages`, which the settings save
  puts into a new or an empty directory (*Dry run* checked or not); a directory holding files and
  no marker is warned about and left alone. Refused, with a warning, and the copies stay in the web
  root: an absolute path, `.` or `..`, a name that is not plain, a path leading out of the web root
  or to the web root itself, and every directory the stock `.htaccess` denies to visitors, where
  each copy would answer 403: `site/assets/cache`, `logs`, `backups`, `sessions`, `config`,
  `database`, `install`, `tmp`, a `site/assets` directory starting with `-`, `site/install`,
  `site/classes`, `site/templates`, `site/templates-admin`, `wire/templates-admin`, `site-default`,
  and names such as `*.php` or `*.bak` below `site/` or `wire/` (`site-<name>` as `site`, in any
  letter case). With a base directory, a render whose copy path a stock 15A deny line matches (a
  page segment `-x`, `feed.php`, `x.bak`; a host such as `docs.php.example.com`; a *Static file
  name* such as `index.bak`) is not cached and ProcessWire answers it: `the stock .htaccess denies
  its copy path <path> to visitors (403 Forbidden)`. Without one nothing is tested, as in 0.11.0.
  Changing the setting deletes every copy under the old place on that save, *Dry run* checked or
  not; a forced wipe checked on that same save scans the old layout. *Non-static directories*
  does not apply below it, and a scan treats its top level as the root level. The web root's own
  root files are never taken over there; the save that switches to a base directory gives a dot
  backup back (`.robots.txt` to `robots.txt`), and one left by a *Dry run* on that save stays until
  the site is back without a base directory: in base directory mode nothing renames a dot backup,
  uninstalling included. Uninstalling leaves the base directory and `.staticpages`, as does a
  change of the setting. Tracked paths skip the base directory while it holds the marker: under a
  tracked path covering it, every copy written would otherwise have counted as a changed source
  file and wiped everything. A marker left behind by a change counts once (one wipe).
- **Static files per host** (`staticHostKey`, off; needs a base directory): each host's copies in
  a directory of their own, `<base>/<host>/`, the host lower-cased with `:` as `_`. A render is
  cached only for a host listed in `$config->httpHosts` and only when the request's `Host` header
  is `$config->httpHost`, else skipped (`Static files per host: <why>`). Turning it on or off
  deletes every copy on that save. The block gets the rules once per host of `$config->httpHosts`,
  the `Host` header matched without regard to letter case and a port only as listed; any other
  host goes to ProcessWire.
- **README**: *Static base directory and static files per host* with the generated block,
  *Pages without a trailing slash*, the new skips under *Why is nothing being generated*, the URLs
  the stock `.htaccess` refuses itself (`/x.bak/`, `/x.sql/`, `/license.txt`: ProcessWire never
  sees them), and an nginx example for both settings, run on nginx 1.29 first.

### Fixed
- **A forced scan left recorded copies outside the dirs it walks on disk.** It cleared the index
  and the manifest and then scanned, and a scan walks only the page dirs of the root: a copy
  elsewhere, the home page rendered with a URL segment (`<segment>/index.html`), lost its record
  and stayed, stale, where no save or wipe found it again. The scan now deletes every recorded file
  first, through the same checks as any delete; its dry run lists each path once.
- **The default *Non-static directories* protected the module's own copies.** With the list never
  saved, a root dir no page is named like that holds only static copies (the home page with a URL
  segment) was in the default, so no save or wipe deleted its copy again. It is left out now when
  the index records a file below it (no base directory, index tables known to be there). A dir
  nothing was recorded under, such as a hand-made `landing/`, stays protected.
- **A page without a trailing slash looped**, see above. Its old copy written as `a/bindex.html`
  beside an empty `a/b/` (on sites with LanguageSupportPageNames) is now deleted by its record, by
  a page save or a wipe, with that directory when it is empty, and the default *Non-static
  directories* counts such a recorded file as a static copy. An unrecorded one stays; the README
  says how to find it. A page listed in *Replace these root files with pages* is still written as
  its root file; the home page is never taken for a page without a trailing slash.

### Tests
- `tests/smoke.php` checks the base directory (validation and refusals, the marker, writes,
  deletes, scans, root files, tracked paths, the stock deny lines), the host key, the block for
  both settings, the forced scan's record pass, the default list's record lookup, and the skip of
  pages without a trailing slash with the deletes of their old copies.

## 0.11.0 (2026-11-23)

Pages answering XML or plain text can be cached, and a page can answer for a file in the web
root such as `robots.txt` or `sitemap.xml`. Both are off by default.

### What happens on an existing site
With the new settings at their defaults:

- **Changed: a response typed by its `Content-Type` header alone is no longer cached as
  `index.html`.** A template whose *Content-Type* setting says HTML, or nothing, but whose code
  sends `header('Content-Type: application/xml')`, `application/json`, `text/plain` and the like
  was cached as `index.html` and served to everybody as `text/html`. It is now skipped, and *Log
  skipped pages* says `the response is sent as <MIME type>`. Such copies already on disk stay
  until a save or a wipe deletes them.
- **Changed: a template whose *Content-Type* setting is a key `$config->contentTypes` does not
  know, or the MIME type `text/html` written out, is cached as HTML.** 0.10.0 skipped it
  (`content type is '…'`).
- **Unchanged**: the names of the copies (`index.html`, or *Static file name*), what a scan or a
  wipe matches and walks, the default *Non-static directories*, the tracked paths, and the
  StaticPages block in the root `.htaccess`, byte for byte. No config key, table or file changes
  meaning, and there is nothing to migrate.

### Added
- **Cache XML and plain text responses** (`cacheNonHtml`, off). Checked, a render is written as
  `index.xml` or `index.txt` (*Static file name* with its extension replaced) by its type: the
  response's last `Content-Type` header, else the template's *Content-Type* setting, else HTML.
  XML is `application/xml`, `text/xml` and the `…+xml` types but XHTML. JSON and other types are
  never cached, nor is a render whose setting and header disagree. Never for the home page, never
  without the index tables, never over a file the index does not record. *CSRF token tags* are
  not stripped from it; the `fileContent` hook still runs. *Non-static page templates* wins, and
  the settings save names the listed templates that answer XML or plain text. Checking or
  unchecking the box deletes every copy on that save, *Dry run* checked or not. The block gets a
  rule for each of the two names, after the HTML one.
- **Replace these root files with pages** (`rootFiles`, empty): one `<file name> <page path>` per
  line, `robots.txt /robots/`, `sitemap.xml /sitemap/`, used only with the box above checked.
  The block routes the name to its page while no file of that name exists; the page's render is
  written to the web root under that name, and the web server serves it from then on. A
  hand-made file of that name is taken over on a settings save, only while the `.htaccess` holds
  the route: it is renamed to its dot backup (`.robots.txt`, `.sitemap.xml`, 403 under the stock
  rules), which is never overwritten. It comes back when the line is removed, when the box is
  unchecked, and on uninstall. A wipe, forced or not, deletes the module's copy by its record and
  renames nothing. *Dry run* holds the renames back and logs them. A site keeping its own rules
  adds the route by hand; the save's warning names the two lines.
- **README**: *XML and plain text*, *robots.txt and sitemap.xml*, the new skips under *Why is
  nothing being generated*, a hand-made directory holding only an `index.xml` or `index.txt`
  (served to a guest's `GET` with the box checked, where the stock rules answer 403), and the
  nginx example extended for both settings, run on nginx 1.29 first.

### Changed
- A page directory that can not be created is logged as a skip (`could not create <dir>`), with
  *Log skipped pages* on.
- A manifest line naming anything but the HTML copy is not imported into the index.

### Tests
- `tests/smoke.php` checks the type of a render, the name sets, the writes and refusals, scans and
  deletes by name and by record, the take-over and the give-back with their refusals and dry
  runs, the uninstall order, a forced wipe with a root file copy, and the block with both
  settings, the default block pinned by its hash.

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
- **A directory made by hand in the web root** (a landing page, a static mini-site) holding an
  `index.html` cannot be told from a page's copy: with the block, a query string (`?utm_source=…`),
  a `POST` or the login cookie hands it to ProcessWire, which answers 404 where the stock rules
  served the file. On an `absent` site the block is written without asking, so check the web root
  first, and add a rule of your own above the block for each such directory (README, *Directories
  made by hand*). A directory holding an `index.htm` is left to the stock rules, unless *Static
  file name* is `index.htm`.

At the time of this release one site loads the module straight from this repository. The others
run a copy of 0.9.1 and meet all this when that copy is updated. This may change.

### Added
- **The StaticPages block** in the root `.htaccess`, made from the settings. It serves a page's
  copy to a guest's `GET` or `HEAD` without a query string. A `POST`, a query string and the
  login cookie go to ProcessWire (the README's old rules served the copy to a `POST` and to a logged-in
  visitor). A
  directory without a copy goes to ProcessWire instead of 403, and a directory with an `index.php`
  of its own (an `/api/` endpoint) or an `index.htm` keeps the stock answers. Proven on Apache 2.4 at the web root
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
  manifest) records, forgets what the `.htaccess` check stamped and told (a reinstall tells its
  outcome again), and last it drops the tables. Copies nothing recorded stay. An uninstall
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
