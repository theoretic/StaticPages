<?php
/*
Static pages module info
AT
18.10.26
*/

namespace ProcessWire;

$info = array(
	'title'					=> 'StaticPages',
	'version'				=> '0.8.0',
	'summary'				=> 'Saves output to static html files. A page save deletes only the files whose render depended on the saved page; settings module config saves and tracked file changes delete them all.',
	'author'				=> 'AT / atis.pro',
	'href'					=> 'http://atis.pro',
	'singular'				=> true,
	'autoload'				=> true,
	'icon'					=> 'file-o',
);
