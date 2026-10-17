
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
to. The record lives in three database tables next to the static file.

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
layout version is kept in their table comments (`StaticPages schema 1`), not in the module
config, so it always matches the tables actually there. Before its first write in a request
the module creates missing tables and migrates older ones, one request at a time, and logs the
migration. Tables left by a newer module version are not written to: after a downgrade the
module falls back as above until it is upgraded again. Uninstalling the module drops them.

## Upgrading from 0.6

Nothing to do, on every site sharing the module dir as well. The tables are created on the
first render or save after the upgrade, and tables from 0.7.0 get their version stamped. Files
listed in the old manifest are imported with unknown dependencies and deleted by the first save
of any page. A static file the manifest did not know about either is only found by a full disk
scan: save the module settings once with *Force static pages deletion on module save* checked.
The *Dir removal passes* setting is gone, every scan now takes one pass.

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
- The template is listed in *Non-static page templates*.

## Installation

- Download or git clone this repository
- Copy the StaticPages folder inside /my-processwire-project/site/modules directory
- Go to PW admin page
- Go to Modules admin page
- Hit Refresh button
- Locate the newly found StaticPages module in the Site tab
- Hit Install button
- Modify module settings at will
- **Important!** The server has to be told about the static files, see below.

## Server configuration

Out of the box ProcessWire's .htaccess ends with a rule that hands everything to
index.php unless it is a file or a directory on disk:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]
```

Do **not** simply comment out the `!-d` line, as earlier versions of this file advised.
That makes every directory request go to index.php, which is the one thing that stops
the generated files from ever being served: mod_rewrite runs before mod_dir, so
DirectoryIndex never gets to look inside the directory.

Leave section 19 as it is and add these two rules above it instead:

```apache
# A directory holding a static file, requested without a query string: serve the file.
RewriteCond %{REQUEST_FILENAME} -d
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{REQUEST_FILENAME}/index.html -f
RewriteRule ^(.+)/$ /$1/index.html [L]

# A directory with a query string, or with no static file in it yet: hand it to PW.
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]
```

The second rule is not optional. Without it a directory that holds no `index.html`
never reaches PHP, and `Options -Indexes` — set a few lines up in the same file —
answers it with **403 Forbidden** rather than with the page.

The home page needs its own pair, because `index.php` sits in that directory and
DirectoryIndex would prefer it:

```apache
RewriteCond %{REQUEST_URI} ^/?$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{DOCUMENT_ROOT}/index.html -f
RewriteRule ^$ /index.html [L]

RewriteCond %{REQUEST_URI} ^/?$
RewriteRule ^$ /index.php [L,QSA]
```

## Multilingual sites

The static file is keyed by `$page->path`, which carries the language segment. That is
the right key when the language is chosen from the URL: `/en/offers/` is rendered and
cached as `en/offers/index.html`, and the rules above find it.

It is the wrong key when the language is chosen from the host name. A request for
`/offers/` on an English host is cached as `en/offers/index.html`, where nothing will
look for it, and the rules above answer that URL from `offers/index.html` — the other
language's copy. Map the host to the prefix before the generic rules, and keep the host
away from the untranslated tree:

```apache
RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{DOCUMENT_ROOT}/en%{REQUEST_URI}index.html -f
RewriteRule ^(.*)$ /en%{REQUEST_URI}index.html [L]

RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteRule ^$ /index.php [L,QSA]

RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]
RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]
```

## Non-static directories

Wipes never touch the site root dirs listed in *Non-static directories* (root dirs only: a page dir
of the same name further down, such as `/parachutes/site/`, is wiped as usual). Until the setting is saved,
the list defaults to every root dir except those that only hold static copies of first level pages
(in every language, `/en/` included); a page dir that also holds other files (an `/api/` page next to
`api/` endpoints) stays protected. Saving the setting stores the list as shown, and the save warns
when it names a dir that only holds static copies: those copies would never be wiped.

Before 0.6.3 the default listed every root dir, so on a site that already had static copies it took
in their page dirs too. If an older install saved such a list, remove the page dirs from it.

## Limits of path tracking

- The check runs on admin requests only. A fully cached URL never reaches PHP, so there is no front end request left to hang it on. A developer who edits files and never opens the admin will not see the cache wiped.
- Checks are throttled to once every 10 seconds so that admin ajax does not walk the tracked directories on every keystroke.
- Only the modification time and the size are compared. An edit changing neither goes unnoticed.
- Symlinked directories inside a tracked path are not descended into. A symlinked base directory is fine.
- A hash file that is missing or unreadable is treated as a first run: it is seeded, nothing is wiped. So a deploy that clears `site/assets/cache/` and changes templates in the same step will not wipe. Deploy scripts should ask for it directly:

```php
$modules->get('StaticPages')->checkTrackedPaths(true);  //check now, ignoring the throttle; deletes at script end
$modules->get('StaticPages')->wipeCaches();             //or just wipe every indexed file, right now
$modules->get('StaticPages')->wipeCaches(true);         //the same with a full disk scan
$modules->get('StaticPages')->drain();                  //process the queue right now
```

## Full disk scans

A scan only runs when asked for (*Force static pages deletion on module save*, `wipeCaches(true)`)
or when there is neither index nor manifest. It walks the site root dirs not listed in
*Non-static directories*, and never enters the listed ones. With *Use shell commands* it is one
`find` on Linux, macOS and BSD, or one PowerShell process on Windows; otherwise a PHP walk. On
Windows the PHP walk is the faster of the two, PowerShell takes a few tenths of a second to start.

## Warning

However tested and used on several websites, hosting platforms and environments, this mode should be considered as beta release. Use it at Your own risk. Since StaticPages may perform bulk files and folders deletion, it's strongly advised to make the full website backup before the first use.