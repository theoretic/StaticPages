
# StaticPages
Lazy static pages generator for Processwire CMS.

## How does it work

Normally Processwire renders any page any time it's requested. StaticPages module allows Processwire to render certain pages only once. When it happens, StaticPages creates a static html file located corresponding to the URL which has been requested. The following requeststs to this URL will not be handled by Processwire. The previously created html file will be returned by the server.

Module reacts to the following events:

- Page render: a new static html file is created.
- Page save: all previously generated html files will be deleted.
- Page delete: all previously generated html files will be deleted.
- Single field save: all previously generated html files will be deleted. Inline admin edits and `$page->setAndSave()` never reach a page save. Can be switched off in the module settings.
- Config save of a listed module: all previously generated html files will be deleted. Modules like SettingsFactory keep site-wide settings in their own module config data and save no page, so a page save event never happens even though the settings end up in the markup. List such modules in the *Wipe on config save of these modules* setting, one class name per line.
- Tracked file change: all previously generated html files will be deleted. Editing a template or an asset file fires no ProcessWire event at all, so nothing would tell the module its static files went stale. The patterns listed in the *Track changes in these paths* setting are checked on admin requests by file name, modification time and size; file contents are never read. `*` matches inside one path segment, `**` matches any number of segments including none.

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

## Limits of path tracking

- The check runs on admin requests only. A fully cached URL never reaches PHP, so there is no front end request left to hang it on. A developer who edits files and never opens the admin will not see the cache wiped.
- Checks are throttled to once every 10 seconds so that admin ajax does not walk the tracked directories on every keystroke.
- Only the modification time and the size are compared. An edit changing neither goes unnoticed.
- Symlinked directories inside a tracked path are not descended into. A symlinked base directory is fine.
- A hash file that is missing or unreadable is treated as a first run: it is seeded, nothing is wiped. So a deploy that clears `site/assets/cache/` and changes templates in the same step will not wipe. Deploy scripts should ask for it directly:

```php
$modules->get('StaticPages')->checkTrackedPaths(true);  //check now, ignoring the throttle
$modules->get('StaticPages')->wipeCaches(true);         //or just wipe, full disk scan
```

## Warning

However tested and used on several websites, hosting platforms and environments, this mode should be considered as beta release. Use it at Your own risk. Since StaticPages may perform bulk files and folders deletion, it's strongly advised to make the full website backup before the first use.