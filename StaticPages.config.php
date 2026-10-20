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

$config = array(

			array(
				'name'					=> 'priority',
				'type'					=> 'text',
				'label'					=> $this->_('Priority'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('Should be higher than other modules have to transform their output.'),
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> StaticPages::priorityDefault,
			),

			array(
				'name'					=> 'nonStaticDirs',
				'type'					=> 'textarea',
				'label'					=> $this->_('Non-static directories'),
				'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('These directories will be protected from deletion when static files and dirs are deleted.'),
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> $nonStaticDirsValue,
			),

			array(
				'name'					=> 'nonStaticTemplates',
				'type'					=> 'AsmSelect',
				'label'					=> $this->_('Non-static page templates'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('Pages having these templates will have no static representation.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'options'				=> $nonStaticTemplatesOptions,
				'value'					=> $nonStaticTemplatesValue,
			),

			array(
				'name'					=> 'newDirPermissions',
				'type'					=> 'text',
				'label'					=> $this->_('Permissions for every new static dir'),
				'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> '0755',
			),

			array(
				'name'					=> 'staticFile',
				'type'					=> 'text',
				'label'					=> $this->_('Static file name'),
				//'description'			=> $this->_('normally index.html'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 'index.html',
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
				'name'					=> 'forceWipeOnModuleSave',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Force static pages deletion on module save'),
				//'description'			=> $this->_('UNIX string like 0755'),
				'notes'					=> $this->_('Performs a full disk scan, also removing static files the index does not know about. It only walks root dirs that are static copies of pages: named like a first level page and holding nothing but static files.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//'value'					=> 1,
			),

			array(
				'name'					=> 'wipeDryRun',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Dry run'),
				'notes'					=> $this->_('With the box above: deletes nothing, and writes every file and dir the scan would delete to the static-pages log, with the root dirs it leaves alone. The same from code: $modules->get(\'StaticPages\')->wipeCaches(true, true).'),
				'columnWidth'			=> 33,
			),

			array(
				'name'					=> 'wipeOnConfigSaveModules',
				'type'					=> 'textarea',
				'label'					=> $this->_('Wipe on config save of these modules'),
				'description'			=> $this->_('one module class name per line'),
				'notes'					=> $this->_('Modules keeping site wide settings in their own config data save no page, so nothing is wiped when their settings change. Saving the config of a module listed here deletes every static file the index knows of, once the response is out. Name the class holding the data, not the Process class: SettingsFactory, not ProcessSettingsFactory. Names are case insensitive and the module needs not be installed. Do not list modules writing counters or timestamps to their config, every such write would wipe.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 'SettingsFactory',
			),

			array(
				'name'					=> 'wipeOnSaveField',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Wipe on single field save'),
				'notes'					=> $this->_('Inline admin edits and setAndSave() never reach a page save. Uncheck when some module writes counters or timestamps that way on frontend requests.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 1,
			),

			array(
				'name'					=> 'wipeAllTemplates',
				'type'					=> 'AsmSelect',
				'label'					=> $this->_('Wipe everything on save of these templates'),
				'notes'					=> $this->_('A save normally removes only the static files whose render loaded the saved page or ran a find it may enter. List templates whose pages feed markup by a way that record can not see: $pages->findRaw(), a WireCache or MarkupCache fragment, raw SQL. Language pages always wipe everything.'),
				'columnWidth'			=> 33,
				'options'				=> $nonStaticTemplatesOptions,
				'value'					=> array(),
			),

			array(
				'name'					=> 'wipeAllFields',
				'type'					=> 'textarea',
				'label'					=> $this->_('Wipe everything on change of these fields'),
				'description'			=> $this->_('one field name per line'),
				'notes'					=> $this->_('The same, for a field rather than a template: a change of a listed field on any page removes every static file. Names are case insensitive.'),
				'columnWidth'			=> 33,
				'value'					=> '',
			),

			array(
				'name'					=> 'trackedPaths',
				'type'					=> 'textarea',
				'label'					=> $this->_('Track changes in these paths'),
				'description'			=> $this->_('one path pattern per line, relative to the website root'),
				'notes'					=> $this->_('Editing a template or an asset file fires no ProcessWire event, so the static files would silently stay stale. On admin page requests the files matching these patterns are checked by name, modification time and size, and the static files are deleted when anything changed. File contents are never read, so an edit changing neither the size nor the modification time goes unnoticed. "*" matches inside one path segment, "**" matches any number of segments including none, "?" matches one character. Matching is case insensitive. Absolute paths, drive letters and ".." are ignored. Anything under site/assets/cache, /logs, /sessions and /tmp is never tracked: ProcessWire and this module write there on ordinary requests and tracking it would wipe forever. Keep the patterns narrow, a line like "**/*" walks the whole website. Leave empty to switch tracking off.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//kept on the module class so the two can not drift apart
				'value'					=> StaticPages::trackedPathsDefault,
			),

			array(
				'name'					=> 'noCacheCookies',
				'type'					=> 'textarea',
				'label'					=> $this->_('Never cache with these cookies'),
				'description'			=> $this->_('one cookie name per line, "*" and "?" as wildcards'),
				'notes'					=> $this->_('A guest request carrying one of these cookies is rendered but not cached: output that depends on a cookie (a currency, a region, a consent banner) would be frozen into the file everybody gets. Responses that set a cookie (ProcessWire\'s own session cookie aside) or send a Vary header on anything but Accept-Encoding are never cached either. Output that depends on Accept-Language without saying so can not be told apart: exclude its template.'),
				'columnWidth'			=> 33,
				'value'					=> '',
			),

			array(
				'name'					=> 'csrfPatterns',
				'type'					=> 'textarea',
				'label'					=> $this->_('CSRF token tags'),
				'description'			=> $this->_('one regex per line, delimiters included, each matching one whole tag'),
				'notes'					=> $this->_('A CSRF token belongs to one session, and a static file is served to everybody. In every tag a line matches, the content and value attributes are emptied before the file is written, and the name attribute of an input. The default matches <meta name=csrf-token> with its -name twin and <input rel=csrf>, the markup @atispro/core 00-csrf.js fills in again. A line that is no valid regex stops all caching. Empty: nothing is stripped.'),
				'columnWidth'			=> 33,
				'value'					=> StaticPages::csrfPatternsDefault,
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

			array(
				'name'					=> 'isEnabled',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Enable this module'),
				//'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 1,
			),
);