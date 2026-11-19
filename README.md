
# StaticPages
Lazy static pages generator for Processwire CMS.

## How does it work

Normally Processwire renders any page any time it's requested. StaticPages module allows Processwire to render certain pages only once. When it happens, StaticPages creates a static html file located corresponding to the URL which has been requested. The following requeststs to this URL will not be handled by Processwire. The previously created html file will be returned by the server.

Module reacts to the following events:

- Page render: a new static html file is created, and the pages its render depended on are recorded with it.
- Page save, delete, sort, trash or restore: the static files that depended on that page are deleted, and only those. See *What a save deletes* below.
- Single field save: the same. Inline admin edits and `$page->setAndSave()` never reach a page save. Can be switched off in the module settings.
- Config save of a listed module: all previously generated html files will be deleted. Modules like SettingsFactory keep site-wide settings in their own module config data and save no page, so a page save event never happens even though the settings end up in the markup. List such modules in the *Wipe on config save of these modules* setting, one class name per line.
- Tracked file change: all previously generated html files will be deleted. Editing a template or an asset file fires no ProcessWire event at all, so nothing would tell the module its static files went stale. The patterns listed in the *Track changes in these paths* setting are checked on admin requests by file name, modification time and size; file contents are never read. `*` matches inside one path segment, `**` matches any number of segments including none.

A save does not delete anything itself. It queues what went stale, and the files are deleted
once the response has been sent: under php-fpm and LiteSpeed the connection is closed first,
so the editor never waits; under other SAPIs the output is flushed first. A queue left behind
by a killed process is finished by the next admin request. Each drain writes one line to the
`static-pages` log: what it was asked for, how many files and dirs it deleted, how long it took.

## What a save deletes

While the front end renders a page, the module records what the render depended on: every page
it loaded, and every `find()` it ran, by the template, parent or ancestor that find was limited
to. The record lives in three database tables next to the static file. The finds are turned
into that record only when the file is written, so requests that end up not cached pay
nothing for them.

A save of a page then deletes:

- every static file whose render loaded the page. That covers the page itself, its listings,
  menus, breadcrumbs, related-pages blocks and page-reference fields pointing at it;
- every static file whose render ran a find the page may have entered or left: one limited to
  its template, its parent or one of its ancestors, or a find with no such limit. A new post,
  a published draft or a post edited into `featured=1` appears in listings that never loaded it.
  Before and after a move or a template change;
- for a rename or a move, the files of every descendant too, their urls changed.

An unpublished page that stays unpublished is shown nowhere, so editing a draft deletes nothing.
A save that changes nothing deletes nothing.

Everything is deleted when:

- a language page is saved, a page of a template listed in *Wipe everything on save of these
  templates* is saved, or a field listed in *Wipe everything on change of these fields* changes;
- a page with children is trashed or restored: ProcessWire sets the children's status in SQL,
  with no save and no hook;
- a renamed or moved page has more than 5000 descendants;
- a listed module's config is saved, or a tracked file changed.

### Templates whose saves invalidate nothing

*Never invalidate on save of these templates* is for pages no cached page shows and that are
saved often: orders, form entries, log pages. Each such save used to delete every file whose
render ran a find with no template or parent limit, and to change the generation stamp, so a
render running meanwhile was not cached either. A save, single field save, deletion, trashing,
restore, sort or move of a page of a listed template now queues nothing: no file is deleted, the
stamp stays, no drain runs. A repeater item counts as a page of its owner's template, through
nested repeaters. A field listed in *Wipe everything on change of these fields* changing on such
a page wipes nothing either.

A template change is judged by both templates. Moving a page into a listed template from one
that is not listed still invalidates once, as it would with the list empty: until that save
cached pages could show it. Saves of the page after that are ignored; ProcessWire keeps
reporting the old template on that page object for the rest of the request, though, so a
further save of it in the same request invalidates as well. Moving a page out of the list
invalidates as usual. A change between two listed templates queues nothing, but for the
repeater container below.

One exception to "queues nothing": ProcessWire keeps a page's repeater items under an admin page
of their own (`…/repeaters/for-field-N/for-page-<id>/`), created when the page is saved, with or
without items, unless the field's *lazy parents* option is on. Deleting a listed page whose
template carries a repeater field, or changing its template to one without that repeater field,
deletes this container too, and its deletion is judged by its own template, which is not listed:
one queue row (`p:<container id>`), one change of the generation stamp, one drain. That drain
deletes the files whose render loaded the container page itself (a render of the page does where
its stored value for the repeater field names no container) and, as every drain does, the files
tagged `*`: more than 5000 dependencies, adopted files, files a delete failed on.

What you sign by listing a template:

- wherever a cached page does show a page of that template (a listing, a counter, a "latest"
  box, a page reference), that copy stays stale until something else deletes it: a save the
  copy depends on, a wipe of everything, `wipeCaches()`;
- the page's own static copy is not deleted either (but for the repeater container above). List
  the template in *Non-static page templates* as well when guests can open its pages;
- deleting, moving or renaming such a page deletes nothing (but for the repeater container
  above), so the static copies of its descendants stay where they are, under their old urls.
  Trashing or restoring one with children deletes nothing either.

A template also listed in *Wipe everything on save of these templates* still wipes everything,
the broader rule wins, and saving the settings warns about it. Language pages always wipe
everything.

What the record can not see, so list the template or the field when it applies:

- `$pages->findRaw()` and `$pages->getRaw()`, which never load pages;
- markup cached by WireCache, MarkupCache and the like, rendered by an earlier request;
- raw SQL;
- status changes through `$pages->addStatus()` and friends, which save no page.

A render that read the database before a save and finishes after it is not cached: a
generation stamp in `site/assets/cache/StaticPages/` changes with every queued save.

Where the tables can not be created (no CREATE privilege) the module says so in the
`static-pages` log once an hour and falls back to the old behaviour: a manifest file, and every
save deleting every static file, synchronously.

The tables are named `static_pages_files`, `static_pages_tags` and `static_pages_queue`. Their
layout version is kept in their table comments (`StaticPages schema 1`), so it always matches
the tables actually there; tables left by a newer module version are not written to, and the
module falls back as above until it is upgraded again. The first request that needs the tables
after an install or an upgrade creates missing ones, migrates older ones (one request at a time)
and stores the outcome in the module config as `indexSchema`: every later request trusts it
and runs no `CREATE TABLE` and no `information_schema` query. A query that finds a table missing
(dropped, or a dump restored without it) clears `indexSchema`, and the next request checks and
creates again. Uninstalling the module deletes the static copies the tables record and then drops
the tables, see *Uninstalling* below.

## Upgrading

Nothing to do, on every site sharing the module dir as well, from any earlier version: 0.3.1
to 0.9.1, and the older 012 and 020 copies; but a site that chooses the language by host name
adds its own `.htaccess` rules before updating to 0.10.0 (see below). Replace the module dir; a
modules refresh is not needed (core skips `___upgrade()` when the module file changes from
`StaticPages.module` to `StaticPages.module.php`, so nothing depends on it).

After the response of the first admin request, CLI script or request that queued a save, the
module brings the install up to date once, and logs each step to `static-pages`:

- the stored config loses `rmDirPasses` (every scan takes one pass since 0.7.0), the force wipe
  boxes are cleared, and a priority of 1000 is stored where none was (012 and 020 never stored
  one; 0.7 read that as 0 and cached ahead of every output transformer);
- *Non-static directories* loses the entries that name a first level page dir holding nothing
  but static copies, or a page dir that is not there at all. Every version before 0.6.3 saved a
  snapshot of the site root that took them in, and their copies were never wiped;
- static files on disk that nothing recorded (012 and 020 kept no manifest; files cached before
  a manifest version was installed were never listed) are found the way a full scan finds them
  and recorded with unknown dependencies. Nothing is deleted then: the next save removes them.
  Files listed in an old manifest are imported the same way.

Keys the module does not know (`-dups`, `nonStaticLanguages` from older lineages) are left in
place. The tables are created on the first render or save.

From 0.10.0 on, the first superuser admin request or CLI run after the update also checks the
root `.htaccess` (see *Server configuration*). A stock file gets the StaticPages block on that
request, after a backup. A file with rules written by hand for the static files gets nothing,
and a superuser gets a warning and the choice between *Keep them* and *Replace*. A visitor's
request never touches the file. A site that chooses the language by host name adds its own
rules first, see *Multilingual sites*.

A file is written to a temporary name in its own directory and moved into place with
`rename()`, which is atomic within a filesystem. A request arriving while another one is
still writing is answered from the previous complete file, or from PHP, never from a
half-written one. A write that fails takes the directory it created back down with it,
so a request can never meet an empty directory either.

## Why is nothing being generated

A rendered page is **not** cached when any of these is true. The *Log skipped pages*
setting writes the reason for each one to the `static-pages` log; turn it on when the
module looks like it is doing nothing, and off again afterwards on a busy front end.

- Somebody is logged in. Markup rendered for a logged-in user may hold private data, and
  a static file is served to everyone. This is the answer almost every time the module
  appears to be installed, enabled and idle: an operator testing the site is logged into
  the admin in the same browser.
- The response carries a status other than 200, or the page is the ProcessWire 404 page.
  A static file is served with 200 for as long as it exists, so an error page frozen into
  one would be handed out as if it were the real thing.
- The template answers a content type other than html, or the request is an ajax request.
- The request carries GET or POST variables, or it is a paginated page beyond the first.
- The request carries a cookie listed in *Never cache with these cookies* (one name per line,
  `*` and `?` as wildcards): output depending on a currency, region or consent cookie would be
  frozen for everybody.
- The response sets a cookie other than ProcessWire's own session cookies, or sends a `Vary`
  header on anything but `Accept-Encoding` (`Vary: Accept-Language`, `Vary: Cookie`): one file
  can not answer for every value of that header. Output that depends on `Accept-Language`
  without saying so can not be told apart; exclude its template.
- The template is listed in *Non-static page templates*.
- The page's dir is listed in *Non-static directories*: no wipe would ever delete the file.
- *Static file name* is not a plain file name (letters, digits, `.`, `_`, `-`, no leading dot).
- A line of *CSRF token tags* is no valid regex.
- Site code said no: `noCache()` was called, a hook on `StaticPages::allowCache` returned false,
  or a hook on `StaticPages::fileContent` returned nothing. See *Hooks and API* below.
- The module is being uninstalled (`the module is being uninstalled`): from the moment an
  uninstall begins nothing is cached, so no copy outlives the index that records it. An
  uninstall that died halfway stops caching for an hour at most; the next admin request after
  that removes its mark.

## CSRF tokens

A CSRF token belongs to one session, and a static file is served to everybody. Before a file is
written, the tags matched by the *CSRF token tags* setting have their `content` and `value`
attributes emptied, and an input's `name` too. One regex per line, delimiters included, each
matching one whole tag. The default matches the `@atispro/core` markup, which `00-csrf.js`
fills in again from `GET /api/data`:

```
/<meta\b[^>]*\bname=([\x22\x27]?)csrf-token(?:-name)?\1(?=[\s\/>])[^>]*>/i
/<input\b[^>]*\brel=([\x22\x27]?)csrf\1(?=[\s\/>])[^>]*>/i
```

A site with other markup lists its own tags there. An empty setting strips nothing. A line that
is no valid regex stops caching altogether, rather than freezing a live token, and the config
save names it. Site code writing its own static files calls `$modules->get('StaticPages')->stripCsrf($html)`,
which answers `null` when the markup could not be prepared.

## Hooks and API

Site code can keep a render out of the cache and change what is written to the file. It can
never force a file: `noCache()` and `allowCache` are asked only about a render every built-in
check of the request already accepted (logged in, status, cookies, headers, template, content
type, ajax, GET and POST, page number), and before anything is created on disk. A yes is no
promise: what comes later (*CSRF token tags*, `fileContent`, the generation stamp, *Static file
name*, *Non-static directories*, the write itself) can still refuse. With *Log skipped pages*
on, a refusal by `noCache()`, `allowCache` or `fileContent` is logged with its reason.

### Keeping a render out of the cache

For output that depends on something no URL shows (session state, a URL segment, a request
flag), call `noCache()` from template code. It holds for the rest of the request; the reason,
when a string, goes to the log, and `noCache(false)` clears it again:

```php
if( $session->get('cart') ) $modules->get('StaticPages')->noCache('cart in session');
```

The same decision from a hook, in `site/ready.php`. `allowCache` gets the page being rendered
and returns true; a falsy return keeps the render out of the cache:

```php
$wire->addHookAfter('StaticPages::allowCache', function(HookEvent $event) {
	$page = $event->arguments(0);
	if( $page->template->name === 'search' && $event->wire()->input->urlSegment1 !== '' ) $event->return = false;
});
```

A render refused by `noCache()` never reaches the hook.

### Changing the stored copy

`fileContent` gets the page and the markup about to be written, after *CSRF token tags* were
stripped, and returns what is written. The visitor of this request gets the render as it was,
the hook changes the file only. Anything but a non-empty string keeps the render out of the
cache, with nothing left on disk. Refusing a render that came out incomplete:

```php
$wire->addHookAfter('StaticPages::fileContent', function(HookEvent $event) {
	if( stripos($event->return, '</html>') === false ) $event->return = '';
});
```

A CSP nonce is made per request, and a static file is served to everybody: the nonce frozen
into it would be the same for every visitor. The hook can put a server side include in its
place, which the web server fills with a new value on every request. The template makes the
nonce for the PHP response:

```php
// site/templates/_init.php
$config->cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: script-src 'nonce-{$config->cspNonce}'");
```

```php
<script nonce="<?= $config->cspNonce ?>">…</script>
```

and the hook swaps it for an SSI echo of nginx's per-request id in the file:

```php
// site/ready.php
$wire->addHookAfter('StaticPages::fileContent', function(HookEvent $event) {
	$nonce = (string) $event->wire()->config->cspNonce;
	if( $nonce === '' ) return;
	$event->return = str_replace($nonce, '<!--# echo var="request_id" -->', $event->return);
});
```

The location that serves the static files, not the one passing requests to PHP, sends the
matching header:

```nginx
ssi on;
add_header Content-Security-Policy "script-src 'nonce-$request_id'" always;
```

nginx ignores the server level `add_header` lines in a location that has one of its own: repeat
the headers of *Response headers* below in it. A second `Content-Security-Policy` sent for the
same response applies on top of this one, and both have to allow the script.

## Installation

- Download or git clone this repository
- Copy the StaticPages folder inside /my-processwire-project/site/modules directory
- Go to PW admin page
- Go to Modules admin page
- Hit Refresh button
- Locate the newly found StaticPages module in the Site tab
- Hit Install button
- Modify module settings at will

The web server has to be told to answer a request from a static file before it hands the request
to ProcessWire. On Apache the module does that itself, in the site's root `.htaccess`: see
*Server configuration* below. A site that chooses the language by host name reads *Multilingual
sites* first.

## Server configuration

### The StaticPages block

On Apache the module keeps one block of rules in the root `.htaccess` (the one next to
ProcessWire's `index.php`), made from its settings. With the default settings it reads:

```apache
# BEGIN StaticPages
# Generated by the StaticPages module and replaced on every update: change its settings, not these lines.
# The home page, from its copy.
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=
RewriteCond %{REQUEST_FILENAME}/index\.html -f
RewriteRule ^$ index\.html [L]
# A page, from its copy, unless its directory holds an index file of its own.
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteCond %{REQUEST_FILENAME}/index\.html -f
RewriteRule ^(.+/)$ $1index\.html [L]
# Any other request for an existing directory, with its trailing slash, a URL the stock 16A condition accepts and without an index file of its own, goes to ProcessWire.
RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"
RewriteCond %{REQUEST_FILENAME} -d
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteCond %{REQUEST_FILENAME}/index.htm !-f
RewriteRule ^(.+/)$ index.php?it=$1 [L,QSA]
# END StaticPages
```

What it does:

- A `GET` or `HEAD` request without a query string, from a visitor who is not logged in, is
  answered from the page's static copy when there is one: `/` from `index.html`, `/a/b/` from
  `a/b/index.html`.
- A query string, any other method (a form's `POST`) and the login cookie
  (`<session name>_challenge`, for the http and the https session name) go to ProcessWire, which
  renders the page. The cookie condition is there only while `$config->sessionChallenge` is on, as
  it is by default; without it a logged-in visitor gets the copies too.
- Any other request for an existing directory, with its trailing slash, goes to ProcessWire. That
  covers a directory that holds no copy (`/a/` when only `/a/b/` is cached), where the stock rules
  would answer 403, and a directory made by hand holding an `index.html`, where they would serve
  that file: see *Directories made by hand*.
- A directory that holds an `index.php` of its own (an `/api/` endpoint, a tool dropped into the
  web root) is left to the stock rules, copy or not. So is one holding an `index.htm`, the third
  name of the stock `DirectoryIndex` line, unless *Static file name* is `index.htm` itself.
- Everything else (existing files, paths that are no file or directory, URLs the stock 16A
  condition refuses, directories requested without their trailing slash) gets what the stock
  file gives it.

Missing or outdated, the block only costs speed: without it every request reaches PHP. The
block goes immediately before the `# PW-PAGENAME` line, inside the `mod_rewrite` section. Further
down it would take the stock conditions of section 17 for itself. Its rules are relative to the
`.htaccess` directory, so a ProcessWire installed in a subdirectory gets the same answers. *Static
file name* and the session settings are the values it is made from, and the settings form shows
it under *The StaticPages block*.

### Directories made by hand

A directory made by hand in the web root (a landing page, a static mini-site, a built front end)
is served by the stock `DirectoryIndex index.php index.html index.htm`, whatever the request. With
the block:

- one holding an `index.php` is left alone;
- one holding an `index.htm` is left alone, unless *Static file name* is `index.htm`;
- one holding an `index.html` cannot be told from a page's copy. A query string
  (`?utm_source=…`), a `POST` or the login cookie hands it to ProcessWire, which answers 404 where
  the stock rules served the file; with *Static file name* other than `index.html`, every request
  does. The same holds for an `index.htm` when *Static file name* is `index.htm`.

The block is written without asking on a site that has none yet (see below), so check the web
root for such directories. Each one gets a rule of the site's own above the block, immediately
before the `# BEGIN StaticPages` line, or before `# PW-PAGENAME` while there is no block yet (the
module then writes its block below the rule). For `landing/`:

```apache
# By hand: landing/ is a directory made by hand, served as the stock rules serve it.
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^landing/ - [L]
```

Its directories, and everything below them, then get the stock answers. A missing path under
`landing/` still goes to ProcessWire, as on a stock site.

### When the module writes it

Never from a visitor's request. After the response of a superuser's admin request, and after a
CLI script (a deploy script, a cron job), the module compares a stamp with the `.htaccess` file's
modification time and size, its own version and its settings. That costs three `stat()` calls;
only when something changed is the file read. Then, by the state the file is in:

| State | Meaning | What the module does |
|---|---|---|
| `absent` | the stock rules, no block, no hand-written StaticPages rules | adds the block |
| `current` | the block is there as the settings make it | nothing |
| `outdated` | the block differs: a module update, a changed setting, a hand edit inside the block | writes it again |
| `candidate` | rules written by hand that look like StaticPages rules | writes nothing; a warning, see below |
| `none` | no `.htaccess` in the site root (nginx, rules in the virtual host) | nothing, the file is never created |
| `locked` | the file or its directory cannot be written, or `.htaccess` is a link | nothing |
| `damaged` | a `# BEGIN StaticPages` without its `# END StaticPages`, two blocks, or the markers in the wrong order | nothing; fix the markers by hand |
| `unrecognised` | not a ProcessWire `.htaccess`: no `START PROCESSWIRE HTACCESS DIRECTIVES` line, or not exactly one `# PW-PAGENAME` line in the `mod_rewrite` section | nothing |

The same happens when the module is installed, and when its settings are saved (a changed
*Static file name* changes the block). Whatever it did is written to the `static-pages` log, and
the next admin page of a superuser shows it once. The other states are logged once each time they
change, and the settings form shows the state under *Root .htaccess*. With *Dry run* checked, a
save writes nothing and logs the new file line by line instead; the check after that request
writes nothing either, the next one does.

### Hand-written rules: Replace or Keep

Sites that served static files before 0.10.0 have rules written by hand for it. The module looks
for them outside its own block, a run of `RewriteCond` lines and the `RewriteRule` that ends it at
a time, comments ignored, and takes as StaticPages-like a rule that:

- names the static file (`index.html`, also as `index\.html`) in a condition or in the rule;
- hands a request to `index.php` and tests for a directory, matches the home page, or is none of
  the stock rules to `index.php`;
- depends on the request method (`RewriteCond %{REQUEST_METHOD} …`);
- or is a `DirectoryIndex` line that lists the static file first.

Stock lines are not counted, with one exception: the stock request method block of section 11E,
when uncommented, depends on the request method and is reported. Neither is a hand-written
route of one file name to a page (`RewriteRule ^sitemap\.xml$ index.php?it=/sitemap/ [L,QSA]`)
nor a redirect that only adds a trailing slash. A false alarm costs a warning and nothing is
written. When the module finds such rules it writes nothing, and a superuser gets a warning
naming the lines and the settings page.
The settings form shows them with their line numbers, and *Hand-written rules found* offers:

- *Keep them*: nothing is written, and there is no warning about these rules again until they
  change. The form keeps showing the block the module would write, as a reference for rules a
  later version adds.
- *Replace them with the StaticPages block*: after a backup, every hand-written line of those rules
  is commented out with the prefix `# StaticPages-replaced: `, and the block is written. Nothing
  is deleted:
  - A line the stock `.htaccess` also holds keeps its place and stays active.
  - A rule that only depends on the request method is left as it is, and stays a warning until
    *Keep them* is chosen for it. Replace is refused when such a rule stands below the place of
    the block.
  - A `DirectoryIndex` line that listed the static file first is commented out, and the stock
    line `DirectoryIndex index.php index.html index.htm` is added after it.
  - Replace is refused, with the reason on the form, when it would leave a kept rule without its
    hand-written condition, or an active `RewriteCond` right above the block, or no active rule
    that hands every request to `index.php` (the stock rule 19,
    `RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]`). A rule 19 not written like the stock line
    (flags in another order, an absolute path such as `/pw/index.php?it=$1`) counts as
    hand-written: write it like the stock line, or choose *Keep them*.

The choice is acted on once, when the settings are saved, and never stored. Uninstalling the
module takes the prefixes off again and gives the hand-written rules back byte for byte.

### Backups and checks

Before every write the file is copied to `.htaccess.sp-<YmdHis>.bak` next to it. The five newest
backups are kept; ProcessWire's `.htaccess` denies every file name that starts with a dot. The new
text is written to a temporary file and renamed over the old one, then read back. When the write
happens in a web request, one request for the site's home page follows; a 5xx answer puts the
backup back. A file
that no longer parses is caught by that request, but a rule that answers wrongly is not.

### Web roots under git, deploy scripts

A web root under version control shows the block as a change of `.htaccess`: commit it. A deploy
that puts an `.htaccess` without the block in place makes the state `absent` again, and the next
superuser admin request or CLI run adds the block again, with a diff and a backup. Deploy scripts
can ask and act themselves:

```php
$sp = $modules->get('StaticPages');
$status = $sp->htaccessStatus();         //the state, the hand-written rules found, the block
$result = $sp->writeHtaccess(true);      //dry run: the state and the new text, nothing written
$result = $sp->writeHtaccess();          //writes from absent and outdated
$result = $sp->writeHtaccess(false, true); //also from candidate: Replace
$result = $sp->removeHtaccess();         //the block and the prefixes out again
```

Each returns the state (`$result['state']`), what was done (`'done'`: refused, nothing, dry run,
written, restored), a message and the backup's name.

### Uninstalling

Uninstalling the module first keeps every request from writing a static copy while it runs. It
then takes the block and the `# StaticPages-replaced: ` prefixes out of the `.htaccess`, after a
backup, and deletes the static copies the index (or the manifest) records. Last, it drops the
tables. Copies nothing recorded stay where they are. The backups stay too.

### Without .htaccess: nginx and other servers

The module cannot write an nginx configuration. The state is `none`, and the settings form shows
the Apache block for reference. The same behaviour for nginx, an example to add by hand to the
`server` block of ProcessWire's `nginx.txt`, for a site at the web root:

```nginx
  # StaticPages: a page's copy for a guest's GET or HEAD without a query string, what the
  # module's block does for Apache. After the deny rules, before "location /". The cookie names
  # are the ones in the block on the module's settings form.
  set $sp "";
  if ($request_method ~ "^(GET|HEAD)$") { set $sp "1"; }
  if ($args != "") { set $sp ""; }
  if ($http_cookie ~ "(^|;\s*)(wire_challenge|wires_challenge)=") { set $sp ""; }
  if ($uri ~ "/$") { set $sp "${sp}/"; }
  if (-f $document_root${uri}index.html) { set $sp "${sp}f"; }
  if (-f $document_root${uri}index.php) { set $sp "${sp}p"; }
  if ($uri = "/") { set $sp "${sp}h"; }
  if (-d $request_filename) { set $sp "${sp}d"; }
  if ($uri !~ "^/~?[-_.a-zA-Z0-9/]*$") { set $sp "${sp}x"; }
  # The home page, from its copy.
  if ($sp = "1/fphd") { rewrite ^ /index.html last; }
  # A page, from its copy, unless its directory holds an index.php of its own.
  if ($sp = "1/fd") { rewrite ^ ${uri}index.html last; }
  if (-f $document_root${uri}index.htm) { set $sp "${sp}t"; }
  # Any other existing directory, with its trailing slash, without an index.php or index.htm of its own, goes to ProcessWire.
  if ($sp ~ "^1?/f?d$") { rewrite ^/(.*)$ /index.php?it=$1 last; }
```

`$sp` collects what is known about the request: `1` for a guest's `GET` or `HEAD` without a query
string, `/` for a trailing slash, `f` and `p` for an `index.html` and an `index.php` in that
directory, `h` for the home page, `d` for an existing directory, `x` for a URL the stock 16A rule
refuses, and, after the copy rules, `t` for an `index.htm` in that directory. With *Static file
name* changed, replace `index.html` in it; with `index.htm`, leave out the `t` line, as the
block does. A directory made by hand needs a line of its own, as on Apache (see *Directories made
by hand* above): `if ($uri ~ "^/landing/") { set $sp "${sp}n"; }` after the `x` line.

Do **not** comment out the `!-d` line of the stock rule 19, as earlier versions of this README
advised: every directory request would then go to `index.php`, and no static file would ever be
served.

## Response headers

A cached page is served by the web server alone: no PHP runs, so nothing a template or a hook
sends with `header()` reaches the visitor, neither `Content-Security-Policy` nor
`X-Content-Type-Options: nosniff` nor `Referrer-Policy`. The first visitor gets them from PHP,
everybody after gets what the web server adds. Headers that must be on every page belong in
the web server configuration, where they cover static files and PHP responses alike. This is
why the archived `Headers` module never worked together with caching.

```apache
<IfModule mod_headers.c>
	Header always set X-Content-Type-Options "nosniff"
	Header always set Referrer-Policy "strict-origin-when-cross-origin"
	Header always set Content-Security-Policy "default-src 'self'"
</IfModule>
```

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'" always;
```

## Multilingual sites

The static file is keyed by `$page->path`, which carries the language segment. That is
the right key when the language is chosen from the URL: `/en/offers/` is rendered and
cached as `en/offers/index.html`, and the StaticPages block finds it.

It is the wrong key when the language is chosen from the host name. A request for
`/offers/` on an English host is cached as `en/offers/index.html`, where the block does not
look for it, and the block answers that URL from `offers/index.html`: the other language's copy.

**Such a site adds rules of its own before it updates to 0.10.0 or installs the module, and
chooses *Keep them*, never *Replace*.** Without them, the module finds the stock rules (`absent`)
and adds its block on the first superuser admin request, and the second host is served the
other language's pages. With them, the file holds hand-written StaticPages-like rules
(`candidate`): nothing is written, and *Keep them* ends the warning. *Replace* would comment them
out and write the block. A hand-written example, not made by the module, for `example.com`
whose copies are under `en/`, with ProcessWire at the web root. It goes in place of the block,
immediately before the `# PW-PAGENAME` line:

```apache
# By hand, kept with Keep: example.com is the English host, its copies are under en/.
# example.com: a page from its English copy.
RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=
RewriteCond %{DOCUMENT_ROOT}/en%{REQUEST_URI}index.html -f
RewriteRule ^(.*/)?$ en/$1index.html [L]
# example.com: its home page without an English copy goes to ProcessWire.
RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteRule ^$ index.php [L,QSA]
# example.com: any other existing directory goes to ProcessWire, never to the other language's copy.
RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"
RewriteCond %{REQUEST_FILENAME} -d
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteCond %{REQUEST_FILENAME}/index.htm !-f
RewriteRule ^(.+/)$ index.php?it=$1 [L,QSA]
# Every other host: the rules of the StaticPages block, as the settings form shows them, without its two marker lines.
```

followed by the block's rules, from `# The home page, from its copy.` to its last `RewriteRule`.
On `example.com`, `/` and `/offers/` are then answered from `en/index.html` and
`en/offers/index.html`. Without an English copy, and for a query string, a `POST` or the login
cookie, they go to ProcessWire. The other hosts get the block's answers. A rule set
like this is what the module cannot write. When a later version changes the block, the form
still shows it, and the copied rules are updated by hand.

## Non-static directories

Wipes never touch the site root dirs listed in *Non-static directories* (root dirs only: a page dir
of the same name further down, such as `/parachutes/site/`, is wiped as usual; an entry like
`about/keep` protects that dir below the root). Names are matched case-insensitively on Windows,
slashes around a name are ignored, and a line holding blanks protects each word as well. Until the
setting is saved, the list defaults to every root dir except those that only hold static copies of
first level pages (in every language, `/en/` included); a page dir that also holds other files (an
`/api/` page next to `api/` endpoints) stays protected. Saving the setting stores the list as shown,
and the save warns when it names a dir that only holds static copies: those copies would never be
wiped. Nothing is cached below a listed dir.

The list is not what keeps a full disk scan out of a dir, see below.

## Full disk scans

A scan only runs when asked for (*Force static pages deletion on module save*, `wipeCaches(true)`)
or when there is neither index nor manifest. It deletes the home page's copy at the root and walks
only root dirs that are static copies of pages: named like a first level page in any language, and
holding nothing but static files (and the temporary files a killed write left). Everything else is
left alone, listed or not, and the scan logs the unlisted dirs it left alone. Before 0.8.0 it
walked every root dir the list did not name, and the list is a snapshot from the last config save:
a dir added since (`vendor/`, a hand made landing page) lost every `index.html` and every empty dir
in it. A static copy below a dir that is not a page copy (url segments of the home page, say) is
only removed through the index.

Nothing is ever deleted that `realpath()` does not put below the site root, or that is not named
like the static file. With *Use shell commands* the scan is one `find` on Linux, macOS and BSD, or
one PowerShell process on Windows, handed only those dirs; every argument goes through
`escapeshellarg()`, and the PHP walk takes over when no shell runs (`shell_exec` disabled, say).
On Windows the PHP walk is the faster of the two, PowerShell takes a few tenths of a second to
start. *Force static pages deletion on module save* is a one shot action: the box is never stored
checked.

### Dry run

Check *Dry run* together with the force box, or call `wipeCaches(true, true)`: the same paths and
the same checks as the real scan (always the PHP walk), nothing deleted, no row dropped, and every
file and dir it would delete written to the `static-pages` log, with the root dirs it leaves alone.
`wipeCaches(false, true)` does the same for a wipe by the index or the manifest. The call returns
the list under `paths` and `leftAlone`. Run one on a copy of a site before trusting a forced wipe
there: it should list page `index.html` files and their dirs, nothing else.

## Limits of path tracking

- The check runs on admin requests only. A fully cached URL never reaches PHP, so there is no front end request left to hang it on. A developer who edits files and never opens the admin will not see the cache wiped.
- Checks are throttled to once every 10 seconds so that admin ajax does not walk the tracked directories on every keystroke. Between two checks an admin request costs one `stat()` of a stamp file; a check stats every tracked file, keep the patterns narrow.
- Only the modification time and the size are compared. An edit changing neither goes unnoticed.
- Symlinked directories inside a tracked path are not descended into. A symlinked base directory is fine.
- A hash file that is missing or unreadable is treated as a first run: it is seeded, nothing is wiped. So a deploy that clears `site/assets/cache/` and changes templates in the same step will not wipe. Deploy scripts should ask for it directly:

```php
$modules->get('StaticPages')->checkTrackedPaths(true);  //check now, ignoring the throttle; deletes at script end
$modules->get('StaticPages')->wipeCaches();             //or just wipe every indexed file, right now
$modules->get('StaticPages')->wipeCaches(true);         //the same with a full disk scan
$modules->get('StaticPages')->wipeCaches(true, true);   //list what the scan would delete, delete nothing
$modules->get('StaticPages')->drain();                  //process the queue right now
```

## Warning

However tested and used on several websites, hosting platforms and environments, this mode should be considered as beta release. Use it at Your own risk. Since StaticPages may perform bulk files and folders deletion, it's strongly advised to make the full website backup before the first use.
