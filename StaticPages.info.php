<?php
/*
Static pages module info
https://github.com/ryancramerdesign/Helloworld
AT
28.11.23
*/

namespace ProcessWire;

$info = [
	'title'					=> 'StaticPages',
	'version'				=> 012,
	'summary'				=> 'Saves output to static html files and deletes html files on each page save.',
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