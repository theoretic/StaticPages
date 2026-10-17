<?php
/*
Static pages module info
https://github.com/ryancramerdesign/Helloworld
AT
17.10.26
*/

namespace ProcessWire;

$info = [
	'title'					=> 'StaticPages',
	'version'				=> '0.7.1',
	'summary'				=> 'Saves output to static html files. A page save deletes only the files whose render depended on the saved page; settings module config saves and tracked file changes delete them all.',
	'author'				=> 'AT / atis.pro',
	'href'					=> 'http://atis.pro',
	'singular'				=> true,
	'autoload'				=> true,
	'icon'					=> 'file-o', 
/*
	'permission' => 'staticwire-generate', 
	'permissions' => [ 'staticwire-generate' => 'Covert pages to HTML files'
	],
	'page' => [
		'name' => 'staticwire',
		'parent' => 'setup', 
		'title' => 'Static Site Generator',
	],
*/
];