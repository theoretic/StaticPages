<?php
/*
Static pages module config
AT
28.07.26
*/

namespace ProcessWire;

//webroot from PW config: works under CLI and subdirectory installs
$DOCUMENT_ROOT = rtrim( wire('config')->paths->root, '/\\' );

$nonStaticTemplatesOptions = [];
$nonStaticTemplatesValue = [];
$nonStaticDirsValue = [];

foreach( wire('templates') as $template ){
	$nonStaticTemplatesOptions[$template->id] = $template->name;
	if( $template->name == 'admin' ) $nonStaticTemplatesValue[] = $template->id;
}

foreach (new \DirectoryIterator($DOCUMENT_ROOT) as $fileInfo) {
	if($fileInfo->isDot()) continue;
	if($fileInfo->isFile()) continue;
	$nonStaticDirsValue[] = $fileInfo->getFilename();
}

$nonStaticDirsValue = implode("\n", $nonStaticDirsValue);

////

$config = [

			[
				'name'					=> 'priority',
				'type'					=> 'text',
				'label'					=> $this->_('Priority'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('Should be higher than other modules have to transform their output.'),
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 1000,
			],

			[
				'name'					=> 'nonStaticDirs',
				'type'					=> 'textarea',
				'label'					=> $this->_('Non-static directories'),
				'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('These directories will be protected from deletion when static files and dirs are deleted.'),
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> $nonStaticDirsValue,
			],

			[
				'name'					=> 'nonStaticTemplates',
				'type'					=> 'AsmSelect',
				'label'					=> $this->_('Non-static page templates'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('Pages having these templates will have no static representation.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'options'				=> $nonStaticTemplatesOptions,
				'value'					=> $nonStaticTemplatesValue,
			],

			[
				'name'					=> 'newDirPermissions',
				'type'					=> 'text',
				'label'					=> $this->_('Permissions for every new static dir'),
				'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> '0755',
			],

			[
				'name'					=> 'staticFile',
				'type'					=> 'text',
				'label'					=> $this->_('Static file name'),
				//'description'			=> $this->_('normally index.html'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 'index.html',
			],

			[
				'name'					=> 'useShell',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Use shell commands'),
				//'description'			=> $this->_('UNIX string like 0755'),
				'notes'					=> $this->_('Applies only to the fallback full-disk scan (no manifest or forced wipe). Faster than php but can be unavailable on certain systems.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//'value'					=> 1,
			],

			[
				'name'					=> 'rmDirPasses',
				'type'					=> 'text',
				'label'					=> $this->_('Dir removal passes'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('When shell is used, recursive removal of empty dirs may require several passes if the directory structure is deeply nested. Normally, 10 passes is enough.'),
				'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 10,
			],

			[
				'name'					=> 'forceWipeOnModuleSave',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Force static pages deletion on module save'),
				//'description'			=> $this->_('UNIX string like 0755'),
				'notes'					=> $this->_('Performs a full disk scan, also removing static files the manifest does not know about.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//'value'					=> 1,
			],

			[
				'name'					=> 'wipeOnConfigSaveModules',
				'type'					=> 'textarea',
				'label'					=> $this->_('Wipe on config save of these modules'),
				'description'			=> $this->_('one module class name per line'),
				'notes'					=> $this->_('Modules keeping site wide settings in their own config data save no page, so nothing is wiped when their settings change. Saving the config of a module listed here deletes the static files (manifest based). Name the class holding the data, not the Process class: SettingsFactory, not ProcessSettingsFactory. Names are case insensitive and the module needs not be installed. Do not list modules writing counters or timestamps to their config, every such write would wipe.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 'SettingsFactory',
			],

			[
				'name'					=> 'wipeOnSaveField',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Wipe on single field save'),
				'notes'					=> $this->_('Inline admin edits and setAndSave() never reach a page save. Uncheck when some module writes counters or timestamps that way on frontend requests.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 1,
			],

			[
				'name'					=> 'trackedPaths',
				'type'					=> 'textarea',
				'label'					=> $this->_('Track changes in these paths'),
				'description'			=> $this->_('one path pattern per line, relative to the website root'),
				'notes'					=> $this->_('Editing a template or an asset file fires no ProcessWire event, so the static files would silently stay stale. On admin page requests the files matching these patterns are checked by name, modification time and size, and the static files are deleted when anything changed. File contents are never read, so an edit changing neither the size nor the modification time goes unnoticed. "*" matches inside one path segment, "**" matches any number of segments including none, "?" matches one character. Matching is case insensitive. Absolute paths, drive letters and ".." are ignored. Anything under site/assets/cache, /logs, /sessions and /tmp is never tracked: ProcessWire and this module write there on ordinary requests and tracking it would wipe forever. Keep the patterns narrow, a line like "**/*" walks the whole website. Leave empty to switch tracking off.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//kept on the module class so the two can not drift apart
				'value'					=> StaticPages::trackedPathsDefault,
			],

			[
				'name'					=> 'logSkips',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Log skipped pages'),
				'notes'					=> $this->_('Writes a line to the static-pages log every time a page was rendered but not cached, naming the reason. Nothing explains otherwise why an installed and enabled module produces no files at all: the commonest reason by far is that you are browsing the site logged in, which is never cached. Leave off on a busy front end, it costs one log line per request.'),
				//'required'				=> true,
				'columnWidth'			=> 33,
				//'value'					=> 1,
			],

			[
				'name'					=> 'isEnabled',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Enable this module'),
				//'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				//'required'				=> true,
				'columnWidth'			=> 33,
				'value'					=> 1,
			],
];