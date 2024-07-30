<?php
/*
Static pages module config
https://github.com/ryancramerdesign/Helloworld
AT
15.12.23
*/

namespace ProcessWire;

foreach( wire('templates') as $template ){
	$nonStaticTemplatesOptions[$template->id] = $template->name;
	if( $template->name == 'admin' ) $nonStaticTemplatesValue[] = $template->id;
}

foreach (new \DirectoryIterator($_SERVER['DOCUMENT_ROOT']) as $fileInfo) {
	if($fileInfo->isDot()) continue;
	if($fileInfo->isFile()) continue;
	$nonStaticDirsValue[] = $fileInfo->getFilename();
}

$nonStaticDirsValue = implode("\n", $nonStaticDirsValue);

////

$config = [

			[
				'name'					=> 'nonStaticTemplates',
				'type'					=> 'AsmSelect',
				'label'					=> $this->_('Non-static page templates'),
				//'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('Pages having these templates will have no static representation.'), 
				'required'				=> true,
				'columnWidth'			=> 25,
				'options'				=> $nonStaticTemplatesOptions,
				'value'					=> $nonStaticTemplatesValue,
			],

			[
				'name'					=> 'nonStaticDirs',
				'type'					=> 'textarea',
				'label'					=> $this->_('Non-static directories'),
				'description'			=> $this->_('one name per line'),
				'notes'					=> $this->_('These directories will be protected from deletion when static files and dirs are deleted.'),
				'required'				=> true,
				'columnWidth'			=> 25,
				'value'					=> $nonStaticDirsValue,
			],

			[
				'name'					=> 'newDirPermissions',
				'type'					=> 'text',
				'label'					=> $this->_('Permissions for every new static dir'),
				'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 25,
				'value'					=> '0755',
			],

			[
				'name'					=> 'staticFile',
				'type'					=> 'text',
				'label'					=> $this->_('Static file name'),
				//'description'			=> $this->_('normally index.html'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				'required'				=> true,
				'columnWidth'			=> 25,
				'value'					=> 'index.html',
			],

			[
				'name'					=> 'forceWipeOnModuleSave',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Force static pages deletion on module save'),
				//'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				//'required'				=> true,
				'columnWidth'			=> 50,
				//'value'					=> 1,
			],

			[
				'name'					=> 'isEnabled',
				'type'					=> 'checkbox',
				'label'					=> $this->_('Enable this module'),
				//'description'			=> $this->_('UNIX string like 0755'),
				//'notes'					=> $this->_('Path relative to website root directory.'), 
				//'required'				=> true,
				'columnWidth'			=> 50,
				'value'					=> 1,
			],
];