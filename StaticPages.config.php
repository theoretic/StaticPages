<?php
/*
Static pages module config
AT
28.07.26
*/

namespace ProcessWire;

$nonStaticTemplatesOptions = array();
$nonStaticTemplatesValue = array();

foreach( $this->wire()->templates as $template ){
	$nonStaticTemplatesOptions[$template->id] = $template->name;
	if( $template->name == 'admin' ) $nonStaticTemplatesValue[] = $template->id;
}

//No default here, and never a call to the module: core includes this file while it builds the
//module's info (refresh, install, clearing the info cache) and asking for the module then
//builds that info again from inside it, endlessly (0.6.3 and 0.8.0 ran out of memory; 0.8.0's
//`$modules->refreshing` guard missed installs). Empty means "the default list":
//nonStaticDirsArray() falls back to it, and hookConfigInputfields() fills it into the form.
$nonStaticDirsValue = '';

////

//Fieldsets are named with a leading "_": ProcessModule never stores such a name, and getAll()
//leaves wrappers out anyway, so no fieldset ever lands in the stored config. Fields find each
//other by getChildByName(), which looks inside fieldsets. The three fields at the bottom stay
//ungrouped on purpose: one shot actions and the main switch, the last thing before Submit.

$config = array(

			array(
				'name'					=> '_general',
				'type'					=> 'fieldset',
				'label'					=> $this->_('General'),
				'children'				=> array(

					array(
						'name'					=> 'priority',
						'type'					=> 'text',
						'label'					=> $this->_('Priority'),
						//'description'			=> $this->_('one name per line'),
						'notes'					=> $this->_('Should be higher than other modules have to transform their output.'),
						'required'				=> true,
						'columnWidth'			=> 100,
						'value'					=> StaticPages::priorityDefault,
					),

				),
			),

			array(
				'name'					=> '_static',
				'type'					=> 'fieldset',
				'label'					=> $this->_('Static'),
				'children'				=> array(

					array(
						'name'					=> 'newDirPermissions',
						'type'					=> 'text',
						'label'					=> $this->_('Permissions for every new static dir'),
						'description'			=> $this->_('UNIX string like 0755'),
						//'notes'					=> $this->_('Path relative to website root directory.'),
						'required'				=> true,
						'columnWidth'			=> 50,
						'value'					=> '0755',
					),

					array(
						'name'					=> 'staticFile',
						'type'					=> 'text',
						'label'					=> $this->_('Static file name'),
						//'description'			=> $this->_('normally index.html'),
						//'notes'					=> $this->_('Path relative to website root directory.'),
						'required'				=> true,
						'columnWidth'			=> 50,
						'value'					=> 'index.html',
					),

					array(
						'name'					=> 'cacheNonHtml',
						'type'					=> 'checkbox',
						'label'					=> $this->_('Cache XML and plain text responses'),
						'notes'					=> $this->_('The file type follows the template\'s Content-Type setting (Templates > Files) or the Content-Type header the template sends: XML is written as index.xml, plain text as index.txt (the static file name with its extension replaced), so a page /sitemap/ becomes sitemap/index.xml and /robots/ becomes robots/index.txt. There is nothing to map. A template listed in "Non-static page templates" is never cached, whatever its type. Off: such responses are not cached at all.'),
						'columnWidth'			=> 100,
					),

					array(
						'name'					=> 'rootFiles',
						'type'					=> 'textarea',
						'label'					=> $this->_('Replace these root files with pages'),
						'description'			=> $this->_('One "file name, page path" per line, e.g. "robots.txt /robots/" or "sitemap.xml /sitemap/". Without a path the page is the one named like the file (/sitemap.xml/).'),
						'notes'					=> $this->_('File names are lower case only, end in .xml or .txt, are no path and no dot file, and differ from the static file name and its typed names. Needs "Cache XML and plain text responses". When StaticPages takes a root file over, the original is kept as its dot backup, robots.txt as .robots.txt, sitemap.xml as .sitemap.xml; an existing backup is never overwritten. On a server without the stock rule that refuses dot paths (the .htaccess section blocking paths that start with a dot, or nginx\'s equivalent) /.robots.txt can be fetched by anybody. Once the copy exists the web server serves it to every request for the file, also with a query string or a POST.'),
						'columnWidth'			=> 100,
						'value'					=> '',
					),

				),
			),

			array(
				'name'					=> '_exclusions',
				'type'					=> 'fieldset',
				'label'					=> $this->_('Non-static and exclusions'),
				'children'				=> array(

					array(
						'name'					=> 'nonStaticDirs',
						'type'					=> 'textarea',
						'label'					=> $this->_('Non-static directories'),
						'description'			=> $this->_('one name per line'),
						'notes'					=> $this->_('These directories will be protected from deletion when static files and dirs are deleted.'),
						'required'				=> true,
						'columnWidth'			=> 50,
						'value'					=> $nonStaticDirsValue,
					),

					array(
						'name'					=> 'nonStaticTemplates',
						'type'					=> 'AsmSelect',
						'label'					=> $this->_('Non-static page templates'),
						//'description'			=> $this->_('one name per line'),
						'notes'					=> $this->_('Pages having these templates will have no static representation.'),
						'required'				=> true,
						'columnWidth'			=> 50,
						'options'				=> $nonStaticTemplatesOptions,
						'value'					=> $nonStaticTemplatesValue,
					),

					array(
						'name'					=> 'trackedPaths',
						'type'					=> 'textarea',
						'label'					=> $this->_('Track changes in these paths'),
						'description'			=> $this->_('one path pattern per line, relative to the website root'),
						'notes'					=> $this->_('Editing a template or an asset file fires no ProcessWire event, so the static files would silently stay stale. On admin page requests the files matching these patterns are checked by name, modification time and size, and the static files are deleted when anything changed. File contents are never read, so an edit changing neither the size nor the modification time goes unnoticed. "*" matches inside one path segment, "**" matches any number of segments including none, "?" matches one character. Matching is case insensitive. Absolute paths, drive letters and ".." are ignored. Anything under site/assets/cache, /logs, /sessions and /tmp is never tracked: ProcessWire and this module write there on ordinary requests and tracking it would wipe forever. Keep the patterns narrow, a line like "**/*" walks the whole website. Leave empty to switch tracking off.'),
						//'required'				=> true,
						'columnWidth'			=> 50,
						//kept on the module class so the two can not drift apart
						'value'					=> StaticPages::trackedPathsDefault,
					),

					array(
						'name'					=> 'noCacheCookies',
						'type'					=> 'textarea',
						'label'					=> $this->_('Never cache with these cookies'),
						'description'			=> $this->_('one cookie name per line, "*" and "?" as wildcards'),
						'notes'					=> $this->_('A guest request carrying one of these cookies is rendered but not cached: output that depends on a cookie (a currency, a region, a consent banner) would be frozen into the file everybody gets. Responses that set a cookie (ProcessWire\'s own session cookie aside) or send a Vary header on anything but Accept-Encoding are never cached either. Output that depends on Accept-Language without saying so can not be told apart: exclude its template.'),
						'columnWidth'			=> 50,
						'value'					=> '',
					),

				),
			),

			array(
				'name'					=> '_system',
				'type'					=> 'fieldset',
				'label'					=> $this->_('System'),
				'children'				=> array(

					array(
						'name'					=> 'csrfPatterns',
						'type'					=> 'textarea',
						'label'					=> $this->_('CSRF token tags'),
						'description'			=> $this->_('one regex per line, delimiters included, each matching one whole tag'),
						'notes'					=> $this->_('A CSRF token belongs to one session, and a static file is served to everybody. In every tag a line matches, the content and value attributes are emptied before the file is written, and the name attribute of an input. The default matches <meta name=csrf-token> with its -name twin and <input rel=csrf>, the markup @atispro/core 00-csrf.js fills in again. A line that is no valid regex stops all caching. Empty: nothing is stripped.'),
						'columnWidth'			=> 100,
						'value'					=> StaticPages::csrfPatternsDefault,
					),

					array(
						'name'					=> 'useShell',
						'type'					=> 'checkbox',
						'label'					=> $this->_('Use shell commands'),
						//'description'			=> $this->_('UNIX string like 0755'),
						'notes'					=> $this->_('Applies only to the full disk scan: a forced wipe, or a site with neither index tables nor manifest. Saves never scan, they remove the files the dependency index names. One find (Linux, macOS, BSD) or one PowerShell process (Windows) instead of the php walk; falls back to php when it can not run.'),
						//'required'				=> true,
						'columnWidth'			=> 33,
						//'value'					=> 1,
					),

					array(
						'name'					=> 'wipeDryRun',
						'type'					=> 'checkbox',
						'label'					=> $this->_('Dry run'),
						'notes'					=> $this->_('With "Force static pages deletion on module save" (at the bottom) checked: deletes nothing, and writes every file and dir the scan would delete to the static-pages log, with the root dirs it leaves alone. The same from code: $modules->get(\'StaticPages\')->wipeCaches(true, true). The same for the root .htaccess: a save with this box checked writes nothing to it, and what it would write goes to the log.'),
						'columnWidth'			=> 33,
					),

					array(
						'name'					=> 'logSkips',
						'type'					=> 'checkbox',
						'label'					=> $this->_('Log skipped pages'),
						'notes'					=> $this->_('Writes a line to the static-pages log every time a page was rendered but not cached, naming the reason. Nothing explains otherwise why an installed and enabled module produces no files at all: the commonest reason by far is that you are browsing the site logged in, which is never cached. Leave off on a busy front end, it costs one log line per request.'),
						//'required'				=> true,
						'columnWidth'			=> 33,
						//'value'					=> 1,
					),

				),
			),

			array(
				'name'					=> '_wipe',
				'type'					=> 'fieldset',
				'label'					=> $this->_('Wipe'),
				'children'				=> array(

					array(
						'name'					=> 'wipeOnConfigSaveModules',
						'type'					=> 'textarea',
						'label'					=> $this->_('Wipe on config save of these modules'),
						'description'			=> $this->_('one module class name per line'),
						'notes'					=> $this->_('Modules keeping site wide settings in their own config data save no page, so nothing is wiped when their settings change. Saving the config of a module listed here deletes every static file the index knows of, once the response is out. Name the class holding the data, not the Process class: SettingsFactory, not ProcessSettingsFactory. Names are case insensitive and the module needs not be installed. Do not list modules writing counters or timestamps to their config, every such write would wipe.'),
						//'required'				=> true,
						'columnWidth'			=> 50,
						'value'					=> 'SettingsFactory',
					),

					array(
						'name'					=> 'wipeOnSaveField',
						'type'					=> 'checkbox',
						'label'					=> $this->_('Wipe on single field save'),
						'notes'					=> $this->_('Inline admin edits and setAndSave() never reach a page save. Uncheck when some module writes counters or timestamps that way on frontend requests.'),
						//'required'				=> true,
						'columnWidth'			=> 50,
						'value'					=> 1,
					),

					array(
						'name'					=> 'wipeAllTemplates',
						'type'					=> 'AsmSelect',
						'label'					=> $this->_('Wipe everything on save of these templates'),
						'notes'					=> $this->_('A save normally removes only the static files whose render loaded the saved page or ran a find it may enter. List templates whose pages feed markup by a way that record can not see: $pages->findRaw(), a WireCache or MarkupCache fragment, raw SQL. Language pages always wipe everything.'),
						'columnWidth'			=> 50,
						'options'				=> $nonStaticTemplatesOptions,
						'value'					=> array(),
					),

					array(
						'name'					=> 'wipeAllFields',
						'type'					=> 'textarea',
						'label'					=> $this->_('Wipe everything on change of these fields'),
						'description'			=> $this->_('one field name per line'),
						'notes'					=> $this->_('The same, for a field rather than a template: a change of a listed field on any page removes every static file. Names are case insensitive.'),
						'columnWidth'			=> 50,
						'value'					=> '',
					),

					array(
						'name'					=> 'ignoreTemplates',
						'type'					=> 'AsmSelect',
						'label'					=> $this->_('Never invalidate on save of these templates'),
						'notes'					=> $this->_('For pages no cached page shows, saved often: orders, form entries, log pages. Saving, deleting, trashing or moving a page of a listed template removes no static file and queues nothing, and a render running meanwhile still writes its file. A repeater item counts as a page of its owner\'s template. A save that moves a page into a listed template from one that is not listed still invalidates once. Deleting a listed page whose template carries a repeater field, or a template change that drops a repeater field, still queues one row for the repeater container page: one drain runs. What you sign: wherever a cached page does show a page of a listed template (a listing, a counter, a "latest" box), it stays stale until something else wipes it, and so do the static copies of its descendants after a move or a rename. A template also listed in "Wipe everything on save of these templates" still wipes everything, and so do language pages.'),
						'columnWidth'			=> 100,
						'options'				=> $nonStaticTemplatesOptions,
						'value'					=> array(),
					),

				),
			),

			array(
				'name'					=> 'htaccessAction',
				'type'					=> 'select',
				'label'					=> $this->_('Hand-written rules found'),
				'description'			=> $this->_('What to do with hand-written rules in the root .htaccess that look like StaticPages rules (see "Root .htaccess" above). Acted on once, when the settings are saved; never stored.'),
				'notes'					=> $this->_('Replace: after a backup (.htaccess.sp-<date>.bak next to it), every hand-written line of those rules is commented out with the prefix "# StaticPages-replaced: " and the StaticPages block is written; nothing is deleted, and uninstalling the module takes the prefix off again. Lines the stock ProcessWire .htaccess also holds, and rules that only depend on the request method, are left as they are. Keep: nothing is written, and there is no warning about these rules again until they change. With "Dry run" checked, Replace writes nothing and the new .htaccess goes to the static-pages log.'),
				'options'				=> array(
					''						=> $this->_('Nothing'),
					'replace'				=> $this->_('Replace them with the StaticPages block'),
					'keep'					=> $this->_('Keep them'),
				),
				'columnWidth'			=> 100,
				'value'					=> '',
			),

			array(
				'name'					=> 'forceWipeOnModuleSave',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Force static pages deletion on module save'),
				//'description'			=> $this->_('UNIX string like 0755'),
				'notes'					=> $this->_('Performs a full disk scan, also removing static files the index does not know about. It only walks root dirs that are static copies of pages: named like a first level page and holding nothing but static files.'),
				//'required'				=> true,
				'columnWidth'			=> 50,
				//'value'					=> 1,
			),

			array(
				'name'					=> 'isEnabled',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Enable this module'),
				//'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'),
				//'required'				=> true,
				'columnWidth'			=> 50,
				'value'					=> 1,
			),
);