<?php
/*
Static pages module info
https://github.com/ryancramerdesign/Helloworld
AT
30.08.26
*/

namespace ProcessWire;

$info = [
	'title'					=> 'StaticPages',
	'version'				=> '0.6.0',
	'summary'				=> 'Saves output to static html files and deletes them on each page save, settings module config save or tracked file change.',
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