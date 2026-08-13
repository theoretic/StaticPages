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