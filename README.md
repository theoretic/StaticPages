
# StaticPages
Lazy static pages generator for Processwire CMS.

## How does it work

Normally Processwire renders any page any time it's requested. StaticPages module allows Processwire to render certain pages only once. When it happens, StaticPages creates a static file located corresponding to the URL which has been requested, in the web root or below *Static base directory* (see *Static base directory and static files per host*): `index.html`, or, with *Cache XML and plain text responses* on, `index.xml` or `index.txt` for a page answering XML or plain text (see *XML and plain text*). The following requeststs to this URL will not be handled by Processwire. The previously created file will be returned by the server.

Module reacts to the following events:

- Page render: a new static file is created, and the pages its render depended on are recorded with it.
- Page save, delete, sort, trash or restore: the static files that depended on that page are deleted, and only those. See *What a save deletes* below.
- Single field save: the same. Inline admin edits and `$page->setAndSave()` never reach a page save. Can be switched off in the module settings.
- Fieldgroup save, new template, template given another fieldgroup: all previously generated static files will be deleted. See *Narrow finds by the fields they use*.
- Expiry time reached: the static file is deleted, see *Expiry*.
- Config save of a listed module: all previously generated static files will be deleted. Modules like SettingsFactory keep site-wide settings in their own module config data and save no page, so a page save event never happens even though the settings end up in the markup. List such modules in the *Wipe on config save of these modules* setting, one class name per line.
- Tracked file change: all previously generated static files will be deleted. Editing a template or an asset file fires no ProcessWire event at all, so nothing would tell the module its static files went stale. The patterns listed in the *Track changes in these paths* setting are checked on admin requests (and, with *Track changes on front-end requests*, after front-end requests PHP handles) by file name, modification time and size; file contents are never read. `*` matches inside one path segment, `**` matches any number of segments including none.

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
  Before and after a move or a template change. A new page always counts (`Pages::added`), also
  when ProcessWire reports no change for it, and an unpublished one too. With *Narrow finds by
  the fields they use* checked, only a find that filters or sorts on what the save changed;
- for a rename or a move, the files of every descendant too, their urls changed.

An unpublished page that stays unpublished is shown nowhere, so editing a draft deletes nothing.
A save that changes nothing deletes nothing.

Everything is deleted when:

- a language page is saved, a page of a template listed in *Wipe everything on save of these
  templates* is saved, or a field listed in *Wipe everything on change of these fields* changes;
- a page with children is trashed or restored: ProcessWire sets the children's status in SQL,
  with no save and no hook;
- a renamed or moved page has more than 5000 descendants;
- a fieldgroup is saved (the admin saves one when the fields of a template are added, removed or
  reordered, when a template is created or renamed, and when a field is added to templates from
  the field editor), a template is created, or a template is given another fieldgroup;
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
one queue row (`p:<container id>`), one change of the generation stamp, one drain. Creating a
listed page whose template carries a repeater field creates such containers, which are new pages
of a template that is not listed: their row holds `p:<container id>` for each and `x:s`. Such a drain
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

## How a save finds the files

Each static file is recorded with tags. A save queues tags of its own, and the drain deletes every
file carrying one of them, and every file tagged `*`.

| Tag | Recorded on a file when its render | Queued by a save of a page |
|---|---|---|
| `p:<id>` | loaded page `<id>`; the page itself and its ancestors always | its own; of every descendant on a rename or a move |
| `t:<id>`, `par:<id>`, `tree:<id>` | ran a find limited to template `<id>`, to parent `<id>`, or to pages below `<id>` | of its template, its parent and every ancestor, before and after the save |
| `any` | ran a find with no such limit, or (with *Narrow finds*) a find the module does not understand | with the listing tags |
| `*` | had more than 5000 tags, or the file was adopted, or a delete of it failed | never: every drain takes these files |
| `<scope>/<what>` | ran a find, with *Narrow finds* checked: its scope and each thing it filters or sorts on | every scope of the page times every what of the save |
| `x:<what>` | ran a find, with *Narrow finds* checked, reaching other pages through a subfield (`author.name`) | every what of the save but `m` |
| `e:<time>` | is to expire, see *Expiry* | never: `expire()` takes these files |

A save queues the listing tags (`t:`, `par:`, `tree:`, `any`) and the `<scope>/<what>` tags for
a page that is published and not in the trash, for one the save published, unpublished, trashed,
restored, moved or sorted, and for a new page; never for a page of a system template (users,
roles, the admin). The `x:` tags it queues for the same pages, system templates included: a user
page is what an author field (`author.name`) reaches.

The grammar of the newer tags:

- scope: `t:<template id>`, `par:<parent id>`, `tree:<ancestor id>`, or `*` for no limit.
- what: `f:<field id>` for a custom field; `s` for a structural change (the page was created,
  deleted, trashed or restored, or its status, parent or template changed); `m`, queued by every
  save, for a find on `modified`; `n:<name>` for any other property, lower-cased (`n:name`,
  `n:sort`, `n:created`), or `n:` and the first 16 hex digits of its md5 when the name is longer
  than 22 characters or holds anything but `a-z`, `0-9` and `_`. One function names a change and a
  selector field alike: `name1012` counts as `name` and `status1012` as `status`, `parent_id` and
  `templates_id` as `s`, `modified_users_id` as `m`, and in a selector `path` and `url` as
  `n:name`. Every tag fits the 40 characters of the tag column, `tree:4294967295/n:<22>` too.
- foreign: `x:f:<field id>`, `x:n:<name>`, `x:s`: something about another page the find reaches
  through a subfield.

A save of a post (template P, parent 7, below the home page 1) changing `body` and `date` queues
`p:<id>`, the listing tags `any`, `t:P`, `par:7`, `tree:7`, `tree:1`, and
`{t:P, par:7, tree:7, tree:1, *} × {f:<body>, f:<date>, m}`, `x:f:<body>`, `x:f:<date>`.

### Narrow finds by the fields they use

Unchecked (the default), a find is recorded by its scope alone: a find limited to the template
`post` is `t:P`, and any save of any post deletes its file, whatever the save changed; a find with
no limit is `any`, deleted by any save of nearly any page. Checked (`narrowFinds`), a find is
recorded by its scope and by what it filters and sorts on, and a save deletes its file only when it
changed one of those, or created, deleted, trashed, restored or moved a page, or changed its status
or template. A page the render showed was loaded, so its `p:` tag still deletes the file on any
save of it.

How a find is read:

- A specific find (`id=1234`) records nothing, as unchecked.
- The scope is the template, parent or ancestor the find is limited to, as unchecked. Else the
  templates carrying a field the find asks for a value (below). Else `*`.
- The whats: `s` always; then every field and property of the selector, whatever the operator,
  negated or not, empty value or not, each of an OR-field `a|b`; and every `sort=` value without
  its leading `-` (`random` gives nothing). `limit`, `start`, `include`, `check_access`,
  `get_total`, and `id` when not specific, give nothing. The properties understood are the page
  columns and their aliases: `name`, `status`, `parent`, `parent_id`, `template`, `templates_id`,
  `sort`, `sortfield`, `created`, `modified`, `published`, `created_users_id`,
  `modified_users_id`, `has_parent`, `path`, `url`, `num_children` (`numChildren`, `createdUser`,
  `modifiedUser`, `page.sort` and the like as the column).
- A subfield of a Page or PageTable field, besides the field's own `f:<id>`: `id` and `count`
  give nothing more; a field gives `x:f:<id>`; `name`, `sort`, `created`, `published` give
  `x:n:<name>`; `status`, `template`, `parent` give `x:s`; anything else, `modified` included,
  makes the find `any`. A subfield of any other field (a repeater's `rep.title`) counts as the
  field: a repeater item's save queues `f:<repeater field>` for its owner. `parent.<field>` gives
  `x:f:<id>`, `children.<field>` gives `x:f:<id>` and `x:s`, `children.count` and `numChildren`
  give `x:s`. A third segment (`author.parent.title`) makes the find `any`, and so does a
  subfield of any other property (`createdUser.name`).
- Anything else makes the find `any`: a value holding a sub-selector `[...]`, an OR-group
  `(...)`, `owner`, `references`, `referencing`, `template.<x>`, `parent.<x>` that is no field,
  another property (`child`, `parents`, `count`), an unknown name.
- More than 64 tags for one find: its scope becomes `*`; still more than 64, the find is `any`.

**Templates carrying a field.** A find `F=value`, `F%=value`, `F*=value` or `F~=value`, not
negated, every value non-empty, `F` a custom field without a subfield, is recorded for the
templates carrying `F`: ProcessWire joins the field's table, so a page whose template lacks `F`
never matches. That holds for the core fieldtypes Text, Textarea, PageTitle, Email, URL, Integer,
Float, Decimal, Checkbox, Datetime, Page and Options, by their exact class; their language
variants, Toggle and every other fieldtype give `*`. Empty is what the fieldtype calls empty:
`featured=0` of a checkbox also matches pages without the field, and gives `*`. More than 20
templates give `*`; of several such selectors the smallest list counts; an OR-field `a|b` gives
none. Since a field added to a template moves its pages into such a scope, every fieldgroup save,
every new template and every template given another fieldgroup deletes every static file, box
checked or not.

Examples (A: the templates carrying the checkbox `featured`; P: the template `post`, with the
date field `date`; `author` a Page field):

| Find | File tags |
|---|---|
| `featured=1` | `t:A/f:<featured>`, `t:A/s` |
| `featured!=1`, `featured=''` | `*/f:<featured>`, `*/s` |
| `template=post, sort=-date, limit=10` | `t:P/f:<date>`, `t:P/s` |
| `template=post, author.name=Bob` | `t:P/f:<author>`, `t:P/s`, `x:n:name` |
| `parent=7, children.count>0` | `par:7/s`, `x:s` |
| `sort=-modified, limit=5` | `*/m`, `*/s` |
| `title%=foo, body=[x=1]` | `any` |
| `title\|body%=foo` | `*/f:<title>`, `*/f:<body>`, `*/s` |
| `!title=foo` | `*/f:<title>`, `*/s` |
| `template=post, sort=random` | `t:P/s` |
| `id=1234` | nothing |
| `(title=x), (body=y)` | `any` |
| `path^=/news/` | `*/n:name`, `*/s` |
| `author.modified>0` | `any` |
| `numChildren>0` | `*/s`, `x:s` |
| `children.title=x` | `*/s`, `x:f:<title>`, `x:s` |
| `template.name=x` | `any` |

The box is unchecked by default because it trades a sure delete for a narrower one. Unchecked, any
save in a find's scope deletes its file; checked, the file also stays after saves the module judges
unrelated, and that judgement is only as good as its reading of every selector the site's templates
run, while a stale copy is served to everybody until something else deletes it. The index also grows: a find records up to 64 tags instead of one. Check it where
saves of a busy template (a counter, a stock level) delete listings that never show the changed
field. Files recorded before the box changed keep their tags until they are deleted; *Force static
pages deletion on module save* on the same save starts afresh.

### Finds the module does not understand

The settings form shows, under *Indexed static files*, how many files the index records, how
many of them carry `any` (a find not understood, or with the box unchecked a find with no limit)
and how many `*` (no dependencies recorded): a save of nearly any page deletes those, whatever it
changed. The numbers need the index tables and are counted only when the form is built. With *Log
skipped pages* on, writing such a file logs each selector that fell back to `any`, once per
selector: `any <path>: a find this module does not understand, selector "<selector>"`.

## Expiry

Output that changes with time rather than with a save (a date, opening hours, an event that has
passed) can be given a time after which its static file is deleted:

- *Maximum age* (`maxAge`): one `<template name> <seconds>` per line, `*` for every template,
  `* 86400` and `event 3600` say. A template's own line beats `*`; of two lines for one template
  the smaller counts; names match whatever their letter case. A line that is not a name and a
  whole number of seconds above 0 is ignored.
- `expireAt()`, from template code, for the render running:

  ```php
  $modules->get('StaticPages')->expireAt(strtotime('tomorrow'));
  ```

  Several calls: the earliest time counts, and so does *Maximum age* when it comes earlier. A time
  not in the future keeps the render out of the cache (`expireAt() asked for a time not in the
  future`); one past 9999999999 sets no expiry.

The time is recorded with the file as the tag `e:<10 digit unix time>`, and the earliest of them
in `site/assets/cache/StaticPages/expire.next`. Every kind of copy expires alike: `index.xml`,
`index.txt` and a root file's copy too (its dot backup stays). Without the index tables a render
with an expiry time is not cached (`no index tables: a render with an expiry time is not cached
with the manifest`). Files recorded before *Maximum age* changed keep their time.

Static hits run no PHP, so nothing notices a time passing while every request is answered from a
copy. After the response of any request PHP handles (a page not cached, a form post, an admin
request), once the time in `expire.next` has passed, `expire()` deletes the files due: until then
an expired copy is still served. Such a request costs one small file read otherwise, and no
query. For exact times, a cron job calls `drain()`, which processes the queue and then always
runs `expire()`; with it running every minute a file is at most a minute late:

```php
<?php
// expire-static.php, outside the web root
include '/var/www/example.com/index.php';
$modules->get('StaticPages')->drain();
```

```
* * * * * php /var/www/expire-static.php
```

A CLI run also checks the root `.htaccess`, see *When the module writes it*. `expire()` takes the
drain lock, deletes through every check a delete goes through, and takes the files tagged `*`
along, as every drain does; it changes no generation stamp and queues nothing. Files due that it
can not delete (a base directory without its marker) are tried again a minute later, not on
every request. Uninstalling deletes `expire.next`.

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

## XML and plain text

With *Cache XML and plain text responses* checked (it is off by default) a page answering XML or
plain text is cached too: XML as `index.xml`, plain text as `index.txt`, where its `index.html`
would be. The names are *Static file name* with its extension replaced. Unchecked, such a response
is not cached at all.

There is nothing to map. The type of a render is, in this order:

- what the response's last `Content-Type` header says, when the template sends one
  (`header('Content-Type: application/rss+xml')`);
- else what the template's *Content-Type* setting says (*Templates > Files*): a key of
  `$config->contentTypes` such as `xml` or `txt`, or a MIME type written out;
- else HTML.

`text/html` is HTML, `text/plain` is plain text, and `application/xml`, `text/xml` and every
`…+xml` type but `application/xhtml+xml` (`application/rss+xml`, `application/atom+xml`) are XML.
Anything else, JSON included, is never cached. When the setting and the header both say something
and disagree, the render is not cached either. The web server serves the copy by its extension:
`index.xml` goes out as `application/xml`, also for a feed PHP sent as `application/rss+xml`.

The pages and their files:

- a page, `/sitemap/`: `sitemap/index.xml`, served for `/sitemap/`;
- a page with URL segments, `/feed/rss/`: `feed/rss/index.xml`;
- the home page: never, the site root holds no `index.xml` or `index.txt`;
- a page listed in *Replace these root files with pages*: one file in the web root, see
  *robots.txt and sitemap.xml* below.

An XML or plain text copy is never written on a site without the index tables (the manifest knows
HTML copies only), and never over a file the index does not record, a hand-made `index.xml` say.
*CSRF token tags* are not stripped from it, they are HTML; the `fileContent` hook sees it as any
render. See *Why is nothing being generated* for the log lines.

*Non-static page templates* wins: a page of a listed template is never cached, whatever its type.
A settings save with the box checked names the listed templates whose *Content-Type* setting says
XML or plain text.

Checking or unchecking the box deletes every static copy, when the settings are saved, *Dry run*
checked or not. Turned on, a response cached as `index.html` by its header would stay next to its
new `index.xml`; turned off, a directory holding an `index.xml` would no longer count as a page's
copy and its files would be protected from every wipe (see *Non-static directories*). A copy goes by
its record whatever the box says. Unchecked, the names a scan matches, the protected directories
and the StaticPages block are those of 0.10.0.

Checked, the StaticPages block gets a rule for each of the two names after the HTML one: a
directory holding both an `index.html` and an `index.xml` is answered from the `index.html`, one
holding an `index.xml` and an `index.txt` from the `index.xml`. A site that keeps its own rules
copies these from the block on the settings form; for nginx see *Without .htaccess*.

## robots.txt and sitemap.xml

*Replace these root files with pages* lets a page answer for a file in the web root: one line per
file, the file name and the page's path, `robots.txt /robots/` or `sitemap.xml /sitemap/`. Without
a path the page is the one named like the file, `/sitemap.xml/`. A name is lower case, ends in
`.xml` or `.txt`, is no path and no dot file, and is neither *Static file name* nor one of its
`index.xml`, `index.txt` names. The list works only while *Cache XML and plain text responses* is
checked. A settings save warns about every line it ignores, every path that is no page, and every
line while that box is unchecked.

While the box is checked, the StaticPages block hands a request for `/robots.txt` to the page as
long as no file of that name exists:

```apache
# The root file robots.txt, from the page /robots/ while it is no existing file.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^robots\.txt$ index.php?it=/robots/ [L,QSA]
```

The page's render, for a guest's `/robots.txt` or `/robots/`, is written to `robots.txt` in the web
root, never to `robots/index.txt`, and only when its type is the one the name ends in: a plain
text render for `robots.txt`, an XML one for `sitemap.xml`. From then on the web server serves the
file to every request for `/robots.txt`, also with a query string, a `POST` or the login cookie, as
it serves any existing file. The copy is recorded as any copy is: a save of a page it depends on
deletes it, and the next request renders it again.

**The take-over.** A `robots.txt` made by hand is in the way. A settings save, from an admin request
or a CLI run, with the box checked and the line listed, renames it to `.robots.txt`, its dot
backup, and the page's copy takes its place. Only when the index tables exist, when the file is
no directory, when no `.robots.txt` exists (a backup is never overwritten), and when the root
`.htaccess` hands `/robots.txt` to the page: the StaticPages block as the settings make it, or the
hand-written route below. Otherwise the file stays where it is and the save warns why.
ProcessWire's `.htaccess` refuses every path that starts with a dot, so `/.robots.txt` answers 403;
on a server without that rule (nginx without its equivalent) anybody can fetch it.

**When the original comes back.** A save that removes the line, a save that unchecks the box, and
uninstalling the module delete the module's copy by its record and rename `.robots.txt` back to
`robots.txt`. A file `robots.txt` in the way stops the rename, and the save warns. A wipe, forced
or not, deletes the copy and renames nothing: the original stays `.robots.txt`, and the page
answers until it is cached again. A save that only changes the line's page path deletes the copy
and renames nothing either. With *Dry run* checked a save still deletes the copy, and holds back
and logs the renames. A `robots.txt` the index does not record is never written over or deleted.

**A site that keeps its own rules** (*Keep them*, or no block at all) adds the route itself, above
the `# PW-PAGENAME` line, one pair of lines per file, and the take-over waits for it. The save's
warning names the lines:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^robots\.txt$ index.php?it=/robots/ [L,QSA]
```

## Static base directory and static files per host

Without a base directory a page's copy lies where its URL points, in the web root: `/about/` is
`about/index.html`, next to `site/`, `wire/` and the site's own directories. *Static base
directory* (`staticBaseDir`, empty by default) puts every copy below one directory instead,
`site/assets/static` say: `/about/` is then `site/assets/static/about/index.html`, the home page
`site/assets/static/index.html`, a file of *Replace these root files with pages*
`site/assets/static/robots.txt`. Choose it when the web root should hold nothing the module
writes (a web root under git or rebuilt by a deploy, page names that collide with directories of
the web root), and for *Static files per host*, which needs it.

The value is a path relative to the web root: plain names (letters, digits, `.`, `_`, `-`, no
leading dot) separated by `/`, no `.` or `..`, no absolute path. It has to lie below the web root,
also through links, and may not be the web root itself. A directory the stock `.htaccess` denies
to visitors is refused too, as every copy in it would answer visitors 403 where ProcessWire serves
the page: `site/assets/cache`, `logs`, `backups`, `sessions`, `config`, `database`, `install`
and `tmp` and anything below them, a directory below `site/assets` whose name starts with `-`,
`site/install`, `site/classes`, `site/templates`, `site/templates-admin`, `wire/templates-admin`
and `site-default`, and the names the stock rules block, such as `htaccess.*`, `*.php` or `*.bak`
below `site/` or `wire/` and `*.inc` below `wire/core/`. `site-<name>` counts as `site`, and
letter case does not count. `site/assets/static` works. A refused value is named in a warning on
the save and in the `static-pages` log, and the copies stay in the web root.

**The marker.** The module writes into the directory and deletes from it only while it holds the
marker file `.staticpages`. The settings save creates a missing directory with the marker, or marks
an empty one, *Dry run* checked or not; a render creates a still missing one the same way. A
directory that already holds files and no marker is never marked: nothing is written into it, no
wipe deletes anything from it, and the save warns. Name a new or an empty directory, and leave the
marker where it is.

**Changing it.** The settings save that sets, changes or clears the directory deletes every copy
under the old place, *Dry run* checked or not; the new place fills as pages are requested. With
*Force static pages deletion on module save* checked on that same save, the scan walks the old
layout, the one the request loaded, not the new one. The old directory and its `.staticpages`
stay where they are; uninstalling leaves the base directory and its marker as well. Remove them by
hand. Under a tracked path (*Track changes in these paths*) covering them, a marker left behind
counts as a changed file on the first check after that save: one more wipe of everything, then
nothing.

Below the base directory:

- *Non-static directories* does not apply. The name check, the marker and the check that a path
  lies inside the base directory do.
- a full scan walks the base directory's subdirectories. Its top level counts as the root level: the
  home page's `index.html` there, no `index.xml` or `index.txt`.
- tracked paths skip everything below it while it holds the marker: copies, root file copies and the
  marker are no source files. A directory named by mistake, without the marker, stays tracked like
  any other.

**Root files.** With a base directory the copy of a file of *Replace these root files with pages*
is written into it, and the web root's own `robots.txt` is never taken over, renamed or deleted,
nor served while the line is listed and the box is checked: the block answers `/robots.txt` from
the copy, and any other request for it from the page. The save that switches to a base directory
gives the web root's dot backups back (`.robots.txt` to `robots.txt`). With *Dry run* checked on
that save the rename is held back and logged, and the backup then stays `.robots.txt` until the
site is back without a base directory: in base directory mode nothing renames a dot backup,
uninstalling included.

**Copies left in the web root** are not served once the block serves the base directory: any
existing directory of the web root goes to ProcessWire, unless it holds an `index.php` or an
`index.htm` of its own, which keeps the stock answers. That holds for a directory made by hand
with an `index.html`, `index.xml` or `index.txt` too; give it the rule of *Directories made by
hand*.

**The block.** With `site/assets/static` the StaticPages block reads:

```apache
# BEGIN StaticPages
# Generated by the StaticPages module and replaced on every update: change its settings, not these lines.
# The home page, from its copy in site/assets/static/.
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=
RewriteCond %{REQUEST_FILENAME}/site/assets/static/index\.html -f
RewriteRule ^$ site/assets/static/index\.html [L]
# A page, from its index.html copy in site/assets/static/, unless its directory in the web root holds an index file of its own.
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteCond %{REQUEST_FILENAME}/index.htm !-f
RewriteCond $1::%{REQUEST_FILENAME}%{PATH_INFO} ^(.*)::(.*)\1$
RewriteCond %2site/assets/static/$1index\.html -f
RewriteRule ^(.+/)$ site/assets/static/$1index\.html [L]
# Any other request for an existing directory, with its trailing slash, a URL the stock 16A condition accepts and without an index file of its own, goes to ProcessWire.
RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"
RewriteCond %{REQUEST_FILENAME} -d
RewriteCond %{REQUEST_FILENAME}/index.php !-f
RewriteCond %{REQUEST_FILENAME}/index.htm !-f
RewriteRule ^(.+/)$ index.php?it=$1 [L,QSA]
# END StaticPages
```

The site root on disk is taken from the request (`$1::%{REQUEST_FILENAME}%{PATH_INFO}`), so no path
of the server goes into the block. With *Cache XML and plain text responses* checked, each typed
name gets a rule of its own after the HTML one, and a root file line gets two: the copy for a
guest's `GET` or `HEAD` without a query string, the page for everything else. A copy is also a file
under its own URL (`/site/assets/static/about/index.html`), served to any request like any file;
the directory `/site/assets/static/about/` goes to ProcessWire and the marker answers 403. The block
checks only how the setting is written: a value refused for another reason (a link leading out, a
file in its place) still gets the rules of the base directory while the copies go to the web root,
where they are not served.

**Copy paths the stock rules deny.** After the block's rewrite Apache tests the path of the copy,
`/<base directory>[/<host>]<page path>index.html`, against the stock deny rules of section 15A.
Where one of them matches, every visitor would get 403 from the copy where ProcessWire serves the
page, so with a base directory such a render is not cached and ProcessWire answers it: `the stock
.htaccess denies its copy path <path> to visitors (403 Forbidden)`. Below `site/assets/static` that
is a page or URL segment named like `-x`, `feed.php`, `x.phtml`, `a.php.b` or `x.bak`; a host
directory named like `docs.php.example.com` (every page of that host); a *Static file name* such as
`index.bak` (every page). Letter case does not count: a segment `Site-Default` is skipped too,
although the stock rule, written for lower case, would serve it. Without a base directory nothing is
tested, as before: a page below `/site-<name>/templates/` or `/site-<name>/templates-admin/` gets
403 from its `index.html` copy once it exists, where ProcessWire served the first request (its
`index.xml` copy is served); list its template in *Non-static page templates*.

### Static files per host

*Static files per host* (`staticHostKey`, off) gives each host its own copies, one directory down:
`site/assets/static/example.com/about/index.html`, `site/assets/static/www.example.com/about/index.html`,
and a root file `site/assets/static/example.com/robots.txt`. It needs a *Static base directory* and
changes nothing without one. Choose it when one ProcessWire answers to several host names and a
page's markup differs by host (absolute links, a canonical URL, a setting chosen by host): without
it, the copy written for one host is served to all of them. Below the host's directory the copy is
still placed by `$page->path`: on a site that chooses the language by host name, a page whose path
carries the language segment (`/en/offers/` for `/offers/`) is written to
`<base>/<host>/en/offers/index.html`, while the block looks for the copy of `/offers/` at
`<base>/<host>/offers/index.html`. Read from the generated rules, not tried on a server: a request
for `/offers/` on that host is then not answered from that copy, nor from another host's directory,
and goes to ProcessWire; see *Multilingual sites*.

The directory is the host in lower case with `:` as `_` (`example.com_8080`). A render is cached only
for a host listed in `$config->httpHosts`, and only when the request's `Host` header is that very host,
`$config->httpHost` (ProcessWire takes the first listed host for a header it does not know);
otherwise it is skipped with `Static files per host: <why>`. A host that is no plain name (`[::1]`)
gets no copy. Checking or unchecking the box deletes every copy on that save, as a change of the
base directory does; checked without a base directory it changes nothing and deletes nothing.

The block then holds the home page and page rules once per host of `$config->httpHosts`, each with
a first condition on the host, `RewriteCond %{HTTP_HOST} ^www\.example\.com$ [NC]`: letter case does
not count, a port only as listed (`example.com:8080` is not `example.com`). Any other host gets no
copy and goes to ProcessWire. A change of `$config->httpHosts` changes the block, which the next
settings save or superuser admin request writes again.

## Cache only these hosts

*Cache only these hosts* (`cacheHosts`, empty) names the hosts whose renders are cached, one per
line, `example.com` or `example.com:8080`; empty, every host is, as before. Choose it when one
ProcessWire answers to host names whose visitors should not get a copy, or whose renders should not
become one: a staging name, a bare IP address, a second domain. It works with or without a base
directory and with or without *Static files per host*. Both sides read it:

- Writing: a render, HTML, XML or plain text, is cached only when `$config->httpHost` is listed,
  letter case aside; any other is skipped with `the host "<host>" ($config->httpHost) is not listed
  in Cache only these hosts`. It is asked before *Static files per host*: an unlisted host gets no
  directory either.
- Serving: every rule of the block that answers from a copy gets one more condition, after the
  cookie one:

  ```apache
  RewriteCond %{HTTP_HOST} ^(?:example\.com|www\.example\.com)$ [NC]
  ```

  Letter case does not count, a port only as listed (`example.com:8080` is not `example.com`). A
  request from any other host goes to ProcessWire. With *Cache XML and plain text responses*
  checked and no base directory, a file of *Replace these root files with pages* gets one more
  rule ahead of its own, sending a request for it from an unlisted host to its page: the stock
  rules would serve that host the copy in the web root.

Only lines that are a plain host name (letters, digits, `-` and dots, with an optional `:port`) go
into the block; the others are left out, and the settings form says so under the block. A list
holding no such line has no copy served at all; without a base directory a root file's request
then goes to its page from every host. A save that changes the list (trimmed, lower-cased, each
host once) deletes every copy, *Dry run* checked or not: copies written for a host that is no
longer listed, or for any host while the list was empty, would otherwise be served to the listed
ones. The block changes with the list, and the save writes it as it writes any change, see *When
the module writes it*.

The nginx examples under *Without .htaccess* do not read this setting: there a copy is served to
every host the `server` block answers.

## Pages without a trailing slash

A template can drop the trailing slash from its pages' URLs (*Should page URLs end with a slash?*
on its *URLs* tab). Such a page, and every page below it, is not cached, in every mode, nor is a
render with URL segments whose template says no to *Should URL segments end with a trailing slash?*:

- `its template <name> has no trailing slash: a copy dir at its URL would loop between the web server's slash redirect and ProcessWire's`;
- `it is below <path>, whose template <name> has no trailing slash: a copy dir at that URL would loop between the web server's slash redirect and ProcessWire's`;
- `its template <name> has no trailing slash after URL segments: a copy dir at that URL would loop between the web server's slash redirect and ProcessWire's`.

0.3.1 to 0.11.0 cached them. A copy makes a directory at the page's own URL: `/a/b` was written as
`a/b/index.html` (on a site with LanguageSupportPageNames as `a/bindex.html` beside an empty
directory `a/b/`), a page below it as `a/b/c/index.html`. Once that directory existed, Apache's
mod_dir answered `/a/b` with a 301 to `/a/b/`, before any rewrite rule, and ProcessWire answered
`/a/b/` with a 301 back: a redirect loop for every visitor, `GET`, `HEAD`, `POST`, with a query
string or the login cookie alike.

Copies written by older versions stay until a save of a page they depend on or a wipe deletes
them, and the loop stays with them: wipe once after the update on a site with such templates. A
recorded `a/bindex.html` (also `…index.xml`, `…index.txt`) is deleted by its record like any copy,
with its directory `a/b/` when that is empty. One that neither the index nor the manifest records
stays: look for a file whose name ends in `index.html` without being `index.html`, next to an empty
directory named like the rest of it, and delete both by hand.

A template switched to no trailing slash keeps its pages' copies and directories, and they loop
again, until a wipe: wipe after switching. Switched back, there is nothing to delete. A page listed
in *Replace these root files with pages* is written as its root file whatever its template says,
below a page without a trailing slash too: that makes no directory. The home page never counts as
a page without a trailing slash, as in ProcessWire itself: on a site with LanguageSupportPageNames
whose home template has the slash off, the home page of a non-default language has the path
`/<lang>`, and it and that language's pages are cached as before, the skip does not cover them.

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
- The request is an ajax request.
- The response is XML or plain text and *Cache XML and plain text responses* is off. The log
  says `content type is '<key>'` when the template's *Content-Type* setting says so, and
  `the response is sent as <MIME type>` when only the response's `Content-Type` header does.
  Up to 0.10.0 any response typed by its header alone (XML, plain text, JSON) was written as
  `index.html` and served as HTML.
- The response is neither HTML, XML nor plain text, switch on or off: JSON, a PDF, an image
  (`content type is '<key>'`, `the response is sent as <MIME type>`).
- The template's *Content-Type* setting and the response's `Content-Type` header disagree:
  `the template says <key>, the response is sent as <MIME type>`. Which one is right, the
  module can not tell.
- An XML or plain text response for the home page, the root level holds HTML only:
  `a non-html copy of the home page is not written`.
- An XML or plain text response on a site without the index tables, the manifest knows HTML
  copies only: `no index tables: only html is cached with the manifest`.
- An XML or plain text copy would replace a file the index does not record, a hand-made
  `index.xml` say: `a file the index does not know is in the way: <path>`.
- The page is listed in *Replace these root files with pages*, and the render's type is not the
  one the listed file name ends in:
  `the page is listed in Replace these root files with pages as <file names>, a <type> render is not written`.
- The page's directory can not be created, for want of permissions or with a file of that name
  in the way: `could not create <dir>`.
- The page's template has no trailing slash, the page is below such a page, or the render has URL
  segments its template wants without a trailing slash: `its template <name> has no trailing
  slash: …`, `it is below <path>, whose template <name> has no trailing slash: …`, `its template
  <name> has no trailing slash after URL segments: …`. See *Pages without a trailing slash*.
- *Static base directory* is set and holds files but no marker:
  `the Static base directory <dir> holds no marker file .staticpages, nothing is written into it`.
- With *Static base directory* set, the stock `.htaccess` would answer the copy with 403:
  `the stock .htaccess denies its copy path <path> to visitors (403 Forbidden)`. See *Copy paths
  the stock rules deny*.
- *Cache only these hosts* does not list the host: `the host "<host>" ($config->httpHost) is not
  listed in Cache only these hosts`. See *Cache only these hosts*.
- `expireAt()` asked for a time not in the future: `expireAt() asked for a time not in the
  future`. A render with an expiry time (`expireAt()` or *Maximum age*) on a site without the index
  tables: `no index tables: a render with an expiry time is not cached with the manifest`. See
  *Expiry*.
- *Static files per host* is checked and the request's host is not the one to cache for:
  `Static files per host: the request's host "<host>" is not $config->httpHost "<host>"`, `…: the
  host "<host>" is not listed in $config->httpHosts`, `…: the host "<host>" is no plain host
  name`, `…: the host is empty`.
- The stock `.htaccess` refuses the URL itself, whatever the module does: a page named like
  `x.bak` or `x.sql` (`/x.bak/`, `/x.sql/`, its sections 5A and 5B), and `/license.txt` (15A),
  answer 403. ProcessWire never sees the request, so there is nothing to cache and nothing to log.
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
  `a/b/index.html`. With *Cache XML and plain text responses* checked, also from `a/b/index.xml`
  and `a/b/index.txt`, and a file of *Replace these root files with pages* goes to its page while
  it does not exist: see *XML and plain text* and *robots.txt and sitemap.xml*.
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
file name*, *Cache XML and plain text responses*, *Replace these root files with pages*, *Static
base directory*, *Static files per host* with `$config->httpHosts`, *Cache only these hosts*, and
the session settings are the values it is made from, and the settings form shows it under *The StaticPages block*. For
the block with a base directory see *Static base directory and static files per host*.

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
- with *Cache XML and plain text responses* checked, one holding an `index.xml` or an `index.txt`
  and no `index.html` is taken for a page's copy too: a guest's `GET` or `HEAD` without a query
  string gets that file, where the stock rules answered 403, and everything else goes to
  ProcessWire.

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
backup, and deletes the static copies the index (or the manifest) records. It renames the
originals of *Replace these root files with pages* back (`.robots.txt` to `robots.txt`), but not
with a *Static base directory* set: then nothing is renamed. Last, it drops the tables. Copies
nothing recorded stay where they are. The backups stay too, and so do the base directory and its
marker `.staticpages`.

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
  # With "Cache XML and plain text responses" on: a page from its index.xml, then its index.txt copy.
  if (-f $document_root${uri}index.xml) { set $sp "${sp}m"; }
  if ($sp = "1/dm") { rewrite ^ ${uri}index.xml last; }
  if (-f $document_root${uri}index.txt) { set $sp "${sp}e"; }
  if ($sp = "1/de") { rewrite ^ ${uri}index.txt last; }
  if (-f $document_root${uri}index.htm) { set $sp "${sp}t"; }
  # Any other existing directory, with its trailing slash, without an index.php or index.htm of its own, goes to ProcessWire.
  if ($sp ~ "^1?/f?dm?e?$") { rewrite ^/(.*)$ /index.php?it=$1 last; }
  # With "Cache XML and plain text responses" on, one line per line of "Replace these root files
  # with pages": the root file robots.txt, from the page /robots/ while it is no existing file.
  location = /robots.txt { try_files $uri /index.php?it=/robots/&$args; }
```

`$sp` collects what is known about the request: `1` for a guest's `GET` or `HEAD` without a query
string, `/` for a trailing slash, `f` and `p` for an `index.html` and an `index.php` in that
directory, `h` for the home page, `d` for an existing directory, `x` for a URL the stock 16A rule
refuses, `m` and `e` for an `index.xml` and an `index.txt`, and, after the copy rules, `t` for an
`index.htm` in that directory. With *Cache XML and plain text responses* unchecked, leave out the
four `m` and `e` lines and the `location` lines, as the block does. The `location` line hands
`/robots.txt` to its page while no such file exists, and serves the file once it does: one per
line of *Replace these root files with pages*, with that line's file name and page path. With
*Static file name* changed, replace `index.html`, `index.xml` and `index.txt` in it; with
`index.htm`, leave out the `t` line, as the block does. A directory made by hand needs a line of its own, as on Apache (see *Directories made
by hand* above): `if ($uri ~ "^/landing/") { set $sp "${sp}n"; }` after the `x` line.

With a *Static base directory*, `site/assets/static` here, the example is this instead:

```nginx
  # StaticPages with "Static base directory" site/assets/static: what the module's block does
  # for Apache then. In place of the example above, after the deny rules, before "location /".
  set $sp "";
  if ($request_method ~ "^(GET|HEAD)$") { set $sp "1"; }
  if ($args != "") { set $sp ""; }
  if ($http_cookie ~ "(^|;\s*)(wire_challenge|wires_challenge)=") { set $sp ""; }
  set $spb "/site/assets/static";
  if ($uri ~ "/$") { set $sp "${sp}/"; }
  if ($uri = "/") { set $sp "${sp}h"; }
  if (-f $document_root${uri}index.php) { set $sp "${sp}p"; }
  if (-f $document_root${uri}index.htm) { set $sp "${sp}t"; }
  if (-f $document_root$spb${uri}index.html) { set $sp "${sp}f"; }
  # The home page, from its copy in the base directory.
  if ($sp ~ "^1/hpt?f$") { rewrite ^ $spb/index.html last; }
  # A page, from its copy in the base directory, unless its directory in the web root holds an index.php or index.htm of its own.
  if ($sp = "1/f") { rewrite ^ $spb${uri}index.html last; }
  # With "Cache XML and plain text responses" on: a page from its index.xml, then its index.txt copy.
  if (-f $document_root$spb${uri}index.xml) { set $sp "${sp}m"; }
  if ($sp = "1/m") { rewrite ^ $spb${uri}index.xml last; }
  if (-f $document_root$spb${uri}index.txt) { set $sp "${sp}e"; }
  if ($sp = "1/e") { rewrite ^ $spb${uri}index.txt last; }
  # Any other existing directory of the web root, with its trailing slash, without an index.php or index.htm of its own, goes to ProcessWire.
  if (-d $request_filename) { set $sp "${sp}d"; }
  if ($uri !~ "^/~?[-_.a-zA-Z0-9/]*$") { set $sp "${sp}x"; }
  if ($sp ~ "^1?/f?m?e?d$") { rewrite ^/(.*)$ /index.php?it=$1 last; }
  # With "Cache XML and plain text responses" on, one location per line of "Replace these root
  # files with pages": robots.txt from its copy in the base directory, anything else for it from
  # the page /robots/. The web root's own robots.txt is not served.
  location = /robots.txt {
    if (-f $document_root$spb/robots.txt) { set $sp "${sp}c"; }
    if ($sp = "1c") { rewrite ^ $spb/robots.txt last; }
    rewrite ^ /index.php?it=/robots/ last;
  }
```

Here `f`, `m` and `e` stand for the copies in the base directory, `p` and `t` for an `index.php` and
an `index.htm` in the web root's directory of that URL, and `c` for the root file's copy. The web
root's own `robots.txt` is not served while the `location` is there, and a copy left in the web
root is not served either: its directory goes to ProcessWire. With *Static files per host*, replace
the `set $spb` line by these, one `if` per host of `$config->httpHosts`, a port written as listed
(`"^example\.com:8080$"`, its directory `/example.com_8080`):

```nginx
  # "Static files per host": one line per host of $config->httpHosts, lower case, ":" as "_".
  set $sph "";
  if ($http_host ~* "^example\.com$") { set $sph "/example.com"; }
  if ($http_host ~* "^www\.example\.com$") { set $sph "/www.example.com"; }
  if ($sph = "") { set $sp ""; }
  set $spb "/site/assets/static$sph";
```

Any other host then gets no copy and goes to ProcessWire. Unlike Apache, nginx answers a `POST` to a
copy's own URL (`/site/assets/static/about/index.html`) with 405, and it does not test the rewritten
path against its deny rules again.

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
`/api/` page next to `api/` endpoints) stays protected. A static copy is an `index.html` (*Static
file name*), and an `index.xml` or `index.txt` only while *Cache XML and plain text responses* is
checked. Saving the setting stores the list as shown,
and the save warns when it names a dir that only holds static copies: those copies would never be
wiped. Nothing is cached below a listed dir.

The default also leaves out a root dir no page is named like that holds nothing but static copies
and under which the index records a file: the dir the module makes for the home page rendered with
a URL segment, `<segment>/index.html`. Up to 0.11.0 the default took it in, and that copy was never
deleted by a save or a wipe again: it went stale. A dir nothing was recorded under stays protected
whatever it holds (a hand-made `landing/` with an `index.html`), and so does one holding anything
else; a record differing in letter case only does not count. A recorded copy of a page without a
trailing slash, `<x>index.html` (see *Pages without a trailing slash*), counts as a static copy
there too; an unrecorded file of that name keeps the dir protected. This lookup needs the index
tables and runs only without a *Static base directory*; below a base directory the list does not
apply at all.

The list is not what keeps a full disk scan out of a dir, see below.

## Full disk scans

A scan only runs when asked for (*Force static pages deletion on module save*, `wipeCaches(true)`)
or when there is neither index nor manifest. It deletes the home page's copy at the root and walks
only root dirs that are static copies of pages: named like a first level page in any language, and
holding nothing but static copies (and the temporary files a killed write left). Everything else is
left alone, listed or not, and the scan logs the unlisted dirs it left alone. Before 0.8.0 it
walked every root dir the list did not name, and the list is a snapshot from the last config save:
a dir added since (`vendor/`, a hand made landing page) lost every `index.html` and every empty dir
in it. Inside those dirs the scan deletes the files named `index.html`
(*Static file name*), and `index.xml` and `index.txt` only while *Cache XML and plain text
responses* is checked; at the root level the `index.html` alone. With a *Static base directory* the
scan walks the base directory's subdirs instead, its top level being the root level.

Before it scans, a forced scan deletes every file the index and the manifest record, read before
their rows and lines go, each through the same checks as any delete. Up to 0.11.0 it cleared the
records and only scanned, so a recorded copy outside the dirs it walks (the home page rendered with
a URL segment, `<segment>/index.html`) lost its record and stayed on disk, stale, where no later
save or wipe would find it. A recorded path in a protected dir, or with a name that is no static
copy's, still stays. A file of *Replace these root files with pages* goes by its index record the
same way: the scan deletes the module's `robots.txt` copy, never a hand-made one, and renames
nothing.

Nothing is ever deleted that `realpath()` does not put below the site root, or that is not named
like a static copy. With *Use shell commands* the scan is one `find` on Linux, macOS and BSD, or
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
the list under `paths` and `leftAlone`; a forced one lists each recorded copy and each file the
scan finds once. Run one on a copy of a site before trusting a forced wipe
there: it should list page `index.html` files and their dirs, nothing else (with *Cache XML and
plain text responses* checked, their `index.xml` and `index.txt` files and the module's copies of
*Replace these root files with pages* as well).

## Limits of path tracking

- The check runs on admin requests. With *Track changes on front-end requests* (`trackOnFrontend`, off by default) it also runs after the response of a front-end request PHP handles, at most once a minute, by a stamp of its own (`site/assets/cache/StaticPages/front.stamp`): a deployed template or asset then wipes the static files without anybody opening the admin. A change found there wipes everything after that same response and writes `Tracked path changed (front-end request): <patterns>` to the `static-pages` log; no notice is shown. A fully cached URL never reaches PHP, so on a site where every page is cached the check waits for the first request that is not: a form post, a query string, a page not cached yet, an admin request. A developer who edits files on such a site, with the box unchecked and the admin never opened, will not see the cache wiped.
- Checks are throttled to once every 10 seconds so that admin ajax does not walk the tracked directories on every keystroke. Between two checks an admin request costs one `stat()` of a stamp file; a check stats every tracked file, keep the patterns narrow. A front-end check walks the same files, at most once a minute, after the response has been sent.
- Only the modification time and the size are compared. An edit changing neither goes unnoticed.
- Symlinked directories inside a tracked path are not descended into. A symlinked base directory is fine.
- Static copies are never source files: a file named like one (`index.html`, `index.xml` and `index.txt` only while *Cache XML and plain text responses* is checked) is skipped anywhere, and so is everything below the *Static base directory* while it holds its marker `.staticpages`. A base directory without the marker stays tracked like any other directory. After a save that clears or changes the base directory, a tracked path covering the old one counts its marker, left behind, as a changed file once: one wipe of everything.
- A hash file that is missing or unreadable is treated as a first run: it is seeded, nothing is wiped. So a deploy that clears `site/assets/cache/` and changes templates in the same step will not wipe. Deploy scripts should ask for it directly:

```php
$modules->get('StaticPages')->checkTrackedPaths(true);  //check now, ignoring the throttle; deletes at script end
$modules->get('StaticPages')->wipeCaches();             //or just wipe every indexed file, right now
$modules->get('StaticPages')->wipeCaches(true);         //the same with a full disk scan
$modules->get('StaticPages')->wipeCaches(true, true);   //list what the scan would delete, delete nothing
$modules->get('StaticPages')->drain();                  //process the queue right now, then delete the files due to expire
```

`checkTrackedPaths(true)` stays the exact way for a deploy script: a front-end check waits for its
minute and for a request PHP handles.

## Warning

However tested and used on several websites, hosting platforms and environments, this mode should be considered as beta release. Use it at Your own risk. Since StaticPages may perform bulk files and folders deletion, it's strongly advised to make the full website backup before the first use.
