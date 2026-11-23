<?php namespace ProcessWire;

/**
 * Smoke test: runs StaticPages against a bootstrapped ProcessWire install with the module
 * installed. Writes to the database (fixture pages with a repeater item or two, the module's
 * own tables, its config, all restored or removed at the end, or from a shutdown function when
 * the script dies before it) and to a temporary directory: the site root, the cache dir and the log dir are pointed
 * there right after the boot, so no file of the install is written, wiped or logged to. Only
 * the module info and module install checks run under the install's own root and cache dir,
 * where core may refresh a stale compiled module.
 *
 * Usage: php tests/smoke.php /path/to/pw/webroot
 *
 * Use a Bridge sandbox or a throwaway install, never a live site: the script refuses webroots
 * under D:/work/sites and Z:/home. The module the install loads is tested, whichever copy that
 * is; the script says which.
 *
 * @author AT
 */

if(PHP_SAPI !== 'cli') exit;

$root = rtrim(str_replace('\\', '/', $argv[1] ?? (getenv('PW_ROOT') ?: '')), '/');
if(!is_file("$root/index.php")) exit("Usage: php tests/smoke.php /path/to/pw/webroot\n");
$real = str_replace('\\', '/', (string) realpath($root));
if(stripos($real, '/work/sites/') !== false || stripos($real, 'Z:/home') === 0) exit("Refusing a site under D:/work/sites or Z:/home: use a Bridge sandbox\n");

//some site configs read it, CLI has none
$_SERVER['DOCUMENT_ROOT'] = $root;
chdir($root);
include "$root/index.php";

$fails = 0;
function check($label, $ok, $info = '') {
	global $fails;
	if(!$ok) $fails++;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $info === '' ? '' : "  [$info]") . "\n";
}

$config = $wire->config;
$pages = $wire->pages;
$modules = $wire->modules;
$database = $wire->database;

if(!$modules->isInstalled('StaticPages')) exit("StaticPages is not installed on this site\n");

//both read before the temporary root below: core finds module files through paths->root.
//The version of the files under test, from StaticPages.info.php rather than the info cache,
//which may still hold the version of another copy. The config to restore at the end: with no
//module info cache core looks for the module's config file before loading it, finds none
//under the temporary root and answers an empty array, which the cleanup then stored
$info = $modules->getModuleInfoVerbose('StaticPages', array('noCache' => true));
$configAtStart = $modules->getConfig('StaticPages');

//everything on disk goes to a temporary dir from here on
//TEMP rather than sys_get_temp_dir(): a PHP build may keep its temp dir inside the sites tree
$tmpBase = getenv('STATICPAGES_SMOKE_TMP') ?: (getenv('TMPDIR') ?: (getenv('TEMP') ?: (getenv('TMP') ?: sys_get_temp_dir())));
$tmpReal = str_replace('\\', '/', (string) realpath($tmpBase));
if($tmpReal === '' || stripos($tmpReal, '/work/sites/') !== false || stripos($tmpReal, 'Z:/home') === 0) exit("Temp dir '$tmpBase' is missing or inside the sites tree: set STATICPAGES_SMOKE_TMP\n");
$tmp = rtrim($tmpReal, '/') . '/staticpages-smoke-' . getmypid();
foreach(array('root', 'cache', 'logs') as $dir) @mkdir("$tmp/$dir", 0777, true);
$realPaths = array('root' => $config->paths->root, 'cache' => $config->paths->cache);
$config->paths->root = "$tmp/root/";
$config->paths->cache = "$tmp/cache/";
$config->paths->logs = "$tmp/logs/";
$tmpRoot = "$tmp/root";

//the install's own root and cache dir for the few core calls that need them (module files,
//compiled modules); false points both at the temporary dir again. Logs stay there either way
function realPaths($real) {
	global $config, $realPaths, $tmp;
	$config->paths->root = $real ? $realPaths['root'] : "$tmp/root/";
	$config->paths->cache = $real ? $realPaths['cache'] : "$tmp/cache/";
}

/** @var StaticPages $sp */
$sp = $modules->get('StaticPages');
echo "PW {$config->version}, StaticPages {$info['versionStr']} from " . dirname((new \ReflectionClass($sp))->getFileName()) . "\n";
echo "temp dir $tmp\n\n";

//an install switched from an older version migrates on its first CLI run, admin request
//or save; here on the temporary root, before the test settings below are made
check('migration runs without ___upgrade() or a modules refresh', $sp->migration()->run() === true && (int) $modules->getConfig('StaticPages', 'configVersion') === StaticPages::configVersion && !$sp->migration()->pending());

$sp->set('isEnabled', 1);
$sp->set('useShell', 0);
$sp->set('logSkips', 1);
$sp->set('staticFile', 'index.html');
$sp->set('nonStaticDirs', "site\nvendor");
$sp->set('noCacheCookies', '');
$sp->remove('csrfPatterns');

function files($dir) {
	$out = array();
	if(!is_dir($dir)) return '';
	$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
	foreach($it as $f) $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir))) . ($f->isDir() ? '/' : '');
	sort($out);
	return implode(' ', $out);
}
function publish(StaticPages $sp, Page $page, array $load = array(), $html = null) {
	//what Page::render hands cachePage(), without a template file: the pages loaded by the
	//"render" are the ones in $load
	$wire = $sp->wire();
	$wire->pages->uncacheAll();
	$page = $wire->pages->get($page->id);
	foreach($load as $id) $wire->pages->get($id);
	$wire->wire('page', $page);
	$sp->queue()->startGen();
	if($html === null) $html = "<html><head><meta name=csrf-token content=tok{$page->id}></head><body>{$page->title}</body></html>";
	$sp->cachePage(new HookEvent(array('object' => $page, 'method' => 'render', 'return' => $html)));
	return $page;
}
function tagsOf(StaticPages $sp, $relative) {
	$query = $sp->wire()->database->prepare('SELECT tag FROM `' . StaticPages::tableTags . '` WHERE path_hash = UNHEX(:hash) ORDER BY tag');
	$query->bindValue(':hash', md5($relative));
	$query->execute();
	return $query->fetchAll(\PDO::FETCH_COLUMN);
}
function rrmdir($dir) {
	if(!is_dir($dir)) return;
	foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f)
		$f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	@rmdir($dir);
}
function logText($tmp) {
	return (string) @file_get_contents("$tmp/logs/static-pages.txt");
}

////  helpers

$sp->set('staticFile', '*');
check('static file name "*" is refused', $sp->staticFileName() === '');
$sp->set('staticFile', '../x.html');
check('static file name "../x.html" is refused', $sp->staticFileName() === '');
$sp->set('staticFile', '.index.html');
check('static file name with a leading dot is refused', $sp->staticFileName() === '');
$sp->remove('staticFile');
check('static file name defaults to index.html', $sp->staticFileName() === 'index.html');
$sp->set('staticFile', 'index.html');

//the names of the other output types: the static file name with its extension replaced
$typed = array();
foreach(array('index.html', 'cache.htm', 'index', '*') as $name) {
	$sp->set('staticFile', $name);
	$typed[$name] = $sp->staticFileName('xml');
}
$sp->set('staticFile', 'index.html');
foreach(array('php', 'json', 'XML', '', '../x') as $type) $typed["type $type"] = $sp->staticFileName($type);
check('staticFileName(type): index.xml, cache.xml, index.xml, "" for an invalid name; "" for a type outside xml and txt', $typed === array('index.html' => 'index.xml', 'cache.htm' => 'cache.xml', 'index' => 'index.xml', '*' => '', 'type php' => '', 'type json' => '', 'type XML' => '', 'type ' => '', 'type ../x' => ''), json_encode($typed));
check('staticFileName() and staticFileName("html") stay the static file name, staticFileName("txt") is index.txt', $sp->staticFileName() === 'index.html' && $sp->staticFileName('html') === 'index.html' && $sp->staticFileName('txt') === 'index.txt');

$pub = $sp->publisher();
$mimes = array();
foreach(array('text/html; charset=utf-8', 'TEXT/HTML', 'application/xml', 'text/xml', 'application/rss+xml', 'application/xhtml+xml', 'text/plain; charset=UTF-8', 'application/json') as $mime) $mimes[] = $pub->typeOfMime($mime);
check('typeOfMime(): html html xml xml xml other txt other', $mimes === array('html', 'html', 'xml', 'xml', 'xml', 'other', 'txt', 'other'), json_encode($mimes));

$rt = array(
	$pub->renderType('', array()),
	$pub->renderType('html', array()),
	$pub->renderType('xml', array()),
	$pub->renderType('', array('Content-Type: application/xml; charset=utf-8')),
	$pub->renderType('txt', array('Content-Type: text/plain')),
	$pub->renderType('xml', array('Content-Type: text/html')),
	$pub->renderType('json', array()),
	$pub->renderType('', array('Content-Type: application/json')),
	$pub->renderType('text/plain', array()),
	$pub->renderType('', array('Content-Type: text/html', 'X-Other: 1', 'content-type: text/xml')),
	$pub->renderType('', array('Content-Type: text/xml', 'Content-Type: text/html')),
);
check('renderType(): the response type, else the template type, else html', array_slice($rt, 0, 5) === array(array('html', ''), array('html', ''), array('xml', ''), array('xml', ''), array('txt', '')) && $rt[8] === array('txt', ''), json_encode($rt));
check('renderType(): a template and a response that disagree are a skip naming both', $rt[5][0] === '' && strpos($rt[5][1], 'xml') !== false && strpos($rt[5][1], 'text/html') !== false, json_encode($rt[5]));
check('renderType(): json is a skip, in today\'s wording when the template says so', $rt[6] === array('', "content type is 'json'") && $rt[7][0] === '' && $rt[7][1] !== '', json_encode(array($rt[6], $rt[7])));
check('renderType(): of two Content-Type headers the last one counts', $rt[9] === array('xml', '') && $rt[10] === array('html', ''), json_encode(array($rt[9], $rt[10])));

$sets = array();
$sp->remove('cacheNonHtml');
$sets['absent'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('cacheNonHtml', 0);
$sets['off'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('cacheNonHtml', 1);
$sets['on'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('staticFile', '*');
$sets['invalid'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('staticFile', 'index.xml');
$sets['index.xml'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('staticFile', 'index.txt');
$sets['index.txt'] = array($sp->scanFileNames(), $sp->knownFileNames());
$sp->set('staticFile', 'index.html');
$sp->remove('cacheNonHtml');
$three = array('index.html', 'index.xml', 'index.txt');
check('scanFileNames(): index.html with the switch off or absent, the three names with it on; knownFileNames(): the three names either way', $sets['absent'] === array(array('index.html'), $three) && $sets['off'] === array(array('index.html'), $three) && $sets['on'] === array($three, $three), json_encode($sets));
check('both name sets are empty for an invalid static file name', $sets['invalid'] === array(array(), array()), json_encode($sets['invalid']));
check('both name sets hold each name once when the static file name is index.xml or index.txt', $sets['index.xml'] === array(array('index.xml', 'index.txt'), array('index.xml', 'index.txt')) && $sets['index.txt'] === array(array('index.txt', 'index.xml'), array('index.txt', 'index.xml')), json_encode(array($sets['index.xml'], $sets['index.txt'])));
$given = array(
	$sp->scanFileNames(array('staticFile' => 'cache.htm', 'cacheNonHtml' => 1)),
	$sp->knownFileNames(array('staticFile' => 'cache.htm')),
	$sp->scanFileNames(array('cacheNonHtml' => 0)),
	$sp->scanFileNames(array('staticFile' => '*', 'cacheNonHtml' => 1)),
);
check('the name sets read staticFile and cacheNonHtml from an array handed in, not from the loaded properties', $given === array(array('cache.htm', 'cache.xml', 'cache.txt'), array('cache.htm', 'cache.xml', 'cache.txt'), array('index.html'), array()) && $sp->scanFileNames() === array('index.html'), json_encode($given));
check('tmpFileGlob(): .index.html.*.tmp, with a name .index.xml.*.tmp', $pub->tmpFileGlob() === '.index.html.*.tmp' && $pub->tmpFileGlob('index.xml') === '.index.xml.*.tmp');

$w = $sp->wiper();
check('readableFilesize 133', $w->readableFilesize(133) === '133.00B', $w->readableFilesize(133));
check('readableFilesize 2048', $w->readableFilesize(2048) === '2.00K', $w->readableFilesize(2048));
check('readableFilesize 0', $w->readableFilesize(0) === '0.00B', $w->readableFilesize(0));

$sp->set('nonStaticDirs', "/site/\nsite vendor wire\nabout/keep");
$dirs = $sp->nonStaticDirsArray();
check('nonStaticDirs: slashes trimmed, blank separated words protected', in_array('site', $dirs, true) && in_array('vendor', $dirs, true) && in_array('wire', $dirs, true) && in_array('site vendor wire', $dirs, true), implode('|', $dirs));
check('isProtectedPath below a listed dir', $w->isProtectedPath("$tmpRoot/vendor/pkg", $dirs));
check('isProtectedPath nested entry', $w->isProtectedPath("$tmpRoot/about/keep/x", $dirs) && !$w->isProtectedPath("$tmpRoot/about/other", $dirs));
check('isProtectedPath leaves a sibling with the same prefix alone', !$w->isProtectedPath("$tmpRoot/sitemap", $dirs));
$sp->set('nonStaticDirs', "site\nvendor");

check('staticRoot: the site root while no base directory is set', $sp->staticRoot() === $sp->rootPath(), $sp->staticRoot() . ' vs ' . $sp->rootPath());

@mkdir("$tmpRoot/in", 0777, true);
file_put_contents("$tmpRoot/in/index.html", 'x');
file_put_contents("$tmp/outside.html", 'x');
check('insideRoot: a file below the root', $w->insideRoot("$tmpRoot/in/index.html"));
check('insideRoot: the root itself is not below it', !$w->insideRoot($tmpRoot));
check('insideRoot: a file beside the root', !$w->insideRoot("$tmp/outside.html"));
check('insideRoot: dots climbing out', !$w->insideRoot("$tmpRoot/in/../../outside.html"));
@unlink("$tmpRoot/in/index.html");
@rmdir("$tmpRoot/in");

////  CSRF tokens

function oldStripCsrf($html) {
	//0.7.1, for comparison
	$html = preg_replace('/(<meta\s+name=([\x22\x27]?)csrf-token(?:-name)?\2\s+content=)(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s>]*)/i', '$1""', $html);
	return preg_replace_callback('/<input\b[^>]*\brel=([\x22\x27]?)csrf\1[^>]*>/i', function($tag) {
		return preg_replace('/\b(value|name)=(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s>\/]*)/i', '$1=""', $tag[0]);
	}, $html);
}
$samples = array(
	'<meta name="csrf-token-name" content="TOKEN123_X"><meta name="csrf-token" content="abcDEF123">',
	'<meta name=csrf-token-name content=TOKEN123_X><meta name=csrf-token content=abcDEF123>',
	"<meta name='csrf-token' content='abc'>",
	'<meta name=csrf-token content=abc/>',
	'<input rel="csrf" type="hidden" name="TOKEN1" value="v1">',
	'<input rel=csrf type=hidden name=TOKEN1 value=v1>',
	'<input type=hidden rel=csrf name=TOKEN1 value=v1/>',
	'<form><input rel="csrf" type="hidden" name="T" value="V"><input name=q value=keep></form>',
	'<meta name="description" content="keep me"><input name="keep" value="keep">',
	'<p>value=1 name=2 content=3</p><meta name=csrf-token content=x>',
);
$same = 0;
foreach($samples as $s) if($sp->stripCsrf($s) === oldStripCsrf($s)) $same++;
check('stripCsrf default = 0.7.1 output on ' . count($samples) . ' samples', $same === count($samples), "$same same");
$sp->set('csrfPatterns', '/<input\b[^>]*\bdata-token\b[^>]*>/i');
check('stripCsrf custom pattern', $sp->stripCsrf('<input data-token name=a value=b><meta name=csrf-token content=x>') === '<input data-token name="" value=""><meta name=csrf-token content=x>', $sp->stripCsrf('<input data-token name=a value=b><meta name=csrf-token content=x>'));
$sp->set('csrfPatterns', "/<input\\b[^>]*>/i\n/(unclosed/");
check('stripCsrf answers null for an invalid pattern', $sp->stripCsrf('<input value=x>') === null);
$sp->remove('csrfPatterns');

////  cookies and headers

$p = $sp->publisher();
$sp->set('noCacheCookies', "currency\nab_*");
check('cookie skip: listed cookie', $p->cookieSkip(array('wire' => 'x', 'currency' => 'eur')) === 'currency');
check('cookie skip: wildcard', $p->cookieSkip(array('ab_test' => '1')) === 'ab_test');
check('cookie skip: unlisted cookies', $p->cookieSkip(array('wire' => 'x', 'other' => '1')) === '');
$sp->set('noCacheCookies', '');
check('cookie skip: empty list', $p->cookieSkip(array('currency' => 'eur')) === '');
$session = $config->sessionName;
check('header skip: session cookie is fine', $p->headerSkip(array("Set-Cookie: $session=abc; path=/", 'Content-Type: text/html')) === '');
check('header skip: other cookie', $p->headerSkip(array('Set-Cookie: region=eu; path=/')) !== '');
check('header skip: Vary Accept-Encoding is fine', $p->headerSkip(array('Vary: Accept-Encoding')) === '');
check('header skip: Vary Accept-Language', strpos($p->headerSkip(array('Vary: Accept-Encoding, Accept-Language')), 'accept-language') !== false);

////  .htaccess rules (0.10.0)

//the generator: settings and $config in, text out. Nothing calls it outside these checks yet,
//the reader and the writer arrive later. $config values are set here and put back at the end
$h = $sp->htaccess();
$sessionAtStart = array($config->sessionName, $config->sessionNameSecure, $config->sessionChallenge);
function sessionConfig($name, $secure, $challenge) {
	global $config;
	$config->sessionName = $name;
	$config->sessionNameSecure = $secure;
	$config->sessionChallenge = $challenge;
}
sessionConfig('wire', '', true);
$sp->set('staticFile', 'index.html');

$block = $h->apacheBlock();
$lines = explode("\n", $block);
check('htaccess block starts with # BEGIN StaticPages and ends with # END StaticPages, each once', strpos($block, "# BEGIN StaticPages\n") === 0 && substr($block, -strlen("\n# END StaticPages")) === "\n# END StaticPages" && substr_count($block, '# BEGIN StaticPages') === 1 && substr_count($block, '# END StaticPages') === 1, $block);
check('htaccess block holds only LF line ends', strpos($block, "\r") === false);
check('htaccess block: two calls with the same settings return identical strings', $h->apacheBlock() === $block && $sp->htaccess()->apacheBlock() === $block);
$rest = array_values(array_filter($lines, fn($l) => strpos(ltrim($l), '#') !== 0));
$bad = array_filter($rest, fn($l) => !preg_match('/^\s*Rewrite(Cond|Rule)\s/', $l));
check('htaccess block: every line that is no comment is a RewriteCond or a RewriteRule', count($rest) > 0 && !count($bad), json_encode(array_values($bad)));
check('htaccess block: the last line that is no comment is a RewriteRule, no condition is left for the stock rule 19', count($rest) && preg_match('/^\s*RewriteRule\s/', end($rest)), (string) end($rest));
check('htaccess block names the static file name escaped, index\\.html', strpos($block, 'index\\.html') !== false && strpos($block, 'index.html') === false, $block);
check('htaccess block: rules() is the rule list as data, every rule with conditions', count($h->rules()['rules']) === 3 && !count(array_filter($h->rules()['rules'], fn($r) => !count($r['conds']))));
//a dir requested without its trailing slash is left to mod_dir: rewritten, it got a 301 to
//"<dir>/?it=<dir>" on Apache 2.4 (probe of step 2)
$slashOnly = array();
foreach($h->rules()['rules'] as $r) if($r['pattern'] !== '^$') $slashOnly[] = $r['pattern'] . ' ' . (int) (preg_match('#' . $r['pattern'] . '#', 'a/b/') === 1 && preg_match('#' . $r['pattern'] . '#', 'a/b') === 0 && preg_match('#' . $r['pattern'] . '#', '') === 0);
check('htaccess block: every rule but the home rule matches a path with its trailing slash only', count($slashOnly) === 2 && $slashOnly === array_filter($slashOnly, fn($s) => substr($s, -2) === ' 1'), json_encode($slashOnly));
//the rule handing an existing dir to ProcessWire refuses what the stock 16A condition refuses
//(a dir named x@y/ gets the stock 403) [decided by user, step 2]: the stock line, word for word
$toPw = array_values(array_filter($h->rules()['rules'], fn($r) => strpos($r['target'], 'index.php?it=') === 0));
$guard = count($toPw) === 1 ? array_values(array_filter($toPw[0]['conds'], fn($c) => $c[0] === '%{REQUEST_URI}')) : array();
$guardRe = count($guard) === 1 ? '#' . trim($guard[0][1], '"') . '#' : '';
check('htaccess block: the rule to ProcessWire carries the stock 16A condition, which lets /a/b/ and /~u/a/ through and refuses /x@y/ and /a!/', count($guard) === 1 && in_array('RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"', $lines, true) && preg_match($guardRe, '/a/b/') === 1 && preg_match($guardRe, '/~u/a/') === 1 && preg_match($guardRe, '/x@y/') === 0 && preg_match($guardRe, '/a!/') === 0, json_encode($guard));

$sp->set('staticFile', 'cache.htm');
$cacheBlock = $h->apacheBlock();
check('htaccess block with static file name cache.htm names cache\\.htm and no index.html', strpos($cacheBlock, 'cache\\.htm') !== false && strpos($cacheBlock, 'index.html') === false && strpos($cacheBlock, 'index\\.html') === false, $cacheBlock);
$sp->set('staticFile', 'my-cache.htm');
$dashBlock = $h->apacheBlock();
check('htaccess block: a name holding regex metacharacters is escaped (my\\-cache\\.htm), never as written', strpos($dashBlock, 'my\\-cache\\.htm') !== false && strpos($dashBlock, 'my-cache.htm') === false && $h->escape('a.b-c') === 'a\\.b\\-c', $dashBlock);
$sp->set('staticFile', '*');
$starBlock = $h->apacheBlock();
$starRules = $h->rules();
check('htaccess block with an invalid static file name (*) holds no Rewrite line, the markers and a comment', strpos($starBlock, 'Rewrite') === false && !count($starRules['rules']) && count($starRules['notes']) === 1 && strpos($starBlock, "# BEGIN StaticPages\n") === 0 && substr($starBlock, -strlen('# END StaticPages')) === '# END StaticPages' && count(explode("\n", $starBlock)) === 4, $starBlock);
$sp->set('staticFile', 'index.html');
//a dir holding an index.htm, the third stock DirectoryIndex name, was made by hand: the rule to
//ProcessWire leaves it to the stock rules, unless the static file is index.htm itself [decided by
//user, audit 1, A1.2]
$htmGuard = array('%{REQUEST_FILENAME}/index.htm', '!-f');
$guards = array();
foreach(array('index.html', 'cache.htm', 'index.htm', 'Index.HTM', 'index.htm.html') as $name) {
	$sp->set('staticFile', $name);
	$byRule = array();
	foreach($h->rules()['rules'] as $r) $byRule[] = (int) in_array($htmGuard, $r['conds'], true);
	$guards[$name] = implode('', $byRule) . ' ' . substr_count($h->apacheBlock(), "\nRewriteCond %{REQUEST_FILENAME}/index.htm !-f\n");
}
$sp->set('staticFile', 'index.html');
check('htaccess block: the rule to ProcessWire, and only it, carries RewriteCond %{REQUEST_FILENAME}/index.htm !-f, for index.html, cache.htm and index.htm.html', $guards['index.html'] === '001 1' && $guards['cache.htm'] === '001 1' && $guards['index.htm.html'] === '001 1' && in_array('RewriteCond %{REQUEST_FILENAME}/index.htm !-f', $lines, true), json_encode($guards));
check('htaccess block: with Static file name index.htm (any case) no rule carries the index.htm condition', $guards['index.htm'] === '000 0' && $guards['Index.HTM'] === '000 0', json_encode($guards));

//Non-static directories is never read: unsaved it is a walk of the site root and a page query
@mkdir("$tmpRoot/sp-ht-a/b", 0777, true);
@mkdir("$tmpRoot/sp-ht-api", 0777, true);
$dirsPopulated = $sp->wiper()->defaultNonStaticDirs();
rrmdir("$tmpRoot/sp-ht-a");
rrmdir("$tmpRoot/sp-ht-api");
$dirsEmpty = $sp->wiper()->defaultNonStaticDirs();
$same = array();
foreach(array('saved' => "site\nvendor", 'hostile' => "a b\n\"q\"\n'q'\n\$1\n%{HTTP_HOST}\n..\\x\nRewriteRule .* - [F]\n/") as $label => $value) {
	$sp->set('nonStaticDirs', $value);
	$same[$label] = $h->apacheBlock() === $block;
}
$sp->remove('nonStaticDirs');
$rootEmpty = files($tmpRoot) === '';
$database->queryLog(true);
$emptyRootBlock = $h->apacheBlock();
$queriesEmpty = count($database->queryLog());
@mkdir("$tmpRoot/sp-ht-a/b", 0777, true);
file_put_contents("$tmpRoot/sp-ht-a/b/index.html", 'copy');
@mkdir("$tmpRoot/sp-ht-api", 0777, true);
file_put_contents("$tmpRoot/sp-ht-api/index.php", '<?php');
file_put_contents("$tmpRoot/index.html", 'home');
$populatedRootBlock = $h->apacheBlock();
$queriesPopulated = count($database->queryLog());
$database->queryLog(false);
//the counter counts: the same window around one query
$database->queryLog(true);
$database->prepare('SELECT 1')->execute();
$queriesControl = count($database->queryLog());
$database->queryLog(false);
check('htaccess block is the same whatever Non-static directories holds: saved, never saved, hostile lines', !in_array(false, $same, true) && $emptyRootBlock === $block, json_encode($same));
check('htaccess block is the same on an empty and on a populated root (whose default Non-static directories differ)', $rootEmpty && $populatedRootBlock === $emptyRootBlock && $dirsPopulated !== $dirsEmpty, json_encode(array($rootEmpty, $dirsEmpty, $dirsPopulated)));
check('building the htaccess block runs no query, Non-static directories never saved', $queriesEmpty === 0 && $queriesPopulated === 0 && $queriesControl > 0, "$queriesEmpty, $queriesPopulated, control $queriesControl");
rrmdir("$tmpRoot/sp-ht-a");
rrmdir("$tmpRoot/sp-ht-api");
@unlink("$tmpRoot/index.html");
$sp->set('nonStaticDirs', "site\nvendor");

//the login cookie: only with a challenge cookie, only plain names
check('loginCookies: the challenge cookie of the plain and the secure session name', $h->loginCookies() === array('wire_challenge', 'wires_challenge'), json_encode($h->loginCookies()));
check('htaccess block with sessionChallenge on names <sessionName>_challenge', strpos($block, 'wire_challenge') !== false && strpos($block, 'wires_challenge') !== false, $block);
sessionConfig('sp-site', 'spsecure', true);
$named = $h->apacheBlock();
check('htaccess block names a configured secure session name, escaped', strpos($named, 'sp\\-site_challenge') !== false && strpos($named, 'spsecure_challenge') !== false && strpos($named, 'wire') === false, $named);
sessionConfig('wire', '', false);
$noCookieBlock = $h->apacheBlock();
$notes = array();
check('htaccess block with sessionChallenge off names no cookie', strpos($noCookieBlock, '_challenge') === false && strpos($noCookieBlock, 'HTTP_COOKIE') === false && $h->loginCookies($notes) === array() && count($notes) === 1, $noCookieBlock);
sessionConfig('wi.re', '', true);
$notes = array();
check('htaccess block with a session name holding a character outside [A-Za-z0-9_-] names no cookie', $h->apacheBlock() === $noCookieBlock && $h->loginCookies($notes) === array() && count($notes) === 1);
sessionConfig('wire', 'wi.res', true);
check('...the same for the secure session name: both names or none', $h->apacheBlock() === $noCookieBlock);

//hostile values for every setting the generator reads: each is left out, the block equals the
//one built without it, so not even a newline gets through
$hostile = array('a b', 'a"b', "a'b", 'a$1', 'a%{HTTP_HOST}', "a\nRewriteRule .* - [F]", "a\r\nb", 'a\\.b', 'a\\', 'a;b', 'a)|(x', 'a/../b', '');
$leaks = array();
sessionConfig('wire', '', true);
foreach($hostile as $value) {
	$sp->set('staticFile', ($value === '' ? '' : "$value.html"));
	if($h->apacheBlock() !== $starBlock) $leaks[] = "staticFile " . json_encode($value);
}
$sp->set('staticFile', 'index.html');
foreach($hostile as $value) {
	sessionConfig($value, '', true);
	if($h->apacheBlock() !== $noCookieBlock) $leaks[] = "sessionName " . json_encode($value);
	sessionConfig('wire', $value === '' ? 'x y' : $value, true);
	if($h->apacheBlock() !== $noCookieBlock) $leaks[] = "sessionNameSecure " . json_encode($value);
}
check('htaccess block: no hostile value of Static file name, sessionName or sessionNameSecure reaches it', !count($leaks), implode('; ', $leaks));

//file types and root files (0.11.0): with the switch off and no root file line the block is
//0.10.0's, byte for byte, so a site upgraded from 0.10.0 does not see it outdated
sessionConfig('wire', '', true);
$sp->set('staticFile', 'index.html');
$block010 = implode("\n", array(
	'# BEGIN StaticPages',
	'# Generated by the StaticPages module and replaced on every update: change its settings, not these lines.',
	'# The home page, from its copy.',
	'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$',
	'RewriteCond %{QUERY_STRING} ^$',
	'RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=',
	'RewriteCond %{REQUEST_FILENAME}/index\.html -f',
	'RewriteRule ^$ index\.html [L]',
	'# A page, from its copy, unless its directory holds an index file of its own.',
	'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$',
	'RewriteCond %{QUERY_STRING} ^$',
	'RewriteCond %{HTTP_COOKIE} !(^|;\s*)(wire_challenge|wires_challenge)=',
	'RewriteCond %{REQUEST_FILENAME}/index.php !-f',
	'RewriteCond %{REQUEST_FILENAME}/index\.html -f',
	'RewriteRule ^(.+/)$ $1index\.html [L]',
	'# Any other request for an existing directory, with its trailing slash, a URL the stock 16A condition accepts and without an index file of its own, goes to ProcessWire.',
	'RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"',
	'RewriteCond %{REQUEST_FILENAME} -d',
	'RewriteCond %{REQUEST_FILENAME}/index.php !-f',
	'RewriteCond %{REQUEST_FILENAME}/index.htm !-f',
	'RewriteRule ^(.+/)$ index.php?it=$1 [L,QSA]',
	'# END StaticPages',
	));
$offBlocks = array('absent' => $h->apacheBlock());
$sp->set('cacheNonHtml', 0);
$offBlocks['off'] = $h->apacheBlock();
$sp->set('rootFiles', 'robots.txt /robots/');
$offBlocks['off, a line'] = $h->apacheBlock();
$sp->remove('rootFiles');
$sp->remove('cacheNonHtml');
check('htaccess block, switch off, no root file line: the 0.10.0 block byte for byte, sha1 428556d9e60563ecfdd25573877f4f703d1d6291; a root file line while the switch is off adds nothing', sha1($block010) === '428556d9e60563ecfdd25573877f4f703d1d6291' && $offBlocks === array_fill_keys(array_keys($offBlocks), $block010), json_encode(array_map('sha1', $offBlocks)));
//the switch on: the page rule for each typed name, after the HTML one, none for the home page
$typedRules = array();
foreach(array('index.html', 'cache.htm', 'index', 'index.htm') as $name) {
	$sp->set('staticFile', $name);
	$sp->set('cacheNonHtml', 1);
	$on = $h->apacheBlock();
	$byRule = array();
	foreach($h->rules()['rules'] as $r) $byRule[] = $r['target'] . (in_array($htmGuard, $r['conds'], true) ? ' htm' : '');
	$sp->set('cacheNonHtml', 0);
	$typedRules[$name] = array(implode(', ', $byRule), substr_count($on, 'index\.xml'), substr_count($on, 'index\.txt'), substr_count($on, 'cache\.xml'), substr_count($on, 'cache\.txt'), $on === $h->apacheBlock());
}
$sp->set('staticFile', 'index.html');
$sp->remove('cacheNonHtml');
$wantTyped = array(
	'index.html' => array('index\.html, $1index\.html, $1index\.xml, $1index\.txt, index.php?it=$1 htm', 2, 2, 0, 0, false),
	'cache.htm' => array('cache\.htm, $1cache\.htm, $1cache\.xml, $1cache\.txt, index.php?it=$1 htm', 0, 0, 2, 2, false),
	'index' => array('index, $1index, $1index\.xml, $1index\.txt, index.php?it=$1 htm', 2, 2, 0, 0, false),
	'index.htm' => array('index\.htm, $1index\.htm, $1index\.xml, $1index\.txt, index.php?it=$1', 2, 2, 0, 0, false),
	);
check('htaccess block, switch on: a page rule for index\\.xml and index\\.txt after the HTML one, none for the home page; Static file name cache.htm, index and index.htm give the names derived from each, the index.htm condition on the rule to ProcessWire unless the name is index.htm', $typedRules === $wantTyped, json_encode($typedRules));
$sp->set('cacheNonHtml', 1);
$typedSame = array();
foreach($h->rules()['rules'] as $r) if($r['pattern'] === '^(.+/)$' && strpos($r['target'], '$1') === 0) $typedSame[] = json_encode(array_slice($r['conds'], 0, -1)) . ' ' . json_encode(end($r['conds'])) . ' ' . $r['flags'];
check('...each typed page rule carries the HTML page rule\'s conditions (GET/HEAD, no query string, no login cookie, no index.php), its own name\'s -f, [L]', count($typedSame) === 3 && count(array_unique(array_map(fn($s) => substr($s, 0, strpos($s, ' ')), $typedSame))) === 1 && strpos($typedSame[1], '"%{REQUEST_FILENAME}\/index\\\\.xml","-f"] [L]') !== false && strpos($typedSame[2], '"%{REQUEST_FILENAME}\/index\\\\.txt","-f"] [L]') !== false, json_encode($typedSame));
$onBlock = $h->apacheBlock();
//one rule per root file line, the line rootRouteLines() suggests
$sp->set('rootFiles', 'robots.txt /robots/');
$rootRules = array_values(array_filter($h->rules()['rules'], fn($r) => strpos($r['pattern'], 'robots') !== false));
$lineBlock = $h->apacheBlock();
check('htaccess block, switch on, a line "robots.txt /robots/": one rule more, ^robots\\.txt$ to index.php?it=/robots/ [L,QSA] while no file, last in the block', count($h->rules()['rules']) === 6 && count($rootRules) === 1 && $rootRules[0]['conds'] === array(array('%{REQUEST_FILENAME}', '!-f')) && $rootRules[0]['pattern'] === '^robots\.txt$' && $rootRules[0]['target'] === 'index.php?it=/robots/' && $rootRules[0]['flags'] === '[L,QSA]' && strpos($lineBlock, "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^robots\\.txt$ index.php?it=/robots/ [L,QSA]\n# END StaticPages") !== false && strpos($lineBlock, $h->rootRouteLines('robots.txt', '/robots/')) !== false, $lineBlock);
$sp->set('rootFiles', "robots.txt /robots/\nsitemap.xml");
check('...two lines, two rules: sitemap.xml to its default page /sitemap.xml/', substr_count($h->apacheBlock(), 'RewriteRule ^') === 7 && strpos($h->apacheBlock(), 'RewriteRule ^sitemap\\.xml$ index.php?it=/sitemap.xml/ [L,QSA]') !== false, $h->apacheBlock());
$leaks = array();
foreach(array('robots.html /x/', 'index.xml /x/', 'Robots.txt /x/', '.robots.txt /x/', 'a/b.txt /x/', 'robots.txt x', 'robots.txt /x', 'robots.txt /', 'robots.txt /x y/', 'robots.txt /$1/', 'robots.txt /%{HTTP_HOST}/', 'robots.txt /x/ [F]', 'robots.txt /a"b/', "robots.txt /a\\b/", 'robots.txt /a?b/') as $value) {
	$sp->set('rootFiles', $value);
	if($h->apacheBlock() !== $onBlock) $leaks[] = json_encode($value);
}
$sp->set('rootFiles', "RewriteRule .* - [F]\nrobots.txt /robots/\r\nrobots.txt /other/\n\n");
$leaks[] = $h->apacheBlock() === $lineBlock ? '' : 'mixed';
check('...a refused or hostile line adds nothing: the block equals the one without it; with a used line among them only that line\'s rule', !count(array_filter($leaks)), implode('; ', $leaks));
$sp->remove('rootFiles');
$sp->remove('cacheNonHtml');
//the lazy check hashes the block: it sees the switch and a line
$stamps = array(
	$h->using(array())->stampPath(),
	$h->using(array('cacheNonHtml' => 1))->stampPath(),
	$h->using(array('cacheNonHtml' => 1, 'rootFiles' => 'robots.txt /robots/'))->stampPath(),
	$h->using(array('cacheNonHtml' => 1, 'rootFiles' => 'robots.txt /robots/'))->stampPath(),
	);
$h->using(null);
check('stampPath() differs with the switch on, and again with a root file line added; the same settings give the same stamp', count(array_unique($stamps)) === 3 && $stamps[2] === $stamps[3], json_encode($stamps));

sessionConfig($sessionAtStart[0], $sessionAtStart[1], $sessionAtStart[2]);
$sp->set('staticFile', 'index.html');

////  .htaccess reader (0.10.0)

//the reader: the file's bytes in, a state out, nothing written. The fixtures are core's stock
//htaccess.txt of 3.0.274 and anonymised hand-written rule groups in the shapes local sites use
//(tests/fixtures); every .htaccess below is written to the temporary root only
$stock = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-301.txt');
$htFile = "$tmpRoot/.htaccess";
$stockLines = $h->lines($stock);
check('htaccess fixture: the stock htaccess.txt of 3.0.274, LF only, split on LF alone (its UTF-8 16B line stays one line)', sha1($stock) === 'ee1e1981b48c5ef603bfe99a54487297a778f9f4' && strpos($stock, "\r") === false && count($stockLines) === substr_count($stock, "\n") + 1 && trim($stockLines[439]['text']) === '# PW-PAGENAME' && strpos($stockLines[453]['text'], 'æåäßöü') !== false && trim($stockLines[445]['text']) === 'RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"');

//$add inserted as lines before (or after) the one line whose trim is $marker
function htInsert($text, $marker, array $add, $after = false) {
	$lines = explode("\n", $text);
	$at = null;
	foreach($lines as $i => $l) if(trim($l) === $marker) { $at = $i; break; }
	array_splice($lines, $after ? $at + 1 : $at, 0, $add);
	return implode("\n", $lines);
}
function htDrop($text, callable $drop) {
	return implode("\n", array_filter(explode("\n", $text), fn($l) => !$drop($l)));
}
function htSummary(array $r) {
	$c = array();
	foreach($r['candidates'] as $g) $c[] = $g['start'] . '-' . $g['end'] . ':' . implode('+', $g['signatures']);
	return $r['state'] . ' ' . implode(',', $c);
}
function htStatus(StaticPagesHtaccess $h, $file, $bytes) {
	file_put_contents($file, $bytes);
	clearstatcache();
	return $h->status();
}
function crlf($text) {
	return str_replace("\n", "\r\n", $text);
}
//"1-4:S1,6-7:S2" counted from line $first on, as absolute line numbers
function htSpans($spec, $first) {
	if($spec === '' || $spec === 'none') return '';
	$out = array();
	foreach(explode(',', $spec) as $part) {
		list($range, $sig) = explode(':', $part);
		list($a, $b) = explode('-', $range);
		$out[] = ($a + $first - 1) . '-' . ($b + $first - 1) . ':' . $sig;
	}
	return implode(',', $out);
}
$twins = array();

@unlink($htFile);
$r = $h->status();
check('htaccess status: no file gives none, and none is created', $r['state'] === 'none' && $h->filePath() === "$tmpRoot/.htaccess" && !file_exists($htFile) && $r['text'] === null, json_encode(array($r['state'], $h->filePath())));

$block = $h->apacheBlock();
$blockCount = count(explode("\n", $block));
$database->queryLog(true);
$before = files($tmpRoot);
file_put_contents($htFile, $stock);
touch($htFile, time() - 100);
clearstatcache();
$mtime = filemtime($htFile);
$r = $h->status();
clearstatcache();
$readerQueries = count($database->queryLog());
$database->queryLog(false);
check('htaccess status: the stock file gives absent, no candidate, no route', $r['state'] === 'absent' && !$r['candidates'] && !$r['routes'] && $r['pageNameLine'] === 439 && $r['eol'] === "\n", htSummary($r));
check('htaccess status writes nothing and runs no query: the dir listing, the bytes and the mtime are unchanged', files($tmpRoot) === '/.htaccess' && $before === '' && file_get_contents($htFile) === $stock && filemtime($htFile) === $mtime && $readerQueries === 0, files($tmpRoot) . " $readerQueries");
$at = strpos($stock, "\n  # PW-PAGENAME\n") + 1;
check('absent: the previewed text is the fixture with the block inserted immediately before the # PW-PAGENAME line, no other byte changed', $r['text'] === substr($stock, 0, $at) . $block . "\n" . substr($stock, $at) && substr($r['text'], 0, $at) === substr($stock, 0, $at) && substr($r['text'], $at + strlen($block) + 1) === substr($stock, $at));
$withBlock = $r['text'];
$stockCrlf = crlf($stock);
$rc = htStatus($h, $htFile, $stockCrlf);
$atc = strpos($stockCrlf, "\r\n  # PW-PAGENAME\r\n") + 2;
check('absent, CRLF fixture: the inserted lines end in CRLF, no lone LF, no other byte changed', $rc['state'] === 'absent' && $rc['eol'] === "\r\n" && $rc['text'] === substr($stockCrlf, 0, $atc) . crlf($block) . "\r\n" . substr($stockCrlf, $atc) && !preg_match('/(?<!\r)\n/', $rc['text']));

$r = htStatus($h, $htFile, $withBlock);
check('htaccess status: the fixture with the generated block gives current', $r['state'] === 'current' && $r['blockLines'] === array(439, 438 + $blockCount) && $r['text'] === $withBlock, htSummary($r) . ' ' . json_encode($r['blockLines']));
$pos = strpos($withBlock, '# The home page');
$changed = substr_replace($withBlock, 't', $pos + 2, 1);
$r = htStatus($h, $htFile, $changed);
check('one byte changed inside the block gives outdated; the preview puts the generated block back and changes nothing else', strlen($changed) === strlen($withBlock) && $r['state'] === 'outdated' && $r['text'] === $withBlock, htSummary($r));
$r = $h->classify(crlf($changed));
check('outdated, CRLF fixture: the preview is the CRLF file with the generated block, no lone LF', $r['state'] === 'outdated' && $r['text'] === crlf($withBlock));
$sp->set('staticFile', 'cache.htm');
$r = $h->classify($withBlock);
check('a block written for another Static file name gives outdated', $r['state'] === 'outdated' && strpos($r['text'], 'cache\\.htm') !== false, htSummary($r));
$sp->set('staticFile', 'index.html');
$twins += array('stock' => $stock, 'with block' => $withBlock, 'changed block' => $changed);

$damaged = array(
	'a BEGIN without its END' => htInsert($stock, '# PW-PAGENAME', array('# BEGIN StaticPages')),
	'two blocks' => htInsert($withBlock, '# END-PW-PAGENAME', explode("\n", $block), true),
	'END before BEGIN' => htInsert($stock, '# PW-PAGENAME', array('# END StaticPages', '# BEGIN StaticPages')),
);
$unrecognised = array(
	'no START PROCESSWIRE line' => htDrop($stock, fn($l) => strpos($l, 'START PROCESSWIRE HTACCESS DIRECTIVES') !== false),
	'no # PW-PAGENAME line' => htDrop($stock, fn($l) => trim($l) === '# PW-PAGENAME'),
	'two # PW-PAGENAME lines' => htInsert($stock, '# PW-PAGENAME', array('  # PW-PAGENAME')),
);
foreach(array('damaged' => $damaged, 'unrecognised' => $unrecognised) as $state => $set) {
	foreach($set as $label => $text) {
		$r = htStatus($h, $htFile, $text);
		check("htaccess status: $label gives $state, no preview", $r['state'] === $state && $r['text'] === null, htSummary($r) . ' ' . $r['reason']);
		$twins["$state: $label"] = $text;
	}
}

file_put_contents($htFile, $stock);
@chmod($htFile, 0444);
clearstatcache();
if(is_writable($htFile)) {
	echo "skip a read-only .htaccess gives locked: this platform cannot make a read-only file\n";
} else {
	$r = $h->status();
	check('htaccess status: a read-only file gives locked, no preview', $r['state'] === 'locked' && $r['text'] === null && !$r['candidates'], $r['state']);
}
@chmod($htFile, 0666);
@unlink($htFile);
@mkdir($htFile);
$r = $h->status();
check('htaccess status: a directory in place of the file gives locked', $r['state'] === 'locked', $r['state']);
@rmdir($htFile);

//signatures: each rule added to the stock fixture before # PW-PAGENAME (line 439 on)
$hand = array(
	//README.md:312-316 at 26a2af1
	'the README\'s first rule' => array(array('# A directory holding a static file, requested without a query string: serve the file.', 'RewriteCond %{REQUEST_FILENAME} -d', 'RewriteCond %{QUERY_STRING} ^$', 'RewriteCond %{REQUEST_FILENAME}/index.html -f', 'RewriteRule ^(.+)/$ /$1/index.html [L]'), '2-5:S1'),
	//README.md:318-320
	'the README\'s second rule' => array(array('# A directory with a query string, or with no static file in it yet: hand it to PW.', 'RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]'), '2-3:S2'),
	//README.md:330-337
	'the README\'s home pair' => array(array('RewriteCond %{REQUEST_URI} ^/?$', 'RewriteCond %{QUERY_STRING} ^$', 'RewriteCond %{DOCUMENT_ROOT}/index.html -f', 'RewriteRule ^$ /index.html [L]', '', 'RewriteCond %{REQUEST_URI} ^/?$', 'RewriteRule ^$ /index.php [L,QSA]'), '1-4:S1,6-7:S2'),
	'a %{DOCUMENT_ROOT}/%1index.html rule' => array(array('RewriteCond %{REQUEST_URI} ^/((?:[-_.a-zA-Z0-9]+/)*)$', 'RewriteCond %{DOCUMENT_ROOT}/%1index.html -f', 'RewriteRule ^ %1index.html [L]'), '1-3:S1'),
	'a per-host home rule' => array(array('RewriteCond %{HTTP_HOST} ^(www\.)?example\.nl$ [NC]', 'RewriteRule ^$ /nl/index.html [L]'), '1-2:S1'),
);
$withIndex = function($name, $lines) use ($stock) {
	return $name === 'DirectoryIndex'
		? htInsert($stock, 'DirectoryIndex index.php index.html index.htm', $lines, true)
		: htInsert($stock, '# PW-PAGENAME', $lines);
};
$hand['DirectoryIndex'] = array(array('DirectoryIndex index.html index.php'), '1-1:S3');
$bad = array();
foreach($hand as $label => list($lines, $spec)) {
	$first = $label === 'DirectoryIndex' ? 171 : 439;
	$text = $withIndex($label, $lines);
	$r = htStatus($h, $htFile, $text);
	$want = 'candidate ' . htSpans($spec, $first);
	$exact = true;
	foreach($r['candidates'] as $g) foreach($g['lines'] as $n => $l) if($l !== $lines[$n - $first]) $exact = false;
	if(htSummary($r) !== $want || !$exact) $bad[] = "$label: " . htSummary($r) . " (want $want)";
	$twins["signature: $label"] = $text;
}
check('htaccess heuristic: the README\'s rules, a %{DOCUMENT_ROOT}/%1index.html rule, a per-host home rule and DirectoryIndex index.html are candidates with exactly their group\'s lines', !count($bad), implode('; ', $bad));

//the same rules written for another Static file name: those naming index.html are not candidates then
$sp->set('staticFile', 'cache.htm');
$bad = array();
foreach($hand as $label => list($lines, $spec)) {
	$first = $label === 'DirectoryIndex' ? 171 : 439;
	$mine = array_map(fn($l) => str_replace('index.html', 'cache.htm', $l), $lines);
	$r = $h->classify($withIndex($label, $mine));
	if(htSummary($r) !== 'candidate ' . htSpans($spec, $first)) $bad[] = "$label for cache.htm: " . htSummary($r);
	$r = $h->classify($withIndex($label, $lines));
	$left = implode(',', array_filter(explode(',', $spec), fn($s) => substr($s, -3) === ':S2'));
	if(htSummary($r) !== ($left === '' ? 'absent ' : 'candidate ' . htSpans($left, $first))) $bad[] = "$label for index.html: " . htSummary($r);
}
$sp->set('staticFile', 'index.html');
check('htaccess heuristic with Static file name cache.htm: the rules written for cache.htm are candidates, those naming index.html are not', !count($bad), implode('; ', $bad));

//not candidates
$bad = array();
foreach($hand as $label => list($lines, $spec)) {
	$r = $h->classify($withIndex($label, array_map(fn($l) => $l === '' ? '' : "# $l", $lines)));
	if(htSummary($r) !== 'absent ') $bad[] = "$label: " . htSummary($r);
}
check('htaccess heuristic: each of those rules commented out is no candidate', !count($bad), implode('; ', $bad));
//sections 9A, 9B, 13A to 13C, 14, 15B, 16B, 18 and 20 of htaccess.txt (@htaccessVersion 301)
$optional = array(array(213, 214), array(219, 220), array(335, 338), array(342, 343), array(347, 348), array(358, 361), array(434, 436), array(453, 453), array(476, 477), array(490, 490));
$lines = explode("\n", $stock);
$uncommented = 0;
foreach($optional as list($a, $b)) for($n = $a; $n <= $b; $n++) {
	if(!preg_match('/^(\s*)# (Rewrite(Cond|Rule) .*)$/', $lines[$n - 1], $m)) continue;
	$lines[$n - 1] = $m[1] . $m[2];
	$uncommented++;
}
$allOptional = implode("\n", $lines);
$r = $h->classify($allOptional);
check('htaccess heuristic: the stock file with every optional Rewrite section uncommented (9A, 9B, 13A to 13C, 14, 15B, 16B, 18, 20) holds no candidate; its DirectoryIndex and rule 19 neither', $uncommented === 23 && htSummary($r) === 'absent ' && !$r['routes'], "$uncommented " . htSummary($r));
$twins['every optional section'] = $allOptional;
$route = htInsert($stock, '# PW-PAGENAME', array('RewriteRule ^sitemap\.xml$ index.php?it=/sitemap/ [L,QSA]'));
$r = $h->classify($route);
check('htaccess heuristic: a root file route before # PW-PAGENAME is no candidate, it is reported as a route', htSummary($r) === 'absent ' && count($r['routes']) === 1 && $r['routes'][0]['line'] === 439 && $r['routes'][0]['name'] === 'sitemap.xml', json_encode($r['routes']));
$twins['root file route'] = $route;

//[decided by user, step 3] S4: a group conditioned on the request method; S2: any hand rule to
//index.php beside stock's own; a directory slash redirect alone is no candidate
$method = htInsert($stock, '# PW-PAGENAME', array('RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$', 'RewriteCond %{REQUEST_URI} ^/((?:[-_.a-zA-Z0-9]+/)*)$', 'RewriteRule ^ gate.php [L]'));
$r = $h->classify($method);
check('htaccess heuristic: a hand rule conditioned on the request method is a candidate (S4), whatever it names', htSummary($r) === 'candidate 439-441:S4', htSummary($r));
$lines = explode("\n", $stock);
$lines[307] = preg_replace('/^(\s*)# /', '$1', $lines[307]);
$lines[308] = preg_replace('/^(\s*)# /', '$1', $lines[308]);
$r = $h->classify(implode("\n", $lines));
check('htaccess heuristic: the stock section 11E method block, uncommented, is a candidate too (S4, a false positive costs a warning)', htSummary($r) === 'candidate 308-309:S4', htSummary($r));
$toPhp = htInsert($stock, '# PW-PAGENAME', array('RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]', 'RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]', 'RewriteCond %{HTTP_HOST} ^(www\.)?example\.org$ [NC]', 'RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]', 'RewriteCond %{HTTP_HOST} ^(www\.)?example\.net$ [NC]', 'RewriteRule  ^(.*)$  /index.php?it=$1  [L,QSA]'));
$r = $h->classify($toPhp);
check('htaccess heuristic: a hand rule to index.php without -d or ^$ is a candidate (S2); one written like stock rule 19 or 20 is not', htSummary($r) === 'candidate 439-440:S2', htSummary($r));
$slash = htInsert($stock, '# PW-PAGENAME', array('RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^(.+[^/])$ /$1/ [R=301,L]'));
check('htaccess heuristic: a directory slash redirect alone is no candidate', htSummary($h->classify($slash)) === 'absent ');
$twins += array('S4 method' => $method, 'S2 to index.php' => $toPhp, 'slash redirect' => $slash);

//a group's lines run from its first RewriteCond to its RewriteRule, comments between included
$spans = array('RewriteCond %{REQUEST_FILENAME} -d', '# between the conditions', 'RewriteCond %{QUERY_STRING} ^$', '', 'RewriteCond %{REQUEST_FILENAME}/index.html -f', 'RewriteRule ^(.+)/$ /$1/index.html [L]', '# between the groups', 'RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]');
$text = htInsert($stock, '# PW-PAGENAME', $spans);
$r = $h->classify($text);
check('htaccess heuristic: a group runs from its first RewriteCond to its RewriteRule, comments and blank lines between included, and never into the next group', htSummary($r) === 'candidate 439-444:S1,446-447:S2' && array_keys($r['candidates'][0]['lines']) === range(439, 444) && $r['candidates'][0]['lines'][440] === '# between the conditions' && !isset($r['candidates'][1]['lines'][445]), htSummary($r));
$twins['spans'] = $text;
//placed after # END-PW-PAGENAME, a hand rule takes the stock 16A condition above it: mod_rewrite
//gives every condition to the next rule, so its group starts on that stock line
$text = htInsert($stock, '# END-PW-PAGENAME', $hand['the README\'s first rule'][0], true);
$r = $h->classify($text);
check('htaccess heuristic: a hand rule right after # END-PW-PAGENAME is a group starting at the stock 16A condition (line 445)', htSummary($r) === 'candidate 445-460:S1' && trim($r['candidates'][0]['lines'][445]) === 'RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"', htSummary($r));
$twins['after END-PW-PAGENAME'] = $text;

//rule groups in the shapes local sites hold, anonymised (Q4 of sprint b-c-file-types)
$sections = array();
$name = null;
foreach(explode("\n", (string) file_get_contents(__DIR__ . '/fixtures/htaccess-hand-rules.txt')) as $line) {
	if(strpos($line, '#### ') === 0) {
		$parts = array_map('trim', explode('|', substr($line, 5)));
		$name = $parts[0];
		$sections[$name] = array('where' => $parts[1], 'candidates' => $parts[2], 'routes' => $parts[3], 'lines' => array());
	} else if($name !== null) {
		$sections[$name]['lines'][] = $line;
	}
}
$bad = array();
foreach($sections as $name => $s) {
	while(count($s['lines']) && trim(end($s['lines'])) === '') array_pop($s['lines']);
	$after = $s['where'] === 'after';
	$first = $after ? 456 : 439;
	$text = $after ? htInsert($stock, '# END-PW-PAGENAME', $s['lines'], true) : htInsert($stock, '# PW-PAGENAME', $s['lines']);
	$r = $h->classify($text);
	$want = $s['candidates'] === 'none' ? 'absent ' : 'candidate ' . htSpans($s['candidates'], $first);
	$routes = implode(',', array_map(fn($x) => $x['line'] - $first + 1, $r['routes']));
	if(htSummary($r) !== $want || $routes !== ($s['routes'] === 'none' ? '' : $s['routes'])) $bad[] = "$name: " . htSummary($r) . " routes $routes (want $want)";
	$twins["sites: $name"] = $text;
}
check('htaccess heuristic: the anonymised rule groups of local sites give exactly the expected candidates and routes (' . count($sections) . ' fixtures)', count($sections) === 6 && !count($bad), implode('; ', $bad));

//a CRLF copy of each fixture gives the same state and candidates as its LF twin
$bad = array();
foreach($twins as $label => $text) {
	$lf = $h->classify($text);
	$cr = $h->classify(crlf($text));
	if(htSummary($lf) !== htSummary($cr) || json_encode($lf['candidates']) !== json_encode($cr['candidates']) || json_encode($lf['routes']) !== json_encode($cr['routes'])) $bad[] = $label;
}
check('htaccess status: a CRLF copy of each fixture gives the same state, candidates and routes as its LF twin (' . count($twins) . ' fixtures)', count($twins) >= 20 && !count($bad), implode('; ', $bad));

//the settings form: built under the install's own root, where core finds the module's config
//file (see "config file and module info" below); the part reads a fixture on the temporary root
$form = null;
$formRoot = $config->paths->root;
$formFixture = htInsert($stock, '# PW-PAGENAME', array_merge($hand['the README\'s first rule'][0], array('RewriteCond %{HTTP_USER_AGENT} "<script>x</script>"', 'RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]')));
file_put_contents($htFile, $formFixture);
$sp->htaccess()->file = $htFile;
$config->paths->root = "$root/";
$values = array();
foreach(array('candidate' => $htFile, 'none' => "$tmpRoot/sp-ht-none/.htaccess", 'locked' => "$tmpRoot/sp-ht-dir") as $label => $file) {
	if($label === 'locked') @mkdir("$file", 0777, true);
	$sp->htaccess()->file = $file;
	$form = $modules->getModuleConfigInputfields('StaticPages');
	$f = $form ? $form->getChildByName('_htaccess') : null;
	$b = $form ? $form->getChildByName('_htaccessBlock') : null;
	$values[$label] = array($f ? (string) $f->value : '', $b ? (string) $b->value : '');
}
//without index tables: the index part answers "no tables" as after a failed check
$tablesReady = new \ReflectionProperty(StaticPagesIndex::class, 'tablesReady');
$tablesReady->setAccessible(true);
$tablesAtStart = $tablesReady->getValue($sp->index());
$tablesReady->setValue($sp->index(), false);
$sp->htaccess()->file = $htFile;
$form = $modules->getModuleConfigInputfields('StaticPages');
$f = $form ? $form->getChildByName('_htaccess') : null;
$values['no tables'] = array($f ? (string) $f->value : '', $sp->index()->ensureTables() === false);
$notesNoTables = $f ? (string) $f->notes : '';
$tablesReady->setValue($sp->index(), $tablesAtStart);
$config->paths->root = $formRoot;
$sp->htaccess()->file = '';
@rmdir("$tmpRoot/sp-ht-dir");
$encoded = $wire->sanitizer->entities($block);
list($v, $vb) = $values['candidate'];
check('the settings form holds the .htaccess state, the candidates with their line numbers and the block', strpos($v, 'State: candidate') !== false && strpos($v, '  440  RewriteCond %{REQUEST_FILENAME} -d') !== false && strpos($v, '  443  RewriteRule ^(.+)/$ /$1/index.html [L]') !== false && strpos($v, 'Lines 440 to 443, S1') !== false && strpos($v, 'Lines 444 to 446, S2') !== false && strpos($vb, $encoded) !== false, substr($v, 0, 600));
check('the settings form encodes what the file holds: no markup from the .htaccess reaches the page', strpos($v, '<script>') === false && strpos($v, '&lt;script&gt;') !== false && strpos($encoded, '&quot;') !== false);
check('the settings form builds without a .htaccess (none) and with a directory in its place (locked)', strpos($values['none'][0], 'State: none') !== false && strpos($values['locked'][0], 'State: locked') !== false && strpos($values['none'][1], $encoded) !== false, json_encode(array(substr($values['none'][0], 0, 120), substr($values['locked'][0], 0, 120))));
check('the settings form builds without index tables and still shows the .htaccess state', strpos($values['no tables'][0], 'State: candidate') !== false && $values['no tables'][1] === true, substr($values['no tables'][0], 0, 120));
check('the settings form tells the operator to add a rule above the block for a directory made by hand', strpos($notesNoTables, 'made by hand') !== false && strpos($notesNoTables, 'RewriteRule ^landing/ - [L]') !== false && strpos($notesNoTables, 'index.htm') !== false, $notesNoTables);
check('htaccessStatus() is the reader\'s status() of the root .htaccess', $sp->htaccessStatus()['state'] === 'candidate' && $sp->htaccessStatus()['file'] === $htFile);

@unlink($htFile);
check('the .htaccess checks leave the temporary root empty', files($tmpRoot) === '', files($tmpRoot));

////  .htaccess writer (0.10.0)

//write(), Replace and remove() on fixtures on the temporary root only: $h->file is '', so the
//part works on <temporary root>/.htaccess. The fetch is a stub where one is asked: under CLI
//the writer makes no request of its own
$prefix = StaticPagesHtaccess::replacedPrefix;
check('htaccess writer: the part works on the temporary root\'s .htaccess', $h->filePath() === $htFile && strpos($htFile, $tmp) === 0);

$keys = preg_split('/\s+/', trim(StaticPagesHtaccess::stockLineKeys));
check('htaccess writer: the stock line keys kept in the part are those of the stock fixture (every line, and its commented-out directives uncommented)', $keys === $h->stockKeysOf($stock) && count($keys) > 262, count($keys) . ' kept, ' . count($h->stockKeysOf($stock)) . ' from the fixture');
check('htaccess writer: stock lines are known whatever their indentation, blank lines count as stock, hand lines do not', $h->isStockLine('RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"') && $h->isStockLine("\t RewriteCond  %{REQUEST_FILENAME} !-f ") && $h->isStockLine('') && $h->isStockLine('  ') && $h->isStockLine('  RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]') && $h->isStockLine('RewriteCond %{REQUEST_URI} !\.(jpg|jpeg|gif|png|ico|webp|svg|js|css)$ [NC]') && !$h->isStockLine('RewriteCond %{REQUEST_FILENAME} -d') && !$h->isStockLine('RewriteRule ^(.+)$ index.php?it=$1 [L,QSA]') && !$h->isStockLine('DirectoryIndex index.html index.php') && !$h->isStockLine('# A directory holding a static file, requested without a query string: serve the file.'));

//$add inserted as by htInsert(), and the line numbers the added lines get
function htHand($text, $marker, array $add, $after = false) {
	$at = null;
	foreach(explode("\n", $text) as $i => $l) if(trim($l) === $marker) { $at = $i; break; }
	$first = ($after ? $at + 1 : $at) + 1;
	return array(htInsert($text, $marker, $add, $after), range($first, $first + count($add) - 1));
}
function htPut($file, $bytes) {
	file_put_contents($file, $bytes);
	clearstatcache();
}
function htBackups($tmpRoot) {
	return array_values(array_filter(scandir($tmpRoot), fn($n) => preg_match('/^\.htaccess\.sp-.*\.bak$/', $n)));
}
//temporary files of the writer: never deleted by the tests, so that a leftover one shows
function htTmp($tmpRoot) {
	return array_values(array_filter(scandir($tmpRoot), fn($n) => substr($n, -4) === '.tmp'));
}
function htClean($tmpRoot) {
	foreach(htBackups($tmpRoot) as $n) @unlink("$tmpRoot/$n");
	@unlink("$tmpRoot/.htaccess");
	clearstatcache();
}
//the text written by a Replace against its fixture: our block taken out, and the stock
//DirectoryIndex line written after a prefixed one; then every line equals the fixture's, or is
//one of the fixture's hand lines ($hand, line numbers) with the prefix. array(problems, the
//fixture's line numbers that carry the prefix)
function htCompare($fixture, $new, array $hand) {
	$prefix = StaticPagesHtaccess::replacedPrefix;
	$out = array();
	$inBlock = false;
	$prev = '';
	foreach(explode("\n", $new) as $l) {
		$t = trim($l);
		if($t === '# BEGIN StaticPages') { $inBlock = true; continue; }
		if($inBlock) { if($t === '# END StaticPages') $inBlock = false; continue; }
		if($t === StaticPagesHtaccess::stockDirectoryIndex && strpos($prev, $prefix) === 0 && preg_match('/^\s*DirectoryIndex\s/i', substr($prev, strlen($prefix)))) { $prev = $l; continue; }
		$out[] = $l;
		$prev = $l;
	}
	$fl = explode("\n", $fixture);
	if(count($out) !== count($fl)) return array(array('line count ' . count($out) . ' against ' . count($fl)), array());
	$bad = $prefixed = array();
	foreach($fl as $i => $l) {
		if($out[$i] === $l) continue;
		if(in_array($i + 1, $hand, true) && $out[$i] === $prefix . $l) $prefixed[] = $i + 1;
		else $bad[] = ($i + 1) . ': ' . trim($out[$i]);
	}
	return array($bad, $prefixed);
}
//the hand lines of the S1/S2 candidate groups of a status: what a Replace must prefix
function htExpected(StaticPagesHtaccess $h, array $status, array $hand) {
	$want = array();
	foreach($status['candidates'] as $g) {
		if($g['signatures'] === array('S4') || $g['signatures'] === array('S3')) continue;
		foreach($g['lines'] as $n => $t) if(in_array($n, $hand, true) && !$h->isStockLine($t)) $want[] = $n;
	}
	sort($want);
	return $want;
}
function htLoneLf($text) {
	return (bool) preg_match('/(?<!\r)\n/', $text);
}

htClean($tmpRoot);
$h->fetch = null;
$h->reread = null;

//absent: the block goes in, after one backup
htPut($htFile, $stock);
$perms = fileperms($htFile);
$r = $h->write();
$after = $h->status();
$bk = htBackups($tmpRoot);
check('htaccess write, absent: gives current; the file is the fixture with the block immediately before # PW-PAGENAME and nothing else changed', $r['ok'] && $r['done'] === 'written' && $r['state'] === 'absent' && $after['state'] === 'current' && file_get_contents($htFile) === $withBlock, $r['message']);
check('htaccess write, absent: one .htaccess.sp-*.bak holding the fixture\'s bytes, named in the result; the file\'s permissions are the fixture\'s; no request made under CLI', count($bk) === 1 && preg_match('/^\.htaccess\.sp-\d{14}\.bak$/', $bk[0]) && $r['backup'] === $bk[0] && file_get_contents("$tmpRoot/{$bk[0]}") === $stock && fileperms($htFile) === $perms && $r['fetch'] === null, json_encode($bk));
check('htaccess write logs one line naming the backup', substr_count(logText($tmp), "backup {$bk[0]}") === 1 && strpos(logText($tmp), 'the StaticPages block was added') !== false);
touch($htFile, time() - 100);
clearstatcache();
$mtime = filemtime($htFile);
$r = $h->write();
clearstatcache();
check('htaccess write, current: a second write changes nothing and makes no backup', $r['ok'] && $r['done'] === 'nothing' && $r['backup'] === '' && htBackups($tmpRoot) === $bk && filemtime($htFile) === $mtime && file_get_contents($htFile) === $withBlock, $r['done']);

//outdated: the block's lines only
$sp->set('staticFile', 'cache.htm');
$r = $h->write();
$new = (string) file_get_contents($htFile);
$begin = strpos($withBlock, '# BEGIN StaticPages');
$end = strpos($withBlock, '# END StaticPages') + strlen('# END StaticPages');
$nbegin = strpos($new, '# BEGIN StaticPages');
$nend = strpos($new, '# END StaticPages') + strlen('# END StaticPages');
check('htaccess write, outdated: replaces the block only, the bytes before and after it unchanged', $r['ok'] && $r['state'] === 'outdated' && $h->status()['state'] === 'current' && substr($new, 0, $nbegin) === substr($withBlock, 0, $begin) && substr($new, $nend) === substr($withBlock, $end) && strpos($new, 'cache\\.htm') !== false && count(htBackups($tmpRoot)) === 2, $r['message']);
$sp->set('staticFile', 'index.html');
$r = $h->write();
check('htaccess write, outdated again: back to the block of these settings', $r['ok'] && file_get_contents($htFile) === $withBlock);

//remove(): a plain current gives the stock fixture back, absent does nothing
$r = $h->remove();
check('htaccess remove, current: gives back the stock fixture byte for byte, after a backup', $r['ok'] && $r['done'] === 'written' && file_get_contents($htFile) === $stock && $r['backup'] !== '' && file_get_contents("$tmpRoot/{$r['backup']}") === $withBlock, $r['message']);
$count = count(htBackups($tmpRoot));
$r = $h->remove();
check('htaccess remove, absent: does nothing, no backup', $r['ok'] && $r['done'] === 'nothing' && file_get_contents($htFile) === $stock && count(htBackups($tmpRoot)) === $count, $r['done']);
htClean($tmpRoot);

//the README's rule for a directory made by hand, above # PW-PAGENAME before the block exists: no
//candidate, the block goes in below it, and a rewrite of the block keeps it above [A1.2]
$exclusion = array('# By hand: landing/ is a directory made by hand, served as the stock rules serve it.', 'RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^landing/ - [L]');
$excluded = htInsert($stock, '# PW-PAGENAME', $exclusion);
htPut($htFile, $excluded);
$before = $h->status();
$r = $h->write();
$new = (string) file_get_contents($htFile);
$want = htInsert($excluded, '# PW-PAGENAME', explode("\n", $block));
check('htaccess write: a hand rule for a directory made by hand above # PW-PAGENAME is no candidate; the block goes in between it and # PW-PAGENAME, nothing else changed', $before['state'] === 'absent' && !$before['candidates'] && $r['ok'] && $r['done'] === 'written' && $new === $want && $h->status()['state'] === 'current', htSummary($before) . ' ' . $r['message']);
$sp->set('staticFile', 'cache.htm');
$r = $h->write();
$sp->set('staticFile', 'index.html');
$r2 = $h->write();
check('htaccess write, outdated with the hand rule right above the block: rewritten, the hand rule kept above it', $r['ok'] && $r['done'] === 'written' && $r['state'] === 'outdated' && $r2['ok'] && $r2['state'] === 'outdated' && file_get_contents($htFile) === $want, $r['message'] . ' ' . $r2['message']);
htClean($tmpRoot);

//candidate: refused without Replace; with it, the hand lines of every S1 and S2 group get the
//prefix, the stock lines stay, the block goes in; remove() gives the fixture back
$readme = array_merge($hand['the README\'s first rule'][0], $hand['the README\'s second rule'][0], $hand['the README\'s home pair'][0]);
list($fixture, $handLines) = htHand($stock, '# PW-PAGENAME', $readme);
htPut($htFile, $fixture);
$listing = files($tmpRoot);
$r = $h->write();
check('htaccess write, candidate without Replace: refused, the file and the dir listing unchanged', !$r['ok'] && $r['done'] === 'refused' && $r['state'] === 'candidate' && strpos($r['message'], 'Replace or Keep') !== false && file_get_contents($htFile) === $fixture && files($tmpRoot) === $listing, $r['message']);
$status = $h->status();
$want = htExpected($h, $status, $handLines);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
$after = $h->status();
list($bad, $prefixed) = htCompare($fixture, $new, $handLines);
check('htaccess Replace: every hand line of every S1 and S2 group carries the prefix, every other line is byte-identical, the block is in place, current with no candidate', $r['ok'] && $r['done'] === 'written' && $after['state'] === 'current' && !$after['candidates'] && !count($bad) && $prefixed === $want && $r['prefixed'] === $want && count($want) === 12 && !$r['regrouped'] && !$r['directoryIndex'], json_encode(array($r['message'], $bad, $prefixed, $want)));
$r = $h->remove();
check('htaccess remove after a Replace: gives back the candidate fixture byte for byte', $r['ok'] && file_get_contents($htFile) === $fixture && $r['prefixed'] === $want, $r['message']);
htClean($tmpRoot);

//[decided by user, step 4] a hand DirectoryIndex naming the copy first (S3): prefixed, and the
//stock line written right after it; remove() takes both back
list($fixture, $handLines) = htHand($stock, 'DirectoryIndex index.php index.html index.htm', array('DirectoryIndex index.html index.php'), true);
list($fixture, $more) = htHand($fixture, '# PW-PAGENAME', $hand['the README\'s first rule'][0]);
$handLines = array_merge($handLines, $more);
htPut($htFile, $fixture);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
$after = $h->status();
list($bad, $prefixed) = htCompare($fixture, $new, $handLines);
check('htaccess Replace, S3: the hand DirectoryIndex line is prefixed and the stock DirectoryIndex line follows it; named in the result; current', $r['ok'] && $after['state'] === 'current' && strpos($new, "DirectoryIndex index.php index.html index.htm\n{$prefix}DirectoryIndex index.html index.php\nDirectoryIndex index.php index.html index.htm\n") !== false && $r['directoryIndex'] === array(171) && strpos(implode(' ', $r['messages']), 'DirectoryIndex') !== false && !count($bad) && in_array(171, $prefixed, true), json_encode(array($r['message'], $bad, $prefixed)));
$r = $h->remove();
check('htaccess remove after a Replace with S3: the stock line it added goes, the hand line is back, the fixture byte for byte', $r['ok'] && file_get_contents($htFile) === $fixture && $r['directoryIndex'] === array(172), json_encode(array($r['message'], $r['directoryIndex'])));
htClean($tmpRoot);

//[changed by user decision 2026-11-17] a candidate between the stock 16A condition and rule 19:
//Replace succeeds, the 16A line stays and joins rule 19 again
list($fixture, $handLines) = htHand($stock, '# END-PW-PAGENAME', array_merge($hand['the README\'s first rule'][0], $hand['the README\'s second rule'][0]), true);
htPut($htFile, $fixture);
$status = $h->status();
$want = htExpected($h, $status, $handLines);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
$after = $h->status();
list($bad, $prefixed) = htCompare($fixture, $new, $handLines);
$newLines = $h->lines($new);
$active = array();
foreach($newLines as $n => $l) if(preg_match('/^\s*Rewrite(Cond|Rule)\s/', $l['text']) && $n > $after['blockLines'][1]) $active[] = trim($l['text']);
check('htaccess Replace, a candidate between 16A and rule 19: succeeds, every stock line byte-identical, every hand line of the group prefixed, the block in place, nothing regrouped', $status['candidates'][0]['start'] === 445 && $r['ok'] && $after['state'] === 'current' && !count($bad) && $prefixed === $want && count($want) === 7 && !$r['regrouped'], json_encode(array($r['message'], $bad, $prefixed, $want, $status['candidates'][0]['start'])));
check('htaccess Replace, a candidate between 16A and rule 19: below the block the active rewrite lines are the stock 16A, section 17 and rule 19 again', $active === array('RewriteCond %{REQUEST_URI} "^/~?[-_.a-zA-Z0-9/]*$"', 'RewriteCond %{REQUEST_FILENAME} !-f', 'RewriteCond %{REQUEST_FILENAME} !-d', 'RewriteCond %{REQUEST_FILENAME} !(favicon\.ico|robots\.txt)', 'RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]'), json_encode($active));
$r = $h->remove();
check('htaccess remove after that Replace: the fixture byte for byte', $r['ok'] && file_get_contents($htFile) === $fixture);
htClean($tmpRoot);

//[decided by user, step 4] the shape of several local sites: a rule the heuristic does not count
//(an exclusion list) between the hand rules; the 16A line stays and joins it, and the result
//says so
$shape = $sections['index-url-redirect']['lines'];
while(count($shape) && trim(end($shape)) === '') array_pop($shape);
list($fixture, $handLines) = htHand($stock, '# END-PW-PAGENAME', $shape, true);
htPut($htFile, $fixture);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
list($bad, $prefixed) = htCompare($fixture, $new, $handLines);
$exclusions = array_search('RewriteRule ^(api|assets|admin|stats|favicon\.ico|robots\.txt)/ - [L]', $shape, true) + $handLines[0];
check('htaccess Replace, a rule the heuristic does not count between 16A and rule 19: goes ahead, the 16A line stays and is named with the rule it joins now', $r['ok'] && $h->status()['state'] === 'current' && !count($bad) && count($r['regrouped']) === 1 && $r['regrouped'][0]['line'] === 445 && $r['regrouped'][0]['ruleLine'] === $exclusions && strpos(implode(' ', $r['messages']), "line 445 stays and from now on belongs to the hand-written rule on line $exclusions") !== false && strpos($new, "\n" . $shape[array_search('RewriteRule ^(api|assets|admin|stats|favicon\.ico|robots\.txt)/ - [L]', $shape, true)] . "\n") !== false, json_encode(array($r['message'], $r['regrouped'], $bad)));
$r = $h->remove();
check('htaccess remove after that Replace: the fixture byte for byte', $r['ok'] && file_get_contents($htFile) === $fixture);
htClean($tmpRoot);

//[decided by user, step 4] a group conditioned on the request method only (S4) is never
//prefixed; the block must go below it, else Replace is refused
$gate = array('RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$', 'RewriteCond %{REQUEST_URI} ^/((?:[-_.a-zA-Z0-9]+/)*)$', 'RewriteRule ^ gate.php [L]');
list($fixture, $handLines) = htHand($stock, '# PW-PAGENAME', array_merge($gate, $hand['the README\'s first rule'][0]));
htPut($htFile, $fixture);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
$after = $h->status();
list($bad, $prefixed) = htCompare($fixture, $new, $handLines);
check('htaccess Replace with an S4 group above the block: the S4 group stays as it is and is named, the rest is replaced; the state stays candidate with that group only', $r['ok'] && $r['kept'] === array('439-441') && $prefixed === array(443, 444, 445, 446) && !count($bad) && $after['state'] === 'candidate' && count($after['candidates']) === 1 && $after['candidates'][0]['signatures'] === array('S4') && strpos($new, "\nRewriteRule ^ gate.php [L]\n") !== false, json_encode(array($r['message'], $prefixed, $bad, htSummary($after))));
$r = $h->remove();
check('htaccess remove with the S4 group left: the fixture byte for byte', $r['ok'] && file_get_contents($htFile) === $fixture, $r['message']);
htClean($tmpRoot);
list($fixture) = htHand($stock, '# END-PW-PAGENAME', array_merge($hand['the README\'s first rule'][0], $gate), true);
htPut($htFile, $fixture);
$listing = files($tmpRoot);
$r = $h->write(false, true);
check('htaccess Replace with an S4 group below the place of the block: refused with the reason, the file and the dir listing unchanged', !$r['ok'] && $r['done'] === 'refused' && strpos($r['message'], 'request method only') !== false && file_get_contents($htFile) === $fixture && files($tmpRoot) === $listing, $r['message']);
htClean($tmpRoot);

//a hand line equal to a stock line is never prefixed: left above the block, with no rule
//between, it would join the block's first rule, so the write is refused
list($fixture) = htHand($stock, '# PW-PAGENAME', array('RewriteCond %{REQUEST_FILENAME} !-f', 'RewriteCond %{REQUEST_FILENAME}/index.html -f', 'RewriteRule ^(.+)/$ /$1/index.html [L]'));
htPut($htFile, $fixture);
$listing = files($tmpRoot);
$r = $h->write(false, true);
check('htaccess Replace: a condition that would stand right above the block is refused, nothing written', !$r['ok'] && strpos($r['message'], 'right above the StaticPages block') !== false && file_get_contents($htFile) === $fixture && files($tmpRoot) === $listing, $r['message']);
htClean($tmpRoot);
//[decided by user, step 4] the same refusal for a plain write: a hand condition with no
//signature right above # PW-PAGENAME would join the block's first rule
list($fixture) = htHand($stock, '# PW-PAGENAME', array('RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]'));
htPut($htFile, $fixture);
$listing = files($tmpRoot);
$st = $h->status()['state'];
$r = $h->write();
check('htaccess write, absent: a hand condition right above the place of the block is refused, nothing written', $st === 'absent' && !$r['ok'] && strpos($r['message'], 'line 439 would stand right above the StaticPages block') !== false && file_get_contents($htFile) === $fixture && files($tmpRoot) === $listing, $r['message']);
htClean($tmpRoot);

//[decided by user, step 4] a hand rule written like a stock line is kept by Replace: when its
//hand conditions would be prefixed it would apply to every request, so Replace is refused
$bad = array();
$stockText = array(
	'a rule written like the stock rule 19' => array('RewriteCond %{REQUEST_FILENAME} -d', 'RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]'),
	'a rule written like a stock optional rule' => array('RewriteCond %{REQUEST_FILENAME}/index.html -f', 'RewriteRule .* - [F,L]'),
);
foreach($stockText as $label => $lines) {
	list($fixture) = htHand($stock, '# PW-PAGENAME', $lines);
	htPut($htFile, $fixture);
	$listing = files($tmpRoot);
	$st = $h->status();
	$r = $h->write(false, true);
	$d = $h->write(true, true);
	if($st['state'] !== 'candidate' || !$h->isStockLine($lines[1]) || $r['ok'] || $d['ok'] || strpos($r['message'], 'the rule on line 440 is written like a line of the stock .htaccess') === false || strpos($r['message'], 'condition on line 439 above it') === false || file_get_contents($htFile) !== $fixture || files($tmpRoot) !== $listing) $bad[] = "$label: " . htSummary($st) . " {$r['message']}";
	htClean($tmpRoot);
}
check('htaccess Replace: a rule written like a stock line whose hand conditions would be prefixed is refused with the reason, also in a dry run; nothing written', !count($bad), implode('; ', $bad));

//[decided by user, audit 1, A1.1] the site's own rule 19 written differently from stock is an S2
//candidate (classification unchanged); Replace would comment out the last catch-all to
//index.php, so it is refused with the reason
$stock14 = str_replace(array('  # RewriteCond %{REQUEST_URI} "[^-_.a-zA-Z0-9/~]"', "  # RewriteCond %{REQUEST_FILENAME} !-f\n  # RewriteCond %{REQUEST_FILENAME} !-d\n  # RewriteRule ^(.*)$ index.php?it=/http404/"), array('  RewriteCond %{REQUEST_URI} "[^-_.a-zA-Z0-9/~]"', "  RewriteCond %{REQUEST_FILENAME} !-f\n  RewriteCond %{REQUEST_FILENAME} !-d\n  RewriteRule ^(.*)$ index.php?it=/http404/"), $stock);
$rule19 = '  RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]';
$ownRule19 = array(
	'flags in another order' => str_replace($rule19, '  RewriteRule ^(.*)$ index.php?it=$1 [QSA,L]', $stock),
	'a subdirectory install\'s absolute path' => str_replace($rule19, '  RewriteRule ^(.*)$ /pw/index.php?it=$1 [L,QSA]', $stock),
	'flags in another order, section 14 switched on' => str_replace($rule19, '  RewriteRule ^(.*)$ index.php?it=$1 [QSA,L]', $stock14),
);
check('htaccess catch-alls: the stock rule 19, and rule 20 when switched on, count, also with a path before index.php; the block\'s rules, section 14, a home rule, ^(.+)$ and a commented-out rule do not', strpos($stock14, "\n  RewriteRule ^(.*)$ index.php?it=/http404/ [L,QSA]\n") !== false && $h->catchAlls($stock) === array(484) && $h->catchAlls($withBlock) === array(484 + count(explode("\n", $block))) && $h->catchAlls(str_replace('  # RewriteRule ^(.*)$ /index.php?it=$1 [L,QSA]', '  RewriteRule ^(.*)$ /index.php?it=$1 [L,QSA]', $stock)) === array(484, 490) && $h->catchAlls(str_replace($rule19, '#', $stock14)) === array() && $h->catchAlls("RewriteRule ^$ /index.php [L,QSA]\nRewriteRule ^(.+)$ index.php?it=$1 [L,QSA]\n# RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]\n$block") === array() && $h->catchAlls($ownRule19['a subdirectory install\'s absolute path']) === array(484), json_encode(array($h->catchAlls($stock), $h->catchAlls($withBlock), $h->catchAlls($stock14))));
$bad = array();
foreach($ownRule19 as $label => $fixture) {
	htPut($htFile, $fixture);
	$listing = files($tmpRoot);
	$st = $h->status();
	$r = $h->write(false, true);
	$d = $h->write(true, true);
	if($fixture === $stock || htSummary($st) !== 'candidate 445-484:S2' || $r['ok'] || $r['done'] !== 'refused' || $d['ok'] || $d['done'] !== 'refused' || $d['text'] !== null || strpos($r['message'], 'no active rule outside the StaticPages block would hand requests to index.php') === false || strpos($r['message'], 'the rule on line 484, written differently from the stock line, would be commented out') === false || strpos($r['message'], 'choose Keep') === false || $r['messages'] || $r['prefixed'] || file_get_contents($htFile) !== $fixture || files($tmpRoot) !== $listing) $bad[] = "$label: " . htSummary($st) . " {$r['done']} {$r['message']} " . implode(' ', $r['messages']);
	htClean($tmpRoot);
}
check('htaccess Replace: the site\'s own rule 19 written differently from stock (flags reordered, /pw/index.php, also with section 14 switched on) is an S2 candidate; Replace is refused with the reason, also in a dry run; nothing written', !count($bad), implode('; ', $bad));
//the same rule as a hand catch-all beside the stock rule 19: Replace goes ahead, rule 19 stays
list($fixture) = htHand($stock, '# PW-PAGENAME', array('RewriteCond %{HTTP_HOST} ^(www\.)?example\.com$ [NC]', 'RewriteRule ^(.*)$ index.php?it=$1 [QSA,L]'));
htPut($htFile, $fixture);
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
check('htaccess Replace: a hand catch-all to index.php beside the stock rule 19 is replaced, the stock rule 19 is left as the one catch-all', $r['ok'] && $r['done'] === 'written' && $r['prefixed'] === array(439, 440) && $h->status()['state'] === 'current' && count($h->catchAlls($new)) === 1 && strpos($new, "\n$rule19\n") !== false, json_encode(array($r['message'], $r['prefixed'], $h->catchAlls($new))));
htClean($tmpRoot);

//states that may not be written: refused, naming the state, the bytes and the listing unchanged
$bad = array();
$refusable = array('none' => null, 'locked: a directory in place of the file' => 'dir') + array_map(fn($t) => $t, $damaged) + $unrecognised;
foreach($refusable as $label => $text) {
	htClean($tmpRoot);
	$state = $label === 'none' ? 'none' : (strpos($label, 'locked') === 0 ? 'locked' : (isset($damaged[$label]) ? 'damaged' : 'unrecognised'));
	if($text === 'dir') @mkdir($htFile);
	else if($text !== null) htPut($htFile, $text);
	$listing = files($tmpRoot);
	foreach(array('write' => fn() => $h->write(), 'replace' => fn() => $h->write(false, true), 'remove' => fn() => $h->remove()) as $call => $fn) {
		$r = $fn();
		clearstatcache();
		$same = $text === 'dir' ? is_dir($htFile) : ($text === null ? !file_exists($htFile) : file_get_contents($htFile) === $text);
		if($r['ok'] || $r['state'] !== $state || strpos($r['message'], "state of the .htaccess is $state") === false || !$same || files($tmpRoot) !== $listing) $bad[] = "$label $call: {$r['state']} {$r['message']}";
	}
	if($text === 'dir') @rmdir($htFile);
}
check('htaccess write and remove refuse none, locked, damaged and unrecognised, naming the state; the file (or its absence) and the dir listing unchanged', count($refusable) === 8 && !count($bad), implode('; ', $bad));
htClean($tmpRoot);

//CRLF: what is written takes the file's own line ends
htPut($htFile, crlf($stock));
$r = $h->write();
$new = (string) file_get_contents($htFile);
check('htaccess write, CRLF fixture: no lone LF, the block in CRLF before # PW-PAGENAME', $r['ok'] && !htLoneLf($new) && $new === crlf($withBlock) && $h->status()['state'] === 'current');
htClean($tmpRoot);
list($fixture, $handLines) = htHand($stock, 'DirectoryIndex index.php index.html index.htm', array('DirectoryIndex index.html index.php'), true);
list($fixture, $more) = htHand($fixture, '# PW-PAGENAME', $readme);
$handLines = array_merge($handLines, $more);
htPut($htFile, crlf($fixture));
$r = $h->write(false, true);
$new = (string) file_get_contents($htFile);
list($bad) = htCompare(crlf($fixture), $new, $handLines);
check('htaccess Replace, CRLF fixture: no lone LF, every other line byte-identical, current', $r['ok'] && !htLoneLf($new) && !count($bad) && $h->status()['state'] === 'current' && strpos($new, "\r\n{$prefix}DirectoryIndex index.html index.php\r\nDirectoryIndex index.php index.html index.htm\r\n") !== false, json_encode(array($r['message'], $bad)));
$r = $h->remove();
check('htaccess remove, CRLF fixture: the CRLF fixture byte for byte', $r['ok'] && file_get_contents($htFile) === crlf($fixture));
htClean($tmpRoot);

//dry run: what would be written, nothing touched
htPut($htFile, $stock);
$listing = files($tmpRoot);
$r = $h->write(true);
$d = $sp->writeHtaccess(true);
check('htaccess dry run, absent: returns the state, the block and the new text; the file and the dir listing unchanged; writeHtaccess() says the same', $r['ok'] && $r['done'] === 'dry run' && $r['state'] === 'absent' && $r['block'] === $block && $r['text'] === $withBlock && $r['backup'] === '' && file_get_contents($htFile) === $stock && files($tmpRoot) === $listing && $d['done'] === 'dry run' && $d['text'] === $r['text']);
list($fixture) = htHand($stock, '# PW-PAGENAME', $readme);
htPut($htFile, $fixture);
$listing = files($tmpRoot);
$r = $h->write(true, true);
$text = $r['text'];
check('htaccess dry run, Replace: returns the state, the candidates, the block and the new text; the file and the dir listing unchanged', $r['ok'] && $r['done'] === 'dry run' && $r['state'] === 'candidate' && count($r['candidates']) === 4 && $r['block'] === $block && is_string($text) && strpos($text, $prefix) !== false && file_get_contents($htFile) === $fixture && files($tmpRoot) === $listing);
$r = $h->write(false, true);
check('htaccess Replace writes what its dry run showed', $r['ok'] && file_get_contents($htFile) === $text);
$listing = files($tmpRoot);
$r = $h->remove(true);
$d = $sp->removeHtaccess(true);
check('htaccess dry run, remove: returns the text it would give back; the file and the dir listing unchanged; removeHtaccess() says the same', $r['ok'] && $r['done'] === 'dry run' && $r['text'] === $fixture && file_get_contents($htFile) === $text && files($tmpRoot) === $listing && $d['text'] === $fixture);
htClean($tmpRoot);

//six writes leave five backups, the oldest gone: within one second, a counter tells them apart
htPut($htFile, $stock);
$names = array();
foreach(array('index.html', 'cache.htm', 'index.html', 'cache.htm', 'index.html', 'cache.htm') as $name) {
	$sp->set('staticFile', $name);
	$r = $h->write();
	$names[] = $r['backup'];
}
$sp->set('staticFile', 'index.html');
$left = array_keys($h->backups($htFile));
check('htaccess: six writes leave five backups, the oldest gone, the newest first', count(array_unique($names)) === 6 && count(htBackups($tmpRoot)) === 5 && !file_exists("$tmpRoot/{$names[0]}") && $left === array_reverse(array_slice($names, 1)), json_encode(array($names, $left)));
htClean($tmpRoot);

//the check request: a 5xx puts the backup back, 200 and no answer keep the new file
$asked = $results = array();
foreach(array('500' => 500, '200' => 200, 'throwing' => null) as $label => $code) {
	htPut($htFile, $stock);
	$h->fetch = function($url) use ($code, &$asked) {
		$asked[] = $url;
		if($code === null) throw new WireException('no answer');
		return $code;
	};
	$r = $h->write();
	$results[$label] = array($r, (string) file_get_contents($htFile), htTmp($tmpRoot));
	htClean($tmpRoot);
}
$h->fetch = null;
check('htaccess write: no temporary file is left after a check request answered 500, 200, or none', $results['500'][2] === array() && $results['200'][2] === array() && $results['throwing'][2] === array(), json_encode(array_column($results, 2)));
list($r, $bytes) = $results['500'];
check('htaccess write: a check request answered 500 puts the backup back, and the result says so', !$r['ok'] && $r['done'] === 'restored' && $r['restored'] && $r['fetch'] === 500 && $bytes === $stock && strpos($r['message'], 'answered 500') !== false && strpos($r['message'], 'was put back') !== false && strpos(logText($tmp), 'put back from') !== false, $r['message']);
check('htaccess write: a check request answered 200 keeps the new file; one that throws keeps it too and is logged', $results['200'][0]['ok'] && $results['200'][0]['fetch'] === 200 && $results['200'][1] === $withBlock && $results['throwing'][0]['ok'] && $results['throwing'][0]['fetch'] === 0 && $results['throwing'][1] === $withBlock && strpos(logText($tmp), 'got no answer') !== false && count($asked) === 3 && $asked[0] === (string) $config->urls->httpRoot, json_encode(array($results['throwing'][0]['message'], $asked)));

//a re-read that is not what was meant puts the backup back
htPut($htFile, $stock);
$h->reread = function(array $status) { $status['state'] = 'outdated'; return $status; };
$r = $h->write();
$h->reread = null;
check('htaccess write: a re-read that is not current (forced) puts the backup back, no temporary file left', !$r['ok'] && $r['restored'] && file_get_contents($htFile) === $stock && strpos($r['message'], 'outdated, not current') !== false && htTmp($tmpRoot) === array(), $r['message']);
htClean($tmpRoot);

//remove(): judged by what the file holds, not its state: no block, no prefixed line
htPut($htFile, $withBlock);
$h->reread = function(array $status) { $status['state'] = 'unrecognised'; return $status; };
$r = $h->remove();
$h->reread = null;
check('htaccess remove: the re-read is judged by its bytes (no marker line, no prefixed line), whatever state it is given', $r['ok'] && $r['done'] === 'written' && file_get_contents($htFile) === $stock, $r['message']);
htClean($tmpRoot);
htPut($htFile, $withBlock);
$h->reread = function(array $status, &$bytes) { $bytes = "# StaticPages-replaced: x\n" . $bytes; return $status; };
$r = $h->remove();
$h->reread = null;
check('htaccess remove: a file read back that still holds a prefixed line puts the backup back, no temporary file left', !$r['ok'] && $r['restored'] && file_get_contents($htFile) === $withBlock && htTmp($tmpRoot) === array(), $r['message']);
htClean($tmpRoot);

check('htaccess writer: no temporary file is left, the temporary root is empty again', files($tmpRoot) === '' && htTmp($tmpRoot) === array(), files($tmpRoot));

////  fixture

$home = $pages->get('/');
$tpl = $wire->templates->get('default') ?: $home->template;
$fixtures = array();
//template id => array(template, contentType at start): a contentType changed in memory, put back
$contentTypesAtStart = array();

//removes the fixture pages and puts the module config back. Called at the end, and from a
//shutdown function when a fatal error or an exit() ends the script before that: a PHP fatal
//still runs the shutdown functions, this one ahead of the module's drain (which the module
//registers from inside the shutdown sequence, so behind every function registered before)
$cleanedUp = false;
function cleanup() {
	global $wire, $sp, $fixtures, $configAtStart, $cleanedUp, $contentTypesAtStart;
	if($cleanedUp) return;
	$cleanedUp = true;
	foreach($contentTypesAtStart as $t) $t[0]->contentType = $t[1];
	$sp->publisher()->responseHeaders = null;
	//the script may have died while the install's own root was in use
	realPaths(false);
	$pages = $wire->pages;
	foreach(array_reverse($fixtures) as $f) if($f->id && $pages->get($f->id)->id) $pages->delete($pages->get($f->id), true);
	$sp->drain();
	$wire->modules->configs->saveConfig('StaticPages', $configAtStart);
	//the restored config may say the migration is pending; this process ran it on the temp root
	$sp->set('configVersion', StaticPages::configVersion);
	//the module's own .htaccess check after this CLI run would read the temporary root after it
	//is gone, recreate its cache dir and log there: this run stands for a visitor's request now
	$cli = new \ReflectionProperty(StaticPages::class, 'cli');
	$cli->setAccessible(true);
	$cli->setValue($sp, false);
	$sp->htaccess()->using(null);
}
register_shutdown_function(function() {
	global $cleanedUp, $fails, $tmp;
	if($cleanedUp) return;
	$fails++;
	echo "\nFAIL the script ended before its last check: removing the fixture pages\n";
	try {
		cleanup();
		echo "fixture pages removed, module config restored; temp dir kept: $tmp\n";
	} catch(\Throwable $t) {
		echo 'FAIL cleanup: ' . $t->getMessage() . "\n";
	}
});

$mk = function($parent, $name, $title) use ($pages, $tpl, &$fixtures) {
	$p = new Page();
	$p->template = $tpl;
	$p->parent = $parent;
	$p->name = $name;
	$p->title = $title;
	$pages->save($p);
	$fixtures[] = $p;
	return $p;
};
$P = $mk($home, 'sp-smoke-' . getmypid(), 'P');
$A = $mk($P, 'a', 'A');
$B = $mk($P, 'b', 'B');
$D = $mk($P, 'd', 'D');
$sp->drain();
$Pdir = '/' . $P->name;

////  index check kept in the module config

$database->queryLog(true);
$fresh = $wire->wire(new StaticPagesIndex($sp));
$fresh->ensureTables();
$log = implode("\n", array_map(fn($q) => is_array($q) ? implode(' ', $q) : (string) $q, $database->queryLog()));
$database->queryLog(false);
check('index tables ready without CREATE TABLE or information_schema', stripos($log, 'CREATE TABLE') === false && stripos($log, 'information_schema') === false, substr($log, 0, 200));
check('indexSchema stored in the module config', (int) $modules->getConfig('StaticPages', 'indexSchema') === StaticPages::schemaVersion, json_encode($modules->getConfig('StaticPages', 'indexSchema')));

$e = new \PDOException('table gone');
$r = new \ReflectionProperty(\Exception::class, 'code');
$r->setAccessible(true);
$r->setValue($e, '42S02');
$fresh->tablesFailed($e);
check('a missing table drops indexSchema', $modules->getConfig('StaticPages', 'indexSchema') === null);
$fresh2 = $wire->wire(new StaticPagesIndex($sp));
check('the next check creates and stores it again', $fresh2->ensureTables() && (int) $modules->getConfig('StaticPages', 'indexSchema') === StaticPages::schemaVersion);

////  publish

publish($sp, $A, array($D->id));
publish($sp, $B);
$sp->index()->collect(true);
$wire->pages->uncacheAll();
$Pp = $pages->get($P->id);
$wire->wire('page', $Pp);
$pages->find("parent=$Pdir/, include=hidden");
$sp->queue()->startGen();
$sp->cachePage(new HookEvent(array('object' => $Pp, 'method' => 'render', 'return' => '<html><body>listing</body></html>')));
$sp->index()->collect(false);

check('publish wrote A, B and the listing', is_file("$tmpRoot$Pdir/a/index.html") && is_file("$tmpRoot$Pdir/b/index.html") && is_file("$tmpRoot$Pdir/index.html"), files($tmpRoot));
check('published markup has the CSRF token emptied', strpos((string) @file_get_contents("$tmpRoot$Pdir/a/index.html"), 'content=""') !== false);
check('no temporary file left behind', strpos(files($tmpRoot), '.tmp') === false);
$tagsA = tagsOf($sp, "$Pdir/a/index.html");
check('A depends on A, D, P and home', in_array("p:{$A->id}", $tagsA) && in_array("p:{$D->id}", $tagsA) && in_array("p:{$P->id}", $tagsA) && in_array('p:1', $tagsA), implode(',', $tagsA));
check('B does not depend on D', !in_array("p:{$D->id}", tagsOf($sp, "$Pdir/b/index.html")));
$tagsP = tagsOf($sp, "$Pdir/index.html");
check('the listing depends on its find (par:P), resolved from the path', in_array("par:{$P->id}", $tagsP), implode(',', $tagsP));

////  a save removes only its dependants

$d = $pages->get($D->id);
$d->title = 'D2';
$pages->save($d);
$sp->drain();
check('saving D removed A (loaded D) and the listing (finds children of P)', !is_file("$tmpRoot$Pdir/a/index.html") && !is_file("$tmpRoot$Pdir/index.html"), files($tmpRoot));
check('saving D kept B', is_file("$tmpRoot$Pdir/b/index.html"));
check('the emptied dir of A is gone', !is_dir("$tmpRoot$Pdir/a"));
$e = $pages->get($B->id);
$e->title = 'B';
$pages->save($e);
$sp->drain();
check('a save changing nothing removes nothing', is_file("$tmpRoot$Pdir/b/index.html"));

////  cachePage refusals

$sp->set('nonStaticDirs', "site\nvendor\n{$P->name}");
publish($sp, $A);
check('nothing is written below a listed dir', !is_file("$tmpRoot$Pdir/a/index.html"));
check('...and the skip is logged', strpos(logText($tmp), 'listed in Non-static directories') !== false);
$sp->set('nonStaticDirs', "site\nvendor");
$sp->set('noCacheCookies', 'currency');
$_COOKIE['currency'] = 'eur';
$wire->input->cookie->set('currency', 'eur');
publish($sp, $A);
check('a listed cookie keeps the page out', !is_file("$tmpRoot$Pdir/a/index.html"));
$wire->input->cookie->remove('currency');
unset($_COOKIE['currency']);
$sp->set('noCacheCookies', '');
$sp->set('staticFile', '*');
publish($sp, $A);
check('an invalid static file name writes nothing', !is_file("$tmpRoot$Pdir/a/index.html") && strpos(files($tmpRoot), '*') === false);
$sp->set('staticFile', 'index.html');

////  allowCache() hook and noCache() (0.9.0)

$hookId = $wire->addHookAfter('StaticPages::allowCache', function(HookEvent $e) { $e->return = false; });
publish($sp, $A);
$wire->removeHook($hookId);
check('an allowCache hook returning false: no file, no dir left behind', !is_file("$tmpRoot$Pdir/a/index.html") && !is_dir("$tmpRoot$Pdir/a"), files($tmpRoot));
check('...and the skip is logged', strpos(logText($tmp), "skip $Pdir/a/: refused by an allowCache hook") !== false);

$allowCalls = 0;
$hookId = $wire->addHookAfter('StaticPages::allowCache', function(HookEvent $e) use (&$allowCalls) { $allowCalls++; $e->return = true; });
$wire->input->get->set('smoke', '1');
publish($sp, $A);
$wire->input->get->remove('smoke');
$wire->removeHook($hookId);
check('a render with a GET parameter is not published, the allowCache hook is never asked', !is_file("$tmpRoot$Pdir/a/index.html") && $allowCalls === 0, "hook called $allowCalls times");

$sp->noCache('cart in session');
publish($sp, $A);
check('noCache(reason): no file', !is_file("$tmpRoot$Pdir/a/index.html") && !is_dir("$tmpRoot$Pdir/a"));
check('...and the reason is logged', strpos(logText($tmp), "skip $Pdir/a/: noCache() was called: cart in session") !== false);
$sp->noCache(false);
publish($sp, $A);
check('noCache(false) clears it: the file is written', is_file("$tmpRoot$Pdir/a/index.html"));
check('with no allowCache hook attached a publish writes the file as before', !count($wire->hooks->getHooks($sp, 'allowCache')) && in_array("p:{$A->id}", tagsOf($sp, "$Pdir/a/index.html")));

////  fileContent() hook (0.9.0)

//the render of A as cachePage() gets it, with the event kept to read its return afterwards
function publishEvent(StaticPages $sp, Page $page, $html) {
	$wire = $sp->wire();
	$wire->pages->uncacheAll();
	$page = $wire->pages->get($page->id);
	$wire->wire('page', $page);
	$sp->queue()->startGen();
	$event = new HookEvent(array('object' => $page, 'method' => 'render', 'return' => $html));
	$sp->cachePage($event);
	return $event;
}
//A's file, its index rows and its dir gone, the state before its first publish
function unpublishA(StaticPages $sp, $tmpRoot, $Pdir) {
	$sp->index()->dropIndexRows(array(md5("$Pdir/a/index.html")));
	$sp->publisher()->unpublish("$tmpRoot$Pdir/a/index.html", "$tmpRoot$Pdir/a");
}
$markup = "<html><head><meta name=csrf-token content=tok{$A->id}><meta name=nonce content=\"{{NONCE}}\"></head><body>{$A->title}</body></html>";

$event = publishEvent($sp, $A, $markup);
check('with no fileContent hook attached the file is stripCsrf() of the render, byte for byte', !count($wire->hooks->getHooks($sp, 'fileContent')) && (string) @file_get_contents("$tmpRoot$Pdir/a/index.html") === $sp->stripCsrf($markup) && $sp->stripCsrf($markup) !== $markup, (string) @file_get_contents("$tmpRoot$Pdir/a/index.html"));

$seen = array();
$hookId = $wire->addHookAfter('StaticPages::fileContent', function(HookEvent $e) use (&$seen) {
	$seen = array($e->arguments(0), $e->arguments(1));
	$e->return = str_replace('{{NONCE}}', '<!--#echo var="CSP_NONCE" -->', $e->return);
});
unpublishA($sp, $tmpRoot, $Pdir);
$event = publishEvent($sp, $A, $markup);
$wire->removeHook($hookId);
$written = (string) @file_get_contents("$tmpRoot$Pdir/a/index.html");
check('a fileContent hook replacing a marker: the file holds the replacement', $written === str_replace('{{NONCE}}', '<!--#echo var="CSP_NONCE" -->', $sp->stripCsrf($markup)), $written);
check('...the hook got the page and the markup after stripCsrf()', ($seen[0] ?? null) instanceof Page && $seen[0]->id === $A->id && ($seen[1] ?? null) === $sp->stripCsrf($markup));
check('...and the render event still returns the original markup', $event->return === $markup, (string) $event->return);
check('...and the file is indexed as usual', in_array("p:{$A->id}", tagsOf($sp, "$Pdir/a/index.html")));

foreach(array("''" => '', 'null' => null) as $label => $value) {
	unpublishA($sp, $tmpRoot, $Pdir);
	$skipsBefore = substr_count(logText($tmp), "skip $Pdir/a/: a fileContent hook returned nothing");
	$hookId = $wire->addHookAfter('StaticPages::fileContent', function(HookEvent $e) use ($value) { $e->return = $value; });
	$event = publishEvent($sp, $A, $markup);
	$wire->removeHook($hookId);
	check("a fileContent hook returning $label: no file, no dir created, nothing indexed", !is_file("$tmpRoot$Pdir/a/index.html") && !is_dir("$tmpRoot$Pdir/a") && !count(tagsOf($sp, "$Pdir/a/index.html")), files($tmpRoot));
	check('...and the skip is logged', substr_count(logText($tmp), "skip $Pdir/a/: a fileContent hook returned nothing") === $skipsBefore + 1);
	check('...and the render event still returns the original markup', $event->return === $markup);
}

//A published again with no hook, as the blocks before left it
publish($sp, $A);
check('with the hooks removed A is published again', !count($wire->hooks->getHooks($sp, 'fileContent')) && is_file("$tmpRoot$Pdir/a/index.html") && in_array("p:{$A->id}", tagsOf($sp, "$Pdir/a/index.html")));

////  Never invalidate on save of these templates (0.9.0)

//what a new request starts with: nothing queued by it, the generation not bumped by it yet.
//The queue part remembers both for the whole process, this script is one long request
function freshQueue(StaticPages $sp) {
	(function() {
		$this->genBumped = false;
		$this->queuedTags = array();
		$this->queuedMode = 0;
		$this->drainRequested = false;
	})->call($sp->queue());
}
function queueMaxId(StaticPages $sp) {
	return (int) $sp->wire()->database->query('SELECT COALESCE(MAX(id), 0) FROM `' . StaticPages::tableQueue . '`')->fetchColumn();
}
function queueRowsAfter(StaticPages $sp, $id) {
	$query = $sp->wire()->database->prepare('SELECT mode, tags FROM `' . StaticPages::tableQueue . '` WHERE id > :id ORDER BY id');
	$query->bindValue(':id', (int) $id, \PDO::PARAM_INT);
	$query->execute();
	return $query->fetchAll(\PDO::FETCH_ASSOC);
}
function queuedTagsOf(array $rows) {
	$tags = array();
	foreach($rows as $row) foreach(explode("\n", (string) $row['tags']) as $tag) if($tag !== '') $tags[] = $tag;
	return $tags;
}
//saves $page with a changed title under the given lists, from a fresh queue: what it queued
function saveListed(StaticPages $sp, $id, array $ignore, array $wipeAll = array(), ?callable $save = null) {
	$wire = $sp->wire();
	$wipeAllBefore = $sp->get('wipeAllTemplates');
	$sp->set('ignoreTemplates', $ignore);
	$sp->set('wipeAllTemplates', $wipeAll);
	freshQueue($sp);
	$gen = $sp->queue()->readGen();
	$maxId = queueMaxId($sp);
	$wire->pages->uncacheAll();
	$page = $wire->pages->get($id);
	if($save) {
		$save($page);
	} else {
		$page->of(false);
		$page->title = $page->title . '+';
		$wire->pages->save($page);
	}
	$out = array('gen' => $sp->queue()->readGen() !== $gen, 'rows' => queueRowsAfter($sp, $maxId), 'drain' => $sp->queue()->drainRequested());
	$sp->remove('ignoreTemplates');
	if($wipeAllBefore === null) $sp->remove('wipeAllTemplates');
	else $sp->set('wipeAllTemplates', $wipeAllBefore);
	return $out;
}

$sp->drain();
check('the generation stamp exists before the ignore checks', is_file($sp->queue()->genPath()));

$r = saveListed($sp, $A->id, array($tpl->id));
$sp->drain();
check('a save of a page of a listed template: the generation stays, no queue row, no drain', !$r['gen'] && !count($r['rows']) && !$r['drain'], json_encode($r));
check('...and a file tagged p:<that page> is still on disk after drain()', is_file("$tmpRoot$Pdir/a/index.html") && in_array("p:{$A->id}", tagsOf($sp, "$Pdir/a/index.html")));

$r = saveListed($sp, $A->id, array());
$sp->drain();
check('the same save with the list empty bumps the generation and queues p:<that page>', $r['gen'] && count($r['rows']) && in_array("p:{$A->id}", queuedTagsOf($r['rows'])) && $r['drain'], json_encode($r));
check('...and drain() removes that file', !is_file("$tmpRoot$Pdir/a/index.html") && !count(tagsOf($sp, "$Pdir/a/index.html")), files($tmpRoot));

$r = saveListed($sp, $A->id, array($tpl->id), array($tpl->id));
check('a template in both Wipe everything and Never invalidate queues modeAll', count($r['rows']) === 1 && (int) $r['rows'][0]['mode'] === StaticPages::modeAll, json_encode($r));
$sp->drain();

$isWarning = fn($n) => $n instanceof NoticeWarning && strpos((string) $n->text, 'Never invalidate on save of these templates') !== false && strpos((string) $n->text, $tpl->name) !== false;
$warningsBefore = count(array_filter(iterator_to_array($wire->notices), $isWarning));
$data = $modules->getConfig('StaticPages');
$both = $data;
$both['ignoreTemplates'] = array($tpl->id);
$both['wipeAllTemplates'] = array((string) $tpl->id);
$modules->saveConfig('StaticPages', $both);
$warned = count(array_filter(iterator_to_array($wire->notices), $isWarning)) - $warningsBefore;
$one = $data;
$one['ignoreTemplates'] = array($tpl->id);
$one['wipeAllTemplates'] = array();
$modules->saveConfig('StaticPages', $one);
$warnedOne = count(array_filter(iterator_to_array($wire->notices), $isWarning)) - $warningsBefore - $warned;
$modules->saveConfig('StaticPages', $data);
check('saving the config with a template in both lists warns, naming it; one list alone does not', $warned === 1 && $warnedOne === 0, "warned $warned, then $warnedOne");

//a template of Non-static page templates answering XML is never cached: with the switch on,
//the save says so. contentType set in memory only, never saved
$isWarning = fn($n) => $n instanceof NoticeWarning && strpos((string) $n->text, 'answer XML or plain text') !== false && strpos((string) $n->text, $tpl->name) !== false;
$contentTypeBefore = $tpl->contentType;
$tpl->contentType = 'xml';
$warningsBefore = count(array_filter(iterator_to_array($wire->notices), $isWarning));
$on = $data;
$on['nonStaticTemplates'] = array_merge((array) ($data['nonStaticTemplates'] ?? array()), array($tpl->id));
$on['cacheNonHtml'] = 1;
$modules->saveConfig('StaticPages', $on);
$warned = count(array_filter(iterator_to_array($wire->notices), $isWarning)) - $warningsBefore;
$off = $on;
$off['cacheNonHtml'] = 0;
$modules->saveConfig('StaticPages', $off);
$warnedOff = count(array_filter(iterator_to_array($wire->notices), $isWarning)) - $warningsBefore - $warned;
$modules->saveConfig('StaticPages', $data);
$tpl->contentType = $contentTypeBefore;
check('saving the config with the switch on and an XML template in Non-static page templates warns, naming it; with the switch off it does not', $warned === 1 && $warnedOff === 0, "warned $warned, then $warnedOff");

//"Replace these root files with pages" (rootFiles): the lines used, the lines refused, the
//warnings of a save. Used from step 05 and 06 on; nothing is written here
$given = $sp->rootFilesArray(array('rootFiles' => "robots.txt /robots/\r\n\r\nsitemap.xml"));
check('rootFilesArray() accepts "robots.txt /robots/" and "sitemap.xml", whose path is /sitemap.xml/', $given === array('robots.txt' => '/robots/', 'sitemap.xml' => '/sitemap.xml/'), json_encode($given));
$refusedLines = array('index.xml /x/', 'x.php /x/', '../robots.txt /x/', 'a/b.txt /x/', 'robots.txt /', 'robots.txt x', 'robots.txt /a b/', '.robots.txt /x/', 'ROBOTS.TXT /x/', 'index.txt', 'index.html /x/');
$accepted = array();
foreach($refusedLines as $line) $accepted[$line] = $sp->rootFilesArray(array('rootFiles' => $line));
check('rootFilesArray() refuses a known static name, another extension, a path or dot file as name, a path that is no /segment/, a blank in the path, upper case', !array_filter($accepted), json_encode($accepted));
$given = $sp->rootFilesArray(array('rootFiles' => "robots.txt /robots/\nrobots.txt /other/"));
check('rootFilesArray() refuses a second line for a name already listed', $given === array('robots.txt' => '/robots/'), json_encode($given));
$given = array(
	$sp->rootFilesArray(array('staticFile' => 'robots.txt', 'rootFiles' => "robots.txt /robots/\nrobots.xml /x/\nsitemap.xml /s/")),
	$sp->rootFilesArray(array('staticFile' => 'robots.txt', 'cacheNonHtml' => 1, 'rootFiles' => 'robots.txt /robots/')),
);
check('rootFilesArray() refuses the names of knownFileNames(), also with Static file name robots.txt', $given === array(array('sitemap.xml' => '/s/'), array()), json_encode($given));
$rootFilesBefore = $sp->get('rootFiles');
$sp->remove('rootFiles');
$given = array($sp->rootFilesArray(), $sp->rootFilesArray(array()));
$sp->set('rootFiles', "robots.txt /robots/");
$given[] = $sp->rootFilesArray();
if($rootFilesBefore === null) $sp->remove('rootFiles'); else $sp->set('rootFiles', $rootFilesBefore);
check('rootFilesArray(): an absent key is an empty list; without an array it reads the loaded setting', $given === array(array(), array(), array('robots.txt' => '/robots/')), json_encode($given));
$noticeTexts = function($from) use ($wire) {
	$out = array();
	foreach(array_slice(iterator_to_array($wire->notices), $from) as $n) if($n instanceof NoticeWarning) $out[] = html_entity_decode((string) $n->text, ENT_QUOTES);
	return $out;
};
$hasLine = fn(array $texts, $needle, $line) => count(array_filter($texts, fn($t) => strpos($t, $needle) !== false && strpos($t, "\"$line\"") !== false));
$pagePath = $A->path;
$withRoot = $data;
$withRoot['rootFiles'] = "robots.txt $pagePath\nsitemap.xml /sp-smoke-no-such-page/\nx.php /x/";
$withRoot['cacheNonHtml'] = 1;
$from = count($wire->notices);
$modules->saveConfig('StaticPages', $withRoot);
$onTexts = $noticeTexts($from);
$withRoot['cacheNonHtml'] = 0;
$from = count($wire->notices);
$modules->saveConfig('StaticPages', $withRoot);
$offTexts = $noticeTexts($from);
$modules->saveConfig('StaticPages', $data);
$rootTexts = fn(array $texts) => array_values(array_filter($texts, fn($t) => strpos($t, 'Replace these root files with pages') !== false));
check('saving the config warns about a refused line and a path that is no page, naming the line; a valid line with a page and the switch on is not warned about', count($rootTexts($onTexts)) === 2 && $hasLine($onTexts, 'is ignored', 'x.php /x/') && $hasLine($onTexts, 'there is no page', 'sitemap.xml /sp-smoke-no-such-page/'), json_encode($rootTexts($onTexts)));
check('saving the config with the switch off also warns about each used line, naming it (core shows a notice text once per request)', count($rootTexts($offTexts)) === 2 && $hasLine($offTexts, 'is off', "robots.txt $pagePath") && $hasLine($offTexts, 'is off', 'sitemap.xml /sp-smoke-no-such-page/'), json_encode($rootTexts($offTexts)));

//a repeater item counts as its owner's page: a nested item if the install has a repeater whose
//items carry a repeater of their own, else a plain one
$textOf = function($template) {
	if($template instanceof Template) foreach($template->fieldgroup as $f) if($f->type instanceof FieldtypeText) return $f;
	return null;
};
$repeatersOf = function($template) {
	$out = array();
	if($template instanceof Template) foreach($template->fieldgroup as $f) if($f->type instanceof FieldtypeRepeater && $f->type->className() === 'FieldtypeRepeater') $out[] = $f;
	return $out;
};
$rep = null;
foreach($wire->templates as $t) {
	if(($t->flags & Template::flagSystem) || strpos($t->name, 'repeater_') === 0 || $t->noParents || count((array) $t->parentTemplates)) continue;
	foreach($repeatersOf($t) as $f) {
		$itemTpl = $wire->templates->get((int) $f->get('template_id'));
		if(!$textOf($itemTpl)) continue;
		$inner = null;
		foreach($repeatersOf($itemTpl) as $g) if($textOf($wire->templates->get((int) $g->get('template_id')))) { $inner = $g; break; }
		if($inner) { $rep = array($t, $f, $inner); break 2; }
		if(!$rep) $rep = array($t, $f, null);
	}
}
if($rep) {
	list($repTpl, $outerField, $innerField) = $rep;
	$R = new Page();
	$R->template = $repTpl;
	$R->parent = $P;
	$R->name = 'r';
	$R->title = 'R';
	$pages->save($R);
	$fixtures[] = $R;
	$R->of(false);
	$item = $R->get($outerField->name)->getNew();
	$item->set($textOf($item->template)->name, 'outer');
	$item->save();
	$R->save($outerField->name);
	if($innerField) {
		$item->of(false);
		$leaf = $item->get($innerField->name)->getNew();
		$leaf->set($textOf($leaf->template)->name, 'inner');
		$leaf->save();
		$item->save($innerField->name);
	} else {
		$leaf = $item;
	}
	$sp->drain();
	$leafId = $leaf->id;
	$label = ($innerField ? "a nested repeater item ({$outerField->name}.{$innerField->name})" : "a repeater item ({$outerField->name})") . " of a {$repTpl->name} page";
	$saveLeaf = function(Page $page) use ($pages, $textOf) {
		$page->of(false);
		$name = $textOf($page->template)->name;
		$page->set($name, $page->get($name) . '+');
		$pages->save($page);
	};
	$r = saveListed($sp, $leafId, array($repTpl->id), array(), $saveLeaf);
	check("$label, the owner's template listed: nothing queued, no generation bump", $leaf instanceof RepeaterPage && !$r['gen'] && !count($r['rows']) && !$r['drain'], json_encode($r));
	$r = saveListed($sp, $leafId, array(), array(), $saveLeaf);
	$tags = queuedTagsOf($r['rows']);
	check('...the same save with the list empty queues the item and its owner', in_array("p:$leafId", $tags) && in_array("p:{$R->id}", $tags) && in_array("t:{$repTpl->id}", $tags), implode(',', $tags));
	$r = saveListed($sp, $leafId, array($tpl->id), array(), $saveLeaf);
	check('...and with only another template listed as well', in_array("p:{$R->id}", queuedTagsOf($r['rows'])), json_encode($r));
	$sp->drain();
} else {
	echo "skip the repeater checks: no template here carries a repeater field with a text field in its items and takes any parent\n";
}

//trashing: the descendants change status in SQL alone, hookTrashed() queues modeAll for them
$T = $mk($P, 't', 'T');
$mk($T, 't1', 'T1');
$U = $mk($P, 'u', 'U');
$mk($U, 'u1', 'U1');
$sp->drain();
$trash = fn($page) => $pages->trash($page);
$r = saveListed($sp, $T->id, array($tpl->id), array(), $trash);
check('trashing a page with children of a listed template queues nothing', $pages->get($T->id)->isTrash() && !count($r['rows']) && !$r['gen'] && !$r['drain'], json_encode($r));
$r = saveListed($sp, $U->id, array(), array(), $trash);
check('...trashing one with the list empty queues modeAll', $pages->get($U->id)->isTrash() && in_array(StaticPages::modeAll, array_map(fn($row) => (int) $row['mode'], $r['rows']), true), json_encode($r));
$sp->drain();

//a template change is judged by both sides: a page given a listed template was a page of an
//unlisted one until that save, and cached pages may show it as one
$other = null;
foreach($wire->templates as $t) {
	if($t->id == $tpl->id || ($t->flags & Template::flagSystem) || strpos($t->name, 'repeater_') === 0 || $t->noParents || count((array) $t->parentTemplates) || $t->noChangeTemplate) continue;
	$other = $t;
	break;
}
if($other) {
	$C = $mk($P, 'c', 'C');
	$sp->drain();
	publish($sp, $C);
	$Cfile = "$tmpRoot$Pdir/c/index.html";
	$kept = null;
	//the page object saved last, for a second save of the same object
	$setTemplate = function(Template $template) use ($pages, &$kept) {
		return function(Page $page) use ($pages, $template, &$kept) {
			$page->of(false);
			$page->template = $template;
			$pages->save($page);
			$kept = $page;
		};
	};
	$retitle = function(Page $page) use ($pages) {
		$page->of(false);
		$page->title = $page->title . '+';
		$pages->save($page);
	};
	$X = $tpl->id;
	$Y = $other->id;
	$templateOf = fn() => (int) $pages->getFresh($C->id)->template->id;
	check("C ({$tpl->name}) is published before the template changes", is_file($Cfile) && in_array("p:{$C->id}", tagsOf($sp, "$Pdir/c/index.html")));

	//unlisted to listed
	$r = saveListed($sp, $C->id, array($Y), array(), $setTemplate($other));
	$tags = queuedTagsOf($r['rows']);
	check("a page given a listed template ({$tpl->name} to {$other->name}) queues p:<it> and t:<old template>, bumps the generation", $templateOf() === $Y && $r['gen'] && $r['drain'] && in_array("p:{$C->id}", $tags) && in_array("t:$X", $tags), json_encode($r));
	$sp->drain();
	check('...and drain() removes its own copy, rendered with the old template', !is_file($Cfile) && !count(tagsOf($sp, "$Pdir/c/index.html")), files($tmpRoot));
	$r = saveListed($sp, $C->id, array($Y), array(), function() use ($pages, &$kept) {
		$kept->title = $kept->title . '+';
		$pages->save($kept);
	});
	check('...a further save of the same page object in that request queues p:<it> again (core keeps templatePrevious)', in_array("p:{$C->id}", queuedTagsOf($r['rows'])), json_encode($r));
	$sp->drain();
	$r = saveListed($sp, $C->id, array($Y), array(), $retitle);
	check('...a save of it loaded afresh queues nothing', !count($r['rows']) && !$r['gen'] && !$r['drain'], json_encode($r));

	//listed to listed
	$r = saveListed($sp, $C->id, array($X, $Y), array(), $setTemplate($tpl));
	check('a template change between two listed templates queues nothing', $templateOf() === $X && !count($r['rows']) && !$r['gen'] && !$r['drain'], json_encode($r));

	//listed to unlisted, as before
	$r = saveListed($sp, $C->id, array($X), array(), $setTemplate($other));
	$tags = queuedTagsOf($r['rows']);
	check('a page given an unlisted template from a listed one queues p:<it>, t:<old> and t:<new>', $templateOf() === $Y && in_array("p:{$C->id}", $tags) && in_array("t:$X", $tags) && in_array("t:$Y", $tags), json_encode($r));
	$sp->drain();

	//the old template in both lists: Wipe everything wins over Never invalidate on that side too
	$r = saveListed($sp, $C->id, array($X, $Y), array($Y), $setTemplate($tpl));
	$tags = queuedTagsOf($r['rows']);
	check('a page leaving a template listed in both lists for a listed one queues p:<it> and t:<old>', $templateOf() === $X && in_array("p:{$C->id}", $tags) && in_array("t:$Y", $tags), json_encode($r));
	$sp->drain();
	$kept = null;
} else {
	echo "skip the template change checks: no second template here takes any parent and allows a template change\n";
}

//the files the blocks below expect, as they were before this block
publish($sp, $A);
publish($sp, $B);
check('A and B are published again after the ignore checks', is_file("$tmpRoot$Pdir/a/index.html") && is_file("$tmpRoot$Pdir/b/index.html"));

////  a save after drain() in the same request (0.9.1)

//the queue part remembers what this request queued, so a bulk save loop inserts each tag once
//and bumps the generation once. drain() takes those rows: a deploy script or CLI saving pages
//after it has to queue, and bump, again. No freshQueue() from here on, drain() alone does it
function saveTitle(StaticPages $sp, $id) {
	$pages = $sp->wire()->pages;
	$pages->uncacheAll();
	$page = $pages->get($id);
	$page->of(false);
	$page->title = $page->title . '+';
	$pages->save($page);
}
$Afile = "$tmpRoot$Pdir/a/index.html";

$sp->queue()->enqueue(StaticPages::modeAll);
$sp->drain();
publish($sp, $A);
$sp->queue()->startGen();
$maxId = queueMaxId($sp);
saveTitle($sp, $A->id);
$r = queueRowsAfter($sp, $maxId);
check('a save after drain() in a request that queued modeAll queues p:<that page>', is_file($Afile) && in_array("p:{$A->id}", queuedTagsOf($r)) && $sp->queue()->drainRequested(), json_encode($r));
check('...and bumps the generation: a render that read the database before it does not publish', $sp->queue()->genChanged());
$sp->drain();
check('...and drain() removes that file', !is_file($Afile) && !count(tagsOf($sp, "$Pdir/a/index.html")), files($tmpRoot));

publish($sp, $A);
$sp->queue()->startGen();
$maxId = queueMaxId($sp);
saveTitle($sp, $A->id);
$r = queueRowsAfter($sp, $maxId);
check('a second save of the same page after drain() queues p:<it> again', is_file($Afile) && in_array("p:{$A->id}", queuedTagsOf($r)), json_encode($r));
check('...and bumps the generation again', $sp->queue()->genChanged());
$sp->drain();
check('...and drain() removes the file published in between', !is_file($Afile) && !count(tagsOf($sp, "$Pdir/a/index.html")), files($tmpRoot));

$maxId = queueMaxId($sp);
saveTitle($sp, $A->id);
saveTitle($sp, $A->id);
$r = queueRowsAfter($sp, $maxId);
check('two saves of one page with no drain() between still queue p:<it> once', count(array_keys(queuedTagsOf($r), "p:{$A->id}")) === 1, json_encode($r));
$sp->drain();

//the files the blocks below expect
publish($sp, $A);
check('A is published again after the drain() checks', is_file($Afile));

////  forced wipe on a temporary root

function layout($tmpRoot, $Pdir) {
	@mkdir("$tmpRoot$Pdir/a/deep", 0777, true);
	file_put_contents("$tmpRoot$Pdir/a/deep/index.html", 'copy');
	file_put_contents("$tmpRoot$Pdir/a/.index.html.999.tmp", 'leftover');
	file_put_contents("$tmpRoot$Pdir/index.html", 'copy');
	@mkdir("$tmpRoot/vendor/pkg", 0777, true);
	file_put_contents("$tmpRoot/vendor/pkg/index.html", 'listed');
	@mkdir("$tmpRoot/site/x", 0777, true);
	file_put_contents("$tmpRoot/site/x/index.html", 'listed');
	@mkdir("$tmpRoot/landing/empty", 0777, true);
	file_put_contents("$tmpRoot/landing/index.html", 'hand made, no list names it');
	@mkdir("$tmpRoot/app/assets/files/1", 0777, true);
	file_put_contents("$tmpRoot/index.html", 'home copy');
}
$survivors = array('/landing/index.html', '/landing/empty/', '/vendor/pkg/index.html', '/site/x/index.html', '/app/assets/files/1/');

foreach(array(0, 1) as $shell) {
	$sp->set('useShell', $shell);
	$label = $shell ? (PHP_OS_FAMILY === 'Windows' ? 'PowerShell' : 'find') : 'PHP walk';
	layout($tmpRoot, $Pdir);
	$before = files($tmpRoot);

	if(!$shell) {
		$dry = $sp->wipeCaches(true, true);
		$paths = $dry['paths'] ?? array();
		check('dry run changes nothing', files($tmpRoot) === $before);
		check('dry run lists the page copies, the leftover and the home copy', in_array("$Pdir/a/deep/index.html", $paths) && in_array("$Pdir/a/.index.html.999.tmp", $paths) && in_array('/index.html', $paths) && in_array("$Pdir/", $paths), implode(' ', $paths));
		check('dry run lists nothing outside the page copies', !count(array_filter($paths, fn($x) => strpos($x, $Pdir) !== 0 && $x !== '/index.html')), implode(' ', $paths));
		check('dry run names the unlisted dirs it leaves alone', in_array('landing', $dry['leftAlone'] ?? array()) && in_array('app', $dry['leftAlone'] ?? array()), implode(',', $dry['leftAlone'] ?? array()));
		check('dry run writes the list to the log', strpos(logText($tmp), "dry run: would delete $Pdir/a/deep/index.html") !== false);
	}

	$sp->wipeCaches(true);
	$after = files($tmpRoot);
	$lost = array_filter($survivors, fn($x) => strpos(" $after ", " $x ") === false);
	check("forced wipe ($label) keeps listed and unlisted dirs whole", !count($lost), 'lost: ' . implode(' ', $lost));
	check("forced wipe ($label) removes the page copies and their dirs", !is_dir("$tmpRoot$Pdir") && !is_file("$tmpRoot/index.html"), $after);
}
$sp->set('useShell', 0);

if(PHP_OS_FAMILY === 'Windows' && trim((string) @shell_exec('bash -c "command -v find" 2>NUL')) !== '') {
	//the Linux find line, run through Git Bash's GNU find on the same layout
	layout($tmpRoot, $Pdir);
	$cmd = $sp->wiper()->unixScanCommand($sp->wiper()->scanTargets());
	//through a file: escapeshellarg() on Windows would blank the double quotes in the line
	file_put_contents("$tmp/find.sh", $cmd . "\n");
	$out = (string) shell_exec('bash ' . escapeshellarg("$tmp/find.sh"));
	$after = files($tmpRoot);
	$lost = array_filter($survivors, fn($x) => strpos(" $after ", " $x ") === false);
	check('find line (GNU find via bash) keeps listed and unlisted dirs whole', !count($lost) && strpos($out, 'StaticPages-scan-done') !== false, 'lost: ' . implode(' ', $lost));
	check('find line removes the page copies', !is_dir("$tmpRoot$Pdir") && !is_file("$tmpRoot/index.html"), $after);
}

////  upgrade from older versions

$m = $sp->migration();
$isPage = fn($name) => $name === 'about';
$versions = array(
	'012 (atisprod)' => array('nonStaticTemplates' => array(2), 'nonStaticDirs' => "site\nwire\nabout", 'newDirPermissions' => '0755', 'staticFile' => 'index.html', 'forceWipeOnModuleSave' => 1, 'isEnabled' => 1),
	'020 (davinci)' => array('nonStaticTemplates' => array(2, 60), 'nonStaticDirs' => "site\ntest\napi\nwire\nabout", 'newDirPermissions' => '0755', 'staticFile' => 'index.html', 'forceWipeOnModuleSave' => 1, 'isEnabled' => 1, 'nonStaticLanguages' => array(), 'nonStaticDomainSamples' => '.nl', 'rmDirPasses' => '10', 'useShell' => 1),
	'0.3.1 (odnaco)' => array('nonStaticDirs' => 'site vendor wire', 'nonStaticTemplates' => array(2, 49), 'newDirPermissions' => '0755', 'staticFile' => 'index.html', 'rmDirPasses' => '10', 'useShell' => 1, 'forceWipeOnModuleSave' => 1, 'isEnabled' => 1, 'priority' => '1000'),
	'0.6.0 (tandem-chegem)' => array('nonStaticDirs' => "api\nsite\ntools\nvendor\nwire", 'priority' => '1000', '-dups' => array('/site/modules/StaticPages~/StaticPages.module', '/site/modules/StaticPages/StaticPages.module'), '-dups-use' => '/site/modules/StaticPages/StaticPages.module', 'wipeOnConfigSaveModules' => 'SettingsFactory', 'wipeOnSaveField' => 1, 'trackedPaths' => StaticPages::trackedPathsDefault, 'rmDirPasses' => '10', 'forceWipeOnModuleSave' => 1, 'isEnabled' => 1),
	'0.7.1' => array('nonStaticDirs' => "api\nsite", 'priority' => '900', 'wipeAllTemplates' => array(), 'wipeAllFields' => '', 'logSkips' => '', 'isEnabled' => 1),
);
foreach($versions as $label => $data) {
	$notes = array();
	$new = $m->configChanges($data, $isPage, $notes);
	$ok = !isset($new['rmDirPasses'])
		&& empty($new['forceWipeOnModuleSave'])
		&& (string) $new['priority'] === (string) ($data['priority'] ?? StaticPages::priorityDefault)
		&& (int) $new['configVersion'] === StaticPages::configVersion
		&& strpos("\n{$new['nonStaticDirs']}\n", "\nabout\n") === false;
	foreach($data as $key => $value) if(!in_array($key, array('rmDirPasses', 'forceWipeOnModuleSave', 'nonStaticDirs', 'priority'), true) && $new[$key] !== $value) $ok = false;
	check("config from $label migrates, other keys kept", $ok, json_encode($new));
}
$new = $m->configChanges($versions['0.3.1 (odnaco)'], $isPage);
check('a one line "site vendor wire" stays as the operator wrote it', $new['nonStaticDirs'] === 'site vendor wire');

//files an older version left on disk, unknown to the index
rrmdir("$tmpRoot$Pdir");
@mkdir("$tmpRoot$Pdir/old", 0777, true);
file_put_contents("$tmpRoot$Pdir/old/index.html", 'cached by 020');
file_put_contents("$tmpRoot/index.html", 'cached by 020');
@mkdir("$tmpRoot/landing", 0777, true);
file_put_contents("$tmpRoot/landing/index.html", 'hand made');
$adopted = $m->adoptStaticFiles();
check('adoption records the page copies and the home copy, not the landing page', $adopted === 2 && tagsOf($sp, "$Pdir/old/index.html") === array('*') && !count(tagsOf($sp, '/landing/index.html')), "adopted $adopted");
check('adoption deletes nothing', is_file("$tmpRoot$Pdir/old/index.html") && is_file("$tmpRoot/index.html"));
$x = $pages->get($B->id);
$x->title = 'B2';
$pages->save($x);
$sp->drain();
check('the next save removes the adopted files', !is_file("$tmpRoot$Pdir/old/index.html") && !is_file("$tmpRoot/index.html") && is_file("$tmpRoot/landing/index.html"));

////  deletes and scans know the name sets (0.11.0)

//knownFileNames() for a delete by record, the HTML name for a manifest line, scanFileNames()
//for a scan inside the targets, the HTML name alone at the root level
function putFiles($tmpRoot, array $relatives) {
	foreach($relatives as $relative) {
		@mkdir(dirname("$tmpRoot$relative"), 0777, true);
		file_put_contents("$tmpRoot$relative", 'x');
	}
}
function recordAll(StaticPages $sp, array $relatives) {
	foreach($relatives as $relative) $sp->index()->recordStaticFile($relative, 0, array('*'));
}
$sp->wipeCaches();
rrmdir("$tmpRoot$Pdir");
$sp->remove('cacheNonHtml');

//by record, switch off and on
foreach(array(0, 1) as $switch) {
	$sp->set('cacheNonHtml', $switch);
	$typed = array("$Pdir/a/index.xml", "$Pdir/b/index.txt");
	putFiles($tmpRoot, $typed);
	recordAll($sp, $typed);
	$sp->wiper()->wipeIndexed(null);
	check("by record (switch $switch): wipeIndexed() removes a recorded index.xml and index.txt and prunes their dirs", !is_dir("$tmpRoot$Pdir/a") && !is_dir("$tmpRoot$Pdir/b") && !count($sp->index()->indexedFiles(null)), files($tmpRoot));
}
$sp->set('cacheNonHtml', 1);
$tampered = array('/robots.txt', '/sitemap.xml', "$Pdir/a/index.php", "$Pdir/a/sitemap.xml");
putFiles($tmpRoot, $tampered);
recordAll($sp, $tampered);
$sp->wiper()->wipeIndexed(null);
$kept = array_filter($tampered, fn($x) => is_file("$tmpRoot$x"));
check('by record, tampered rows naming robots.txt, sitemap.xml, a/index.php and a/sitemap.xml: none is deleted', count($kept) === 4, files($tmpRoot));
foreach($tampered as $x) @unlink("$tmpRoot$x");
rrmdir("$tmpRoot$Pdir");

//the manifest: HTML lines only
$manifest = $sp->manifestPath();
$manifestBefore = is_file($manifest) ? file_get_contents($manifest) : null;
putFiles($tmpRoot, array("$Pdir/a/index.xml", "$Pdir/a/index.html"));
file_put_contents($manifest, "$Pdir/a/index.xml\n$Pdir/a/index.html\n");
$sp->wiper()->wipeCachesManifest();
check('manifest listing a/index.xml and a/index.html: wipeCachesManifest() removes the HTML file only', is_file("$tmpRoot$Pdir/a/index.xml") && !is_file("$tmpRoot$Pdir/a/index.html"), files($tmpRoot));
file_put_contents($manifest, "$Pdir/a/index.xml\n$Pdir/a/index.html\n");
$sp->index()->importManifest();
check('importManifest() records the HTML line only', tagsOf($sp, "$Pdir/a/index.html") === array('*') && !count(tagsOf($sp, "$Pdir/a/index.xml")) && !is_file($manifest));
$sp->index()->clearRows();
if($manifestBefore !== null) file_put_contents($manifest, $manifestBefore);
else @unlink($manifest);
rrmdir("$tmpRoot$Pdir");

//the scan, on layout() and more
$rootHand = array('/robots.txt', '/sitemap.xml', '/index.xml', '/index.txt');
$typedLayout = array("$Pdir/a/index.xml", "$Pdir/a/.index.xml.999.tmp", "$Pdir/b/index.txt", "$Pdir/c.xml/index.xml");
function typedLayout($tmpRoot, $Pdir, array $rootHand, array $typedLayout) {
	layout($tmpRoot, $Pdir);
	putFiles($tmpRoot, array_merge($rootHand, $typedLayout));
}
$typedSurvivors = array_merge($survivors, $rootHand);
$sp->set('cacheNonHtml', 1);
foreach(array(0, 1) as $shell) {
	$sp->set('useShell', $shell);
	$label = $shell ? (PHP_OS_FAMILY === 'Windows' ? 'PowerShell' : 'find') : 'PHP walk';
	typedLayout($tmpRoot, $Pdir, $rootHand, $typedLayout);
	if(!$shell) {
		$before = files($tmpRoot);
		$beforeList = explode(' ', $before);
		$dry = $sp->wipeCaches(true, true);
		$paths = $dry['paths'] ?? array();
		check('switch on, dry run: changes nothing, lists none of the four root files', files($tmpRoot) === $before && !count(array_intersect($rootHand, $paths)), implode(' ', $paths));
	}
	$sp->wipeCaches(true);
	$after = files($tmpRoot);
	$lost = array_filter($typedSurvivors, fn($x) => strpos(" $after ", " $x ") === false);
	check("switch on, forced wipe ($label): the four root files and the other survivors stay, the page dir goes", !count($lost) && !is_dir("$tmpRoot$Pdir"), 'lost: ' . implode(' ', $lost) . "; $after");
	if(!$shell) {
		$removed = array_values(array_diff($beforeList, explode(' ', $after)));
		$listed = $paths;
		sort($removed);
		sort($listed);
		check('switch on, dry run: lists exactly what the real scan then removes', $removed === $listed, json_encode(array($removed, $listed)));
	}
}
$sp->set('useShell', 0);
if(PHP_OS_FAMILY === 'Windows' && trim((string) @shell_exec('bash -c "command -v find" 2>NUL')) !== '') {
	typedLayout($tmpRoot, $Pdir, $rootHand, $typedLayout);
	file_put_contents("$tmp/find.sh", $sp->wiper()->unixScanCommand($sp->wiper()->scanTargets()) . "\n");
	$out = (string) shell_exec('bash ' . escapeshellarg("$tmp/find.sh"));
	$after = files($tmpRoot);
	$lost = array_filter($typedSurvivors, fn($x) => strpos(" $after ", " $x ") === false);
	check('switch on, find line (GNU find via bash): the four root files and the other survivors stay, the page dir goes', !count($lost) && !is_dir("$tmpRoot$Pdir") && strpos($out, 'StaticPages-scan-done') !== false, 'lost: ' . implode(' ', $lost) . "; $after");
}
$sp->set('cacheNonHtml', 0);
typedLayout($tmpRoot, $Pdir, $rootHand, $typedLayout);
$before = files($tmpRoot);
$pdirFiles = array_values(array_filter(explode(' ', $before), fn($x) => strpos($x, "$Pdir/") === 0));
$sp->wipeCaches(true);
$after = files($tmpRoot);
$gone = array_filter($pdirFiles, fn($x) => strpos(" $after ", " $x ") === false);
check('switch off, forced wipe: the page dir holding index.xml is left alone, nothing below it is deleted', in_array(ltrim($Pdir, '/'), $sp->wiper()->leftAlone()) && !count($gone), 'gone: ' . implode(' ', $gone));

//the find line: the switch off as 0.10.0 builds it, the switch on no index.xml at the root
$targets = $sp->wiper()->scanTargets();
$targets[] = "$tmpRoot/x";
$q = escapeshellarg($sp->staticFileName()) . ' -o -name ' . escapeshellarg($sp->publisher()->tmpFileGlob());
$literal = 'find ' . escapeshellarg($sp->rootPath()) . " -mindepth 1 -maxdepth 1 -type f \\( -name $q \\) -delete 2>/dev/null; find " . implode(' ', array_map('escapeshellarg', $targets)) . " -depth \\( -type f \\( -name $q \\) -delete \\) -o \\( -type d -empty -delete \\) 2>/dev/null; echo StaticPages-scan-done";
check('unixScanCommand(), switch off: the line 0.10.0 builds, character for character', $sp->wiper()->unixScanCommand($targets) === $literal, $sp->wiper()->unixScanCommand($targets));
$sp->set('cacheNonHtml', 1);
$parts = explode('; ', $sp->wiper()->unixScanCommand($targets));
check('unixScanCommand(), switch on: the root find holds no index.xml, the targets find does', strpos($parts[0], 'index.xml') === false && strpos($parts[1], escapeshellarg('index.xml')) !== false && strpos($parts[1], escapeshellarg('.index.txt.*.tmp')) !== false, implode(' | ', $parts));
rrmdir("$tmpRoot$Pdir");
foreach($rootHand as $x) @unlink("$tmpRoot$x");

//the default Non-static directories
putFiles($tmpRoot, array("$Pdir/index.html", "$Pdir/a/index.xml"));
$sp->set('cacheNonHtml', 1);
$listedOn = in_array(ltrim($Pdir, '/'), $sp->defaultNonStaticDirs(), true);
$sp->set('cacheNonHtml', 0);
$listedOff = in_array(ltrim($Pdir, '/'), $sp->defaultNonStaticDirs(), true);
check('defaultNonStaticDirs(): a page dir holding index.html and a/index.xml is not listed with the switch on, listed with it off', !$listedOn && $listedOff);

//adoption
@unlink("$tmpRoot$Pdir/index.html");
$sp->index()->clearRows();
$sp->set('cacheNonHtml', 0);
$m->adoptStaticFiles();
$adoptedOff = tagsOf($sp, "$Pdir/a/index.xml");
$sp->set('cacheNonHtml', 1);
$m->adoptStaticFiles();
$adoptedOn = tagsOf($sp, "$Pdir/a/index.xml");
check('adoption: an unrecorded a/index.xml is recorded with * with the switch on, not with it off', $adoptedOff === array() && $adoptedOn === array('*'), json_encode(array($adoptedOff, $adoptedOn)));

//Q17 (a): a recorded index.xml under a tracked pattern is no source file while the switch is on
$trackedBefore = $sp->get('trackedPaths');
$sp->set('trackedPaths', ltrim($Pdir, '/') . '/');
$sp->checkTrackedPaths(true);
file_put_contents("$tmpRoot$Pdir/a/index.xml", 'changed, longer');
$maxId = queueMaxId($sp);
$sp->checkTrackedPaths(true);
$queuedOn = queueRowsAfter($sp, $maxId);
$sp->set('cacheNonHtml', 0);
$excludedOff = $sp->tracker()->isExcludedFromTracking("$tmpRoot$Pdir/a/index.xml");
$htmlOff = $sp->tracker()->isExcludedFromTracking("$tmpRoot$Pdir/a/index.html");
check('tracked pattern over a recorded index.xml: its change queues nothing with the switch on; with it off isExcludedFromTracking() answers as 0.10.0 (index.xml no, index.html yes)', !count($queuedOn) && $excludedOff === false && $htmlOff === true, json_encode($queuedOn));
if($trackedBefore === null) $sp->remove('trackedPaths');
else $sp->set('trackedPaths', $trackedBefore);

//uninstall by the index
$sp->set('cacheNonHtml', 1);
$sp->index()->clearRows();
putFiles($tmpRoot, array("$Pdir/a/index.xml"));
recordAll($sp, array("$Pdir/a/index.xml"));
$out = htUninstall($sp);
check('onUninstall() removes a recorded index.xml', !is_file("$tmpRoot$Pdir/a/index.xml") && !count($sp->index()->indexedFiles(null)), files($tmpRoot));
htForget($tmp);
rrmdir("$tmpRoot$Pdir");

//a change of the stored switch on a save of the whole config wipes, before the value is stored
$switchFiles = array("$Pdir/a/index.html", "$Pdir/b/index.xml");
$setUpSwitch = function() use ($sp, $tmpRoot, $switchFiles) {
	$sp->index()->clearRows();
	putFiles($tmpRoot, $switchFiles);
	recordAll($sp, $switchFiles);
};
$present = fn() => count(array_filter($switchFiles, fn($x) => is_file("$tmpRoot$x")));
$base = $modules->getConfig('StaticPages');
unset($base['cacheNonHtml']);
$modules->saveConfig('StaticPages', $base);
$sp->remove('cacheNonHtml');
$seen = array();
$seenHook = $wire->addHookBefore('Modules::saveConfig', function($e) use (&$seen, $modules, $present) {
	if(is_array($e->arguments(1))) $seen[] = array($modules->getConfig('StaticPages', 'cacheNonHtml'), $present());
}, array('priority' => 200));
$setUpSwitch();
$same = $base;
$same['cacheNonHtml'] = 0;
$modules->saveConfig('StaticPages', $same);
$modules->saveConfig('StaticPages', $same);
$modules->saveConfig('StaticPages', 'indexSchema', StaticPages::schemaVersion);
check('a save keeping the switch (absent, then 0, twice) and a single key save remove nothing', $present() === 2 && count($sp->index()->indexedFiles(null)) === 2);
$log0 = logText($tmp);
$seen = array();
$on = $base;
$on['cacheNonHtml'] = 1;
$modules->saveConfig('StaticPages', $on);
check('a save turning the switch on removes the recorded files before the value is stored, and logs it', $present() === 0 && $seen === array(array(0, 0)) && (int) $modules->getConfig('StaticPages', 'cacheNonHtml') === 1 && strpos(substr(logText($tmp), strlen($log0)), 'Cache XML and plain text responses turned on') !== false, json_encode($seen));
$sp->set('cacheNonHtml', 1);
$setUpSwitch();
$modules->saveConfig('StaticPages', $on);
$modules->saveConfig('StaticPages', $on);
check('a save with the switch on again, twice, removes nothing', $present() === 2);
$off = $base;
$off['cacheNonHtml'] = 0;
$off['wipeDryRun'] = 1;
$log0 = logText($tmp);
$modules->saveConfig('StaticPages', $off);
//a dry run save holds back every .htaccess write for the rest of the request: taken back
$dryRunHeld = new \ReflectionProperty(StaticPagesHtaccess::class, 'dryRunSave');
$dryRunHeld->setAccessible(true);
$dryRunHeld->setValue($sp->htaccess(), false);
$stored = $modules->getConfig('StaticPages');
check('Q16 (a): a save turning the switch off with Dry run checked still wipes, stores the new value, clears Dry run', $present() === 0 && !count($sp->index()->indexedFiles(null)) && (int) $stored['cacheNonHtml'] === 0 && empty($stored['wipeDryRun']) && strpos(substr(logText($tmp), strlen($log0)), 'Cache XML and plain text responses turned off') !== false, json_encode($stored));
$wire->removeHook($seenHook);
$modules->saveConfig('StaticPages', $base);
$sp->remove('cacheNonHtml');
htForget($tmp);
rrmdir("$tmpRoot$Pdir");

////  rendering to index.xml and index.txt (0.11.0)

//the fixture template and the home page's answer XML in memory only, never saved: put back at
//the end of this block and by cleanup()
$homeTpl = $home->template;
foreach(array($tpl, $homeTpl) as $t) if(!isset($contentTypesAtStart[$t->id])) $contentTypesAtStart[$t->id] = array($t, $t->contentType);
$tplType = $tpl->contentType;
$homeType = $homeTpl->contentType;
$since = fn($log0) => substr(logText($tmp), strlen($log0));
$xmlRel = "$Pdir/a/index.xml";
$xmlFile = "$tmpRoot$xmlRel";
$htmlFile = "$tmpRoot$Pdir/a/index.html";
$body = '<urlset><meta name=csrf-token content=tok></urlset>';
$resetA = function() use ($sp, $tmpRoot, $Pdir) {
	$sp->index()->clearRows();
	rrmdir("$tmpRoot$Pdir/a");
};
$saveA = function($title) use ($pages, $A, $sp) {
	$a = $pages->get($A->id);
	$a->title = $title;
	$pages->save($a);
	$sp->drain();
};
$sp->index()->clearRows();
$sp->set('cacheNonHtml', 1);

$tpl->contentType = 'xml';
publish($sp, $A, array(), $body);
$rows = $sp->index()->indexedFiles(null);
check('switch on, XML template: a/index.xml holds the render byte for byte, CSRF token kept; no index.html, no temporary file', (string) @file_get_contents($xmlFile) === $body && !is_file($htmlFile) && strpos(files("$tmpRoot$Pdir"), '.tmp') === false, files("$tmpRoot$Pdir"));
check('...its index row names a/index.xml', array_values($rows) === array($xmlRel), json_encode($rows));
$tagsXml = tagsOf($sp, $xmlRel);
$tpl->contentType = $tplType;
publish($sp, $A, array(), $body);
$tagsHtml = tagsOf($sp, "$Pdir/a/index.html");
check('...its tags are those the same render gets as index.html', $tagsXml !== array() && $tagsXml === $tagsHtml, json_encode(array($tagsXml, $tagsHtml)));
$sp->index()->dropIndexRows(array(md5("$Pdir/a/index.html")));
@unlink($htmlFile);
$tpl->contentType = 'xml';
$saveA('A2');
check('saving the page and drain() remove index.xml and its dir', !is_file($xmlFile) && !is_dir("$tmpRoot$Pdir/a") && !count($sp->index()->indexedFiles(null)), files($tmpRoot));

$sp->set('cacheNonHtml', 0);
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
check('switch off, XML template: nothing written, skipped with content type is \'xml\'', files("$tmpRoot$Pdir") === '' && strpos($since($log0), "skip $Pdir/a/: content type is 'xml'") !== false, files("$tmpRoot$Pdir"));
$sp->set('cacheNonHtml', 1);
$tpl->contentType = 'json';
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
check('switch on, template content type json: nothing written, skip logged', files("$tmpRoot$Pdir") === '' && strpos($since($log0), "skip $Pdir/a/: content type is 'json'") !== false, files("$tmpRoot$Pdir"));

$homeTpl->contentType = 'xml';
$log0 = logText($tmp);
publish($sp, $home, array(), $body);
$homeTpl->contentType = $homeType;
check('switch on, the home page with an XML template: no /index.xml, no /index.html, skip logged', !is_file("$tmpRoot/index.xml") && !is_file("$tmpRoot/index.html") && strpos($since($log0), 'a non-html copy of the home page is not written') !== false, files($tmpRoot));

$tpl->contentType = 'xml';
$wire->input->setUrlSegment(1, 'feed');
publish($sp, $A, array(), $body);
$wire->input->setUrlSegment(1, null);
check('switch on: a render with a URL segment writes a/feed/index.xml', (string) @file_get_contents("$tmpRoot$Pdir/a/feed/index.xml") === $body && $wire->input->urlSegmentStr === '', files("$tmpRoot$Pdir"));
$resetA();

$C = $mk($P, 'c.xml', 'C');
$Dx = $mk($P, 'd.xml', 'Dx');
$sp->drain();
publish($sp, $C, array(), $body);
check('a page named c.xml is written to c.xml/index.xml', $C->name === 'c.xml' && (string) @file_get_contents("$tmpRoot$Pdir/c.xml/index.xml") === $body, files("$tmpRoot$Pdir"));
$c = $pages->get($C->id);
$c->title = 'C2';
$pages->save($c);
$sp->drain();
check('...a save removes the file and the dir c.xml', !is_dir("$tmpRoot$Pdir/c.xml"), files("$tmpRoot$Pdir"));
@mkdir("$tmpRoot$Pdir", 0777, true);
file_put_contents("$tmpRoot$Pdir/d.xml", 'hand-made');
$log0 = logText($tmp);
publish($sp, $Dx, array(), $body);
check('a hand-made file d.xml where the dir of the page d.xml would go: nothing written, its bytes stay, could not create logged', $Dx->name === 'd.xml' && is_file("$tmpRoot$Pdir/d.xml") && file_get_contents("$tmpRoot$Pdir/d.xml") === 'hand-made' && strpos($since($log0), "skip $Pdir/d.xml/: could not create") !== false, files("$tmpRoot$Pdir"));
@unlink("$tmpRoot$Pdir/d.xml");

//without index tables: xml refused, html to the manifest
$resetA();
$manifest = $sp->manifestPath();
$manifestBefore = is_file($manifest) ? file_get_contents($manifest) : null;
@unlink($manifest);
$tablesAtStart = $tablesReady->getValue($sp->index());
$tablesReady->setValue($sp->index(), false);
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
$noTablesXml = array(is_file($xmlFile), is_dir("$tmpRoot$Pdir/a"), strpos($since($log0), 'no index tables: only html is cached with the manifest') !== false, is_file($manifest));
$tpl->contentType = $tplType;
publish($sp, $A, array(), $body);
$noTablesHtml = array(is_file($htmlFile), trim((string) @file_get_contents($manifest)));
$tablesReady->setValue($sp->index(), $tablesAtStart);
if($manifestBefore !== null) file_put_contents($manifest, $manifestBefore);
else @unlink($manifest);
check('without index tables: an XML render writes nothing, skip logged; an HTML render still goes to the manifest', $noTablesXml === array(false, false, true, false) && $noTablesHtml === array(true, "$Pdir/a/index.html"), json_encode(array($noTablesXml, $noTablesHtml)));

//a file nothing recorded is never written over; once recorded it is
$resetA();
putFiles($tmpRoot, array($xmlRel));
$tpl->contentType = 'xml';
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
check('an unrecorded a/index.xml in the way: its bytes stay, skip logged', (string) @file_get_contents($xmlFile) === 'x' && strpos($since($log0), "a file the index does not know is in the way: $xmlRel") !== false, (string) @file_get_contents($xmlFile));
$sp->index()->recordStaticFile($xmlRel, $A->id, array("p:{$A->id}"));
publish($sp, $A, array(), $body);
check('...once recorded, the next publish replaces it', (string) @file_get_contents($xmlFile) === $body);

//the fileContent hook runs for xml too
$resetA();
$seen = null;
$hookId = $wire->addHookAfter('StaticPages::fileContent', function(HookEvent $e) use (&$seen) { $seen = $e->arguments(1); $e->return = 'hooked ' . $e->return; });
publish($sp, $A, array(), $body);
$wire->removeHook($hookId);
check('a fileContent hook gets the XML render as rendered, its return is what index.xml holds', $seen === $body && (string) @file_get_contents($xmlFile) === "hooked $body", (string) @file_get_contents($xmlFile));
$resetA();
$hookId = $wire->addHookAfter('StaticPages::fileContent', function(HookEvent $e) { $e->return = ''; });
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
$wire->removeHook($hookId);
check('...returning \'\': nothing written, no dir, skip logged', !is_file($xmlFile) && !is_dir("$tmpRoot$Pdir/a") && strpos($since($log0), "skip $Pdir/a/: a fileContent hook returned nothing") !== false);

//a broken CSRF token tags line stops HTML only
$resetA();
$sp->set('csrfPatterns', '/(unclosed/');
$tpl->contentType = $tplType;
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
$csrfHtml = array(is_file($htmlFile), strpos($since($log0), "skip $Pdir/a/: markup preparation failed") !== false);
$tpl->contentType = 'xml';
publish($sp, $A, array(), $body);
$sp->remove('csrfPatterns');
check('a broken CSRF token tags line: an HTML render is skipped (markup preparation failed), an XML render is written', $csrfHtml === array(false, true) && (string) @file_get_contents($xmlFile) === $body, json_encode($csrfHtml));

//AUDIT_CHECKLIST StaticPages 1: a template changing its type between render and save
$resetA();
$tpl->contentType = 'xml';
publish($sp, $A, array(), $body);
$typeChange = array(is_file($xmlFile));
$tpl->contentType = $tplType;
$saveA('A3');
$typeChange[] = is_file($xmlFile);
publish($sp, $A, array(), $body);
$typeChange[] = is_file($htmlFile);
$tpl->contentType = 'xml';
$saveA('A4');
$typeChange[] = is_file($htmlFile);
check('cached as index.xml, the type changed to html, saved: index.xml goes; cached as index.html, changed back, saved: index.html goes', $typeChange === array(true, false, true, false), json_encode($typeChange));

//the uninstall flag, for xml
$resetA();
$sp->markUninstalling();
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
$flagCase = array(is_file($xmlFile), is_dir("$tmpRoot$Pdir/a"), strpos($since($log0), "skip $Pdir/a/: the module is being uninstalled") !== false);
$sp->markUninstalling(false);
check('with the uninstall flag set an XML render writes nothing, skip logged; the flag is taken back', $flagCase === array(false, false, true) && htFlagStored($sp) === null && !$sp->uninstalling(), json_encode($flagCase));

//Q10 (a): a response typed XML by its header only. headers_list() is empty under CLI: the
//header list is handed in through StaticPagesPublisher::$responseHeaders
$resetA();
$tpl->contentType = '';
$sp->set('cacheNonHtml', 0);
$sp->publisher()->responseHeaders = array('Content-Type: application/xml; charset=utf-8');
$log0 = logText($tmp);
publish($sp, $A, array(), $body);
$q10off = array(is_file($xmlFile), is_file($htmlFile), strpos($since($log0), "skip $Pdir/a/: the response is sent as application/xml; charset=utf-8") !== false);
$sp->set('cacheNonHtml', 1);
publish($sp, $A, array(), $body);
$q10on = (string) @file_get_contents($xmlFile) === $body && !is_file($htmlFile);
$sp->publisher()->responseHeaders = null;
check('Q10 (a): a template without a content type, the response typed XML by its header: switch off skipped as the response is sent as <mime>; switch on written to index.xml', $q10off === array(false, false, true) && $q10on, json_encode(array($q10off, $q10on)));

$tpl->contentType = $tplType;
$homeTpl->contentType = $homeType;
foreach(array($C, $Dx) as $x) $pages->delete($pages->get($x->id), true);
$saveA('A');
$sp->index()->clearRows();
$sp->remove('cacheNonHtml');
rrmdir("$tmpRoot$Pdir");
check('XML block: content types back as they were, nothing left on the temporary root below P', $tpl->contentType === $contentTypesAtStart[$tpl->id][1] && $homeTpl->contentType === $contentTypesAtStart[$homeTpl->id][1] && !is_dir("$tmpRoot$Pdir"));

////  root files: take over, write, delete by record, give back (0.11.0)

//robots.txt, .robots.txt and the .htaccess below are fixtures on the temporary root.
//StaticPagesHtaccess::$blockRoute stands for "the generated block holds the route" ("the block
//condition"): true unless a check says otherwise; null where a check asks the generator
$R = $mk($P, 'r', 'R');
$sp->drain();
$rPath = $R->path;
$rRel = "$Pdir/r";
$robots = "$tmpRoot/robots.txt";
$dotRobots = "$tmpRoot/.robots.txt";
$original = "User-agent: *\nDisallow: /hand-made/\n";
$txtBody = "User-agent: *\nDisallow: /rendered/\n";
$rLine = "robots.txt $rPath";
$listed = array('cacheNonHtml' => 1, 'rootFiles' => $rLine);
$rootBase = $modules->getConfig('StaticPages');
$rootSave = function(array $set) use ($modules, $rootBase, $wire, $noticeTexts) {
	$d = $rootBase;
	foreach($set as $k => $v) $d[$k] = $v;
	$from = count($wire->notices);
	$modules->saveConfig('StaticPages', $d);
	return $noticeTexts($from);
};
$rootReset = function($robotsBytes = null, $dotBytes = null) use ($robots, $dotRobots, $sp) {
	$sp->index()->clearRows();
	@unlink($robots);
	@unlink($dotRobots);
	if($robotsBytes !== null) file_put_contents($robots, $robotsBytes);
	if($dotBytes !== null) file_put_contents($dotRobots, $dotBytes);
	clearstatcache();
};
$bytesOf = function($file) { clearstatcache(); return is_file($file) ? file_get_contents($file) : null; };
$hasAny = fn(array $texts, $needle) => count(array_filter($texts, fn($t) => strpos($t, $needle) !== false)) > 0;
$methodLines = array('RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$', 'RewriteCond %{REQUEST_URI} ^/((?:[-_.a-zA-Z0-9]+/)*)$', 'RewriteRule ^ gate.php [L]');
$tplType = $tpl->contentType;
htClean($tmpRoot);
htPut($htFile, $withBlock);
$h->blockRoute = fn($name, $path) => true;
$sp->set('cacheNonHtml', 1);
$sp->set('rootFiles', $rLine);
$rootReset();
$rootSave($listed);

//taking over
$rootReset($original);
$log0 = logText($tmp);
$texts = $rootSave($listed);
check('root files: a settings save with robots.txt listed renames a hand-made /robots.txt to /.robots.txt with its bytes and logs it, no /robots.txt left', $h->status()['state'] === 'current' && $bytesOf($robots) === null && $bytesOf($dotRobots) === $original && strpos($since($log0), "root file robots.txt: taken over for $rPath, the original is kept as .robots.txt") !== false, json_encode($texts) . ' ' . $since($log0));
$log0 = logText($tmp);
$rootSave($listed);
check('...a second save renames nothing', $bytesOf($robots) === null && $bytesOf($dotRobots) === $original && strpos($since($log0), 'root file robots.txt') === false, $since($log0));

$refused = array();
$rootReset($original);
$h->blockRoute = fn($name, $path) => false;
$texts = $rootSave($listed);
$h->blockRoute = fn($name, $path) => true;
$refused['block'] = array($bytesOf($robots) === $original, $bytesOf($dotRobots) === null, $hasAny($texts, 'does not hand /robots.txt to the page'));
$tablesAtStart = $tablesReady->getValue($sp->index());
$tablesReady->setValue($sp->index(), false);
$texts = $rootSave($listed);
$tablesReady->setValue($sp->index(), $tablesAtStart);
$refused['tables'] = array($bytesOf($robots) === $original, $bytesOf($dotRobots) === null, $hasAny($texts, 'there are no index tables'));
$texts = $rootSave(array('cacheNonHtml' => 0, 'rootFiles' => $rLine));
$refused['off'] = array($bytesOf($robots) === $original, $bytesOf($dotRobots) === null, $hasAny($texts, "\"$rLine\" does nothing while"));
$rootReset();
$rootSave($listed);
check('...the same save with the block condition false, without index tables, or with the switch off: /robots.txt byte-identical, no /.robots.txt, a warning names the reason', !in_array(false, array_merge(...array_values($refused)), true), json_encode($refused));

$rootReset($original, 'an older backup');
$texts = $rootSave($listed);
check('...the same save with a /.robots.txt already there: both files byte-identical, a warning says a backup is in the way', $bytesOf($robots) === $original && $bytesOf($dotRobots) === 'an older backup' && $hasAny($texts, 'a backup /.robots.txt is in the way'), json_encode($texts));

$rootReset($original);
$log0 = logText($tmp);
$rootSave($listed + array('wipeDryRun' => 1));
$dryRunHeld->setValue($h, false);
check('Q16 (a): the same save with Dry run checked renames nothing and logs the rename it held back', $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && strpos($since($log0), 'dry run: root file robots.txt: would be renamed to .robots.txt, nothing was') !== false, $since($log0));

//writing
$rootReset($original);
$rootSave($listed);
$tpl->contentType = 'txt';
publish($sp, $R, array(), $txtBody);
$rows = $sp->index()->indexedFiles(null);
check('a TXT render of the listed page writes /robots.txt, no index.txt below its page dir; the index row names /robots.txt; /.robots.txt untouched', $bytesOf($robots) === $txtBody && !is_file("$tmpRoot$rRel/index.txt") && array_values($rows) === array('/robots.txt') && $bytesOf($dotRobots) === $original, json_encode($rows) . ' ' . files($tmpRoot));
$tpl->contentType = 'xml';
$log0 = logText($tmp);
publish($sp, $R, array(), '<urlset/>');
$tpl->contentType = 'txt';
check('...an XML render of that page writes nothing and logs why', $bytesOf($robots) === $txtBody && !is_dir("$tmpRoot$rRel") && count($sp->index()->indexedFiles(null)) === 1 && strpos($since($log0), "skip $rPath: the page is listed in Replace these root files with pages as robots.txt, a xml render is not written") !== false, $since($log0));
$saveR = function($title) use ($pages, $R, $sp) {
	$r = $pages->get($R->id);
	$r->title = $title;
	$pages->save($r);
	$sp->drain();
};
$saveR('R2');
$savedR = array($bytesOf($robots), $bytesOf($dotRobots) === $original, count($sp->index()->indexedFiles(null)));
publish($sp, $R, array(), $txtBody);
check('saving the page and drain() remove /robots.txt, /.robots.txt untouched; the next render writes it again', $savedR === array(null, true, 0) && $bytesOf($robots) === $txtBody, json_encode($savedR));

//a changed page path for a name that stays listed [decided by user, Q26]
$B = $mk($P, 'b', 'B');
$sp->drain();
$bPath = $B->path;
$bBody = "User-agent: *\nDisallow: /page-b/\n";
$log0 = logText($tmp);
$rootSave($listed);
check('Q26: a save with the robots.txt line unchanged deletes nothing: the recorded /robots.txt and its row stay, /.robots.txt untouched', $bytesOf($robots) === $txtBody && $sp->index()->hasFile('/robots.txt') && $bytesOf($dotRobots) === $original && strpos($since($log0), 'root file robots.txt') === false, $since($log0));
$log0 = logText($tmp);
$rootSave(array('cacheNonHtml' => 1, 'rootFiles' => "robots.txt $bPath"));
$sp->set('rootFiles', "robots.txt $bPath");
$moved = array($bytesOf($robots), $sp->index()->hasFile('/robots.txt'), $bytesOf($dotRobots) === $original, strpos($since($log0), "page path $rPath changed to $bPath: root file robots.txt: the copy was deleted") !== false, strpos($since($log0), 'renamed') === false);
publish($sp, $B, array(), $bBody);
$rows = $sp->index()->indexedFiles(null);
check('Q26: a save moving robots.txt from page /a/ to /b/ deletes the recorded /robots.txt and its row, renames nothing, /.robots.txt untouched; a render of /b/ writes /robots.txt anew', $moved === array(null, false, true, true, true) && $bytesOf($robots) === $bBody && array_values($rows) === array('/robots.txt') && !is_dir("$tmpRoot$Pdir/b") && $bytesOf($dotRobots) === $original, json_encode($moved) . ' ' . json_encode($rows) . ' ' . $since($log0));
$log0 = logText($tmp);
$rootSave($listed + array('wipeDryRun' => 1));
$dryRunHeld->setValue($h, false);
$sp->set('rootFiles', $rLine);
check('Q26, Q16 (a): the same change with Dry run checked deletes the recorded copy as well, /.robots.txt untouched', $bytesOf($robots) === null && !$sp->index()->hasFile('/robots.txt') && $bytesOf($dotRobots) === $original && strpos($since($log0), "page path $bPath changed to $rPath: root file robots.txt: the copy was deleted") !== false, $since($log0));

//a /robots.txt the index does not know, put there after the take-over
$sp->index()->clearRows();
file_put_contents($robots, 'put there by hand');
$intact = fn() => $bytesOf($robots) === 'put there by hand' && $bytesOf($dotRobots) === $original;
$log0 = logText($tmp);
publish($sp, $R, array(), $txtBody);
$unknown = array('render' => $intact() && strpos($since($log0), 'a file the index does not know is in the way: /robots.txt') !== false);
$sp->wipeCaches();
$unknown['wipeCaches()'] = $intact();
foreach(array(0, 1) as $shell) {
	$sp->set('useShell', $shell);
	$sp->wipeCaches(true);
	$unknown["wipeCaches(true), useShell $shell"] = $intact();
}
$sp->set('useShell', 0);
if(PHP_OS_FAMILY === 'Windows' && trim((string) @shell_exec('bash -c "command -v find" 2>NUL')) !== '') {
	file_put_contents("$tmp/find.sh", $sp->wiper()->unixScanCommand($sp->wiper()->scanTargets()) . "\n");
	shell_exec('bash ' . escapeshellarg("$tmp/find.sh"));
	$unknown['find line'] = $intact();
}
$manifest = $sp->manifestPath();
$manifestBefore = is_file($manifest) ? file_get_contents($manifest) : null;
file_put_contents($manifest, "/robots.txt\n/.robots.txt\n");
$sp->wiper()->wipeCachesManifest();
$unknown['manifest'] = $intact();
file_put_contents($manifest, "/robots.txt\n/.robots.txt\n");
$sp->index()->importManifest();
$m->adoptStaticFiles();
$unknown['importManifest, adoption'] = $intact() && !$sp->index()->hasFile('/robots.txt') && !$sp->index()->hasFile('/.robots.txt');
if($manifestBefore !== null) file_put_contents($manifest, $manifestBefore);
else @unlink($manifest);
check('an unrecorded /robots.txt after the take-over: a render, wipeCaches(), wipeCaches(true) by the PHP walk and the shell, the find line, a manifest line, importManifest() and the adoption all leave it and /.robots.txt', !in_array(false, $unknown, true), json_encode($unknown));

//rows a delete must not follow
$sp->index()->clearRows();
$rowCases = array();
$sp->remove('rootFiles');
recordAll($sp, array('/robots.txt'));
$sp->wiper()->wipeIndexed(null);
$rowCases['not listed'] = $intact();
$sp->set('rootFiles', $rLine);
$sp->set('cacheNonHtml', 0);
recordAll($sp, array('/robots.txt'));
$sp->wiper()->wipeIndexed(null);
$rowCases['switch off'] = $intact();
$sp->set('cacheNonHtml', 1);
putFiles($tmpRoot, array("$Pdir/robots.txt"));
recordAll($sp, array("$Pdir/robots.txt"));
$sp->wiper()->wipeIndexed(null);
$rowCases['below the root'] = is_file("$tmpRoot$Pdir/robots.txt");
@unlink("$tmpRoot$Pdir/robots.txt");
check('an index row for /robots.txt while the name is not listed or the switch is off, and one for <P>/robots.txt while it is listed: no file is deleted', !in_array(false, $rowCases, true), json_encode($rowCases));

$sp->index()->clearRows();
file_put_contents($robots, $txtBody);
recordAll($sp, array('/robots.txt'));
$dry = $sp->wipeCaches(false, true);
$dryScan = $sp->wipeCaches(true, true);
$dryPaths = array_merge($dry['paths'] ?? array(), $dryScan['paths'] ?? array());
check('a dry run of a wipe lists the recorded /robots.txt and deletes nothing; neither it nor a dry run scan lists /.robots.txt or /.htaccess', in_array('/robots.txt', $dry['paths'] ?? array(), true) && !in_array('/.robots.txt', $dryPaths, true) && !in_array('/.htaccess', $dryPaths, true) && $bytesOf($robots) === $txtBody && $bytesOf($dotRobots) === $original, json_encode($dryPaths));

//a forced wipe deletes the recorded copy [decided by user, Q30]: it left the file without a
//row, the next save took it over as a stale original and a give-back renamed it back
foreach(array(0, 1) as $shell) {
	$rootReset();
	$sp->set('useShell', $shell);
	publish($sp, $R, array(), $txtBody);
	$written = $bytesOf($robots) === $txtBody && $sp->index()->hasFile('/robots.txt');
	$sp->wipeCaches(true);
	$wiped = array($bytesOf($robots), $sp->index()->hasFile('/robots.txt'));
	$log0 = logText($tmp);
	$rootSave($listed);
	$afterSave = array($bytesOf($robots), $bytesOf($dotRobots));
	$rootSave(array('cacheNonHtml' => 1, 'rootFiles' => ''));
	$saves = $since($log0);
	$end = array($bytesOf($robots), $bytesOf($dotRobots));
	check("Q30: a recorded /robots.txt, wipeCaches(true) with useShell $shell: file and row gone; a save listing the name and one without it make no /.robots.txt, take nothing over, rename nothing back; no /robots.txt at the end", $written && $wiped === array(null, false) && $afterSave === array(null, null) && $end === array(null, null) && strpos($saves, 'taken over') === false && strpos($saves, 'renamed back') === false, json_encode(array($written, $wiped, $afterSave, $end)) . ' ' . $saves);
	$rootReset();
	$rootSave($listed);
}
$sp->set('useShell', 0);

$setUpTaken = function() use ($rootReset, $sp, $txtBody, $original) {
	$rootReset($txtBody, $original);
	recordAll($sp, array('/robots.txt'));
};
$setUpTaken();
$sp->wipeCaches(true);
$wiped = array($bytesOf($robots), $sp->index()->hasFile('/robots.txt'), $bytesOf($dotRobots) === $original);
publish($sp, $R, array(), $txtBody);
check('Q30: with a taken-over original, wipeCaches(true) deletes the recorded /robots.txt and its row, /.robots.txt byte-identical; the next render writes /robots.txt and its row again', $wiped === array(null, false, true) && $bytesOf($robots) === $txtBody && $sp->index()->hasFile('/robots.txt') && $bytesOf($dotRobots) === $original, json_encode($wiped));

$rowsBefore = count($sp->index()->indexedFiles(null));
$dryScan = $sp->wipeCaches(true, true);
$dryFiles = $dryScan['paths'] ?? array();
$m->adoptStaticFiles();
$dryKept = array(count(array_keys($dryFiles, '/robots.txt', true)), in_array('/.robots.txt', $dryFiles, true), $bytesOf($robots) === $txtBody, $sp->index()->hasFile('/robots.txt'), count($sp->index()->indexedFiles(null)) === $rowsBefore);
$filesBefore = explode(' ', files($tmpRoot));
$walk = $sp->wipeCaches(true);
$gone = array_values(array_diff($filesBefore, explode(' ', files($tmpRoot))));
$listedPaths = $dryFiles;
sort($gone);
sort($listedPaths);
check('Q30: wipeCaches(true, true) with the copy recorded lists /robots.txt once and no dot file, file and row stay, adoptStaticFiles() adds no row; the PHP walk then removes exactly what it listed, equal files counts', $dryKept === array(1, false, true, true, true) && $gone === $listedPaths && is_array($walk) && $walk['files'] === $dryScan['files'] && $bytesOf($dotRobots) === $original, json_encode(array($dryKept, $gone, $listedPaths, $walk['files'] ?? null, $dryScan['files'] ?? null)));

$rootReset('put there by hand', $original);
$scanCases = array();
$sp->remove('rootFiles');
recordAll($sp, array('/robots.txt'));
$sp->wipeCaches(true);
$scanCases['not listed'] = $intact();
$sp->set('rootFiles', $rLine);
$sp->set('cacheNonHtml', 0);
recordAll($sp, array('/robots.txt'));
$sp->wipeCaches(true);
$scanCases['switch off'] = $intact();
$sp->set('cacheNonHtml', 1);
putFiles($tmpRoot, array("$Pdir/robots.txt"));
recordAll($sp, array("$Pdir/robots.txt"));
$sp->wipeCaches(true);
$scanCases['below the root'] = is_file("$tmpRoot$Pdir/robots.txt") && $intact();
@unlink("$tmpRoot$Pdir/robots.txt");
check('Q30: wipeCaches(true) with a row for /robots.txt while the name is not listed or the switch is off, and one for <P>/robots.txt while it is listed: no file is deleted', !in_array(false, $scanCases, true), json_encode($scanCases));
$rootReset();

//giving back
$setUpTaken();
$log0 = logText($tmp);
$rootSave(array('cacheNonHtml' => 1, 'rootFiles' => ''));
check('a save removing the name: the recorded copy goes, /.robots.txt is renamed back to /robots.txt byte for byte, logged', $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && !$sp->index()->hasFile('/robots.txt') && strpos($since($log0), 'settings save: root file robots.txt: .robots.txt renamed back to robots.txt') !== false, $since($log0));
$rootSave($listed);
$takenAgain = $bytesOf($robots) === null && $bytesOf($dotRobots) === $original;
file_put_contents($robots, 'unrecorded');
$texts = $rootSave(array('cacheNonHtml' => 1, 'rootFiles' => ''));
check('...with an unrecorded /robots.txt in the way nothing is renamed and a warning says so', $takenAgain && $bytesOf($robots) === 'unrecorded' && $bytesOf($dotRobots) === $original && $hasAny($texts, 'a file /robots.txt is in the way'), json_encode($texts));
$rootReset();
$rootSave($listed);
$setUpTaken();
$rootSave(array('cacheNonHtml' => 0, 'rootFiles' => $rLine));
check('...switching Cache XML and plain text responses off does the same', $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && !$sp->index()->hasFile('/robots.txt'), files($tmpRoot));
$rootReset();
$rootSave($listed);

//uninstall [decided by user, Q15 (a)]: the copies by the index first, then the originals
@unlink($htFile);
$setUpTaken();
$log0 = logText($tmp);
htUninstall($sp);
$uninstallLog = $since($log0);
$wipeAt = strpos($uninstallLog, 'uninstall: 1 static file(s)');
$backAt = strpos($uninstallLog, 'uninstall: root file robots.txt: .robots.txt renamed back to robots.txt');
check('onUninstall() with a recorded /robots.txt and a /.robots.txt: the copy is deleted by the index, then the original is back byte for byte', $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && $wipeAt !== false && $backAt !== false && $wipeAt < $backAt && !count($sp->index()->indexedFiles(null)), $uninstallLog);
htForget($tmp);
$rootReset($txtBody);
recordAll($sp, array('/robots.txt'));
htUninstall($sp);
check('...with no backup only the copy goes', $bytesOf($robots) === null && $bytesOf($dotRobots) === null);
htForget($tmp);

//a hand-written route on a site that keeps its own rules: Keep chosen for the method group, so
//the save tells nothing about it (core shows a notice text once per request, a later check
//waits for that warning)
$h->blockRoute = null;
$routeLine = 'RewriteRule ^robots\.txt$ index.php?it=' . $rPath . ' [L,QSA]';
htPut($htFile, htInsert($stock, '# PW-PAGENAME', array_merge($methodLines, array($routeLine))));
$st = $h->status();
$listed['htaccessKeep'] = $h->keepHash($st);
$rootReset($original);
$rootSave($listed);
check('a candidate .htaccess holding the hand-written route "' . 'RewriteRule ^robots\.txt$ index.php?it=/<P>/r/ [L,QSA]' . '": the save takes /robots.txt over', $st['state'] === 'candidate' && count($st['routes']) === 1 && $bytesOf($robots) === null && $bytesOf($dotRobots) === $original, htSummary($st));
htPut($htFile, htInsert($stock, '# PW-PAGENAME', array_merge($methodLines, array('RewriteRule ^robots\.txt$ index.php?it=/other/ [L,QSA]'))));
$rootReset($original);
$log0 = logText($tmp);
$rootSave($listed);
$want = str_replace("\n", ' | ', $h->rootRouteLines('robots.txt', $rPath));
check('...with a route to another page path it does not, and the warning shows the lines to add', $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && strpos($want, $routeLine) !== false && $hasAny($noticeTexts(0), $want) && strpos($since($log0), "root file robots.txt: not taken over, the .htaccess holds no route to $rPath") !== false, $want);
$suggested = $h->classify(htInsert($stock, '# PW-PAGENAME', explode("\n", $h->rootRouteLines('robots.txt', $rPath))));
check('...the lines it shows are read back as a root file route, no candidate', htSummary($suggested) === 'absent ' && count($suggested['routes']) === 1 && $suggested['routes'][0]['name'] === 'robots.txt', htSummary($suggested));

//[decided by user, Q18 (a)] the block present and current counts in state candidate too; the
//block the saved settings generate, holding the route (the generator asked, no stub)
$h->blockRoute = null;
preg_match('/# BEGIN StaticPages.*?# END StaticPages/s', $withBlock, $oldBlock);
$listedBlock = $h->using(array_merge($rootBase, $listed))->apacheBlock();
$q18 = htInsert(str_replace($oldBlock[0], $listedBlock, $withBlock), '# BEGIN StaticPages', $methodLines);
htPut($htFile, str_replace('# The home page, from its copy.', '# The home page, from its copY.', $q18));
$rootReset($original);
$rootSave($listed);
$q18Outdated = array($h->status()['state'], $bytesOf($robots) === $original && $bytesOf($dotRobots) === null);
htPut($htFile, $q18);
$st = $h->status();
$rootSave($listed);
check('Q18 (a): state candidate with the block present and current: the save takes /robots.txt over; with a byte of the block changed it does not', $st['state'] === 'candidate' && $st['blockLines'] !== null && $bytesOf($robots) === null && $bytesOf($dotRobots) === $original && $q18Outdated === array('candidate', true), json_encode(array(htSummary($st), $q18Outdated)));

//the real block condition (step 06): a save adding the line to a current block rewrites the
//block first and takes the file over in the same save
$onNoLine = array('cacheNonHtml' => 1, 'rootFiles' => '');
$addLine = array('cacheNonHtml' => 1, 'rootFiles' => $rLine);
$rootReset();
htPut($htFile, str_replace($oldBlock[0], $h->using(array_merge($rootBase, $onNoLine))->apacheBlock(), $withBlock));
$rootSave($onNoLine);
$before = $h->status();
$rootReset($original);
$rootSave($addLine);
$after = $h->status();
check('a settings save adding "robots.txt <P>/r/" to a current block (switch on, no line): the block is rewritten with the route and /robots.txt is taken over in the same save', $before['state'] === 'current' && $after['state'] === 'current' && strpos((string) $bytesOf($htFile), $h->rootRouteLines('robots.txt', $rPath) . "\n# END StaticPages") !== false && $bytesOf($robots) === null && $bytesOf($dotRobots) === $original, json_encode(array(htSummary($before), htSummary($after))));
//a candidate without a hand-written route: no block is written, nothing taken over
$rootReset();
$rootSave($onNoLine);
htPut($htFile, htInsert($stock, '# PW-PAGENAME', $methodLines));
$st = $h->status();
$rootReset($original);
$log0 = logText($tmp);
$rootSave($addLine + array('htaccessKeep' => $h->keepHash($st)));
$want = str_replace("\n", ' | ', $h->rootRouteLines('robots.txt', $rPath));
check('...the same save with a candidate .htaccess holding no route: /robots.txt is not taken over, the warning shows the lines to add', $st['state'] === 'candidate' && $bytesOf($robots) === $original && $bytesOf($dotRobots) === null && strpos((string) $bytesOf($htFile), '# BEGIN StaticPages') === false && $hasAny($noticeTexts(0), $want) && strpos($since($log0), "root file robots.txt: not taken over, the .htaccess holds no route to $rPath") !== false, htSummary($st) . ' ' . $since($log0));
//[decided by user, Q19 (a)] S1 looks for the HTML name only
$h->using(array('cacheNonHtml' => 1));
$q19 = array();
foreach(array('xml', 'html') as $ext) $q19[$ext] = htSummary($h->classify(htInsert($stock, '# PW-PAGENAME', array("RewriteCond %{REQUEST_FILENAME}/index\\.$ext -f", "RewriteRule ^(.+/)$ \$1index\\.$ext [L]"))));
check('Q19 (a): switch on, a hand rule naming only index\\.xml is no candidate; the same rule naming index\\.html is (S1)', $q19['xml'] === 'absent ' && $q19['html'] === 'candidate 439-440:S1', json_encode($q19));
$h->using(null);
$rootReset();
htClean($tmpRoot);

//step 02's four hand-made root files, with two names listed
$rootReset();
htClean($tmpRoot);
$sp->set('rootFiles', "robots.txt $rPath\nsitemap.xml /sitemap.xml/");
foreach(array(0, 1) as $shell) {
	$sp->set('useShell', $shell);
	$label = $shell ? (PHP_OS_FAMILY === 'Windows' ? 'PowerShell' : 'find') : 'PHP walk';
	typedLayout($tmpRoot, $Pdir, $rootHand, $typedLayout);
	$sp->wipeCaches(true);
	$after = files($tmpRoot);
	$lost = array_filter($typedSurvivors, fn($x) => strpos(" $after ", " $x ") === false);
	check("robots.txt and sitemap.xml listed, forced wipe ($label): the four hand-made root files and the other survivors stay", !count($lost), 'lost: ' . implode(' ', $lost) . "; $after");
}
$sp->set('useShell', 0);
foreach($rootHand as $x) @unlink("$tmpRoot$x");

$h->blockRoute = null;
$modules->saveConfig('StaticPages', $rootBase);
$dryRunHeld->setValue($h, false);
$h->using(null);
htClean($tmpRoot);
htForget($tmp);
$sp->remove('rootFiles');
$sp->remove('cacheNonHtml');
$tpl->contentType = $tplType;
$pages->delete($pages->get($R->id), true);
$pages->delete($pages->get($B->id), true);
$sp->drain();
$sp->index()->clearRows();
$rootReset();
rrmdir("$tmpRoot$Pdir");
check('root files block: no robots.txt, .robots.txt, sitemap.xml, .htaccess or backup left on the temporary root, the stored config as before', !file_exists($robots) && !file_exists($dotRobots) && !file_exists("$tmpRoot/sitemap.xml") && !file_exists($htFile) && !htBackups($tmpRoot) && $modules->getConfig('StaticPages') == $rootBase, files($tmpRoot));

////  .htaccess manager (0.10.0)

//when the manager runs: install, the check after a superuser's admin request or a CLI run, a
//settings save, uninstall. Every .htaccess is a fixture on the temporary root; StaticPages' own
//___install() and ___uninstall() are never called (they create and drop the install's tables):
//what they do about the .htaccess and the copies is in onInstall() and onUninstall()
$h = $sp->htaccess();
$h->file = '';
$h->fetch = null;
$h->reread = null;
$h->using(null);
$moduleSource = (string) file_get_contents((new \ReflectionClass($sp))->getFileName());
$installBody = preg_match('/function ___install\(\) \{(.*?)\n\t\}/s', $moduleSource, $m) ? $m[1] : '';
$uninstallBody = preg_match('/function ___uninstall\(\) \{(.*?)\n\t\}/s', $moduleSource, $m) ? $m[1] : '';
$testSource = (string) file_get_contents(__FILE__);
check('___install() calls onInstall(), ___uninstall() calls onUninstall() before dropTables(); this script calls neither hook method', strpos($installBody, '$this->htaccess()->onInstall()') !== false && strpos($uninstallBody, '$this->htaccess()->onUninstall()') !== false && strpos($uninstallBody, 'dropTables()') > strpos($uninstallBody, 'onUninstall()') && !preg_match('/->(___)?(un)?install\(\s*\)/', $testSource) && !preg_match('/->(un)?install\(\s*[\'"]StaticPages/', $testSource));

//the module's shutdown, as after a superuser's admin request ('admin'), an editor's ('editor'),
//a visitor's ('front') or a CLI run ('cli'): the SAPI and the admin flag are properties the test
//sets, the user is switched for the call
$cliFlag = new \ReflectionProperty(StaticPages::class, 'cli');
$cliFlag->setAccessible(true);
$adminFlag = new \ReflectionProperty(StaticPages::class, 'adminRequest');
$adminFlag->setAccessible(true);
$userAtStart = $wire->user;
$superuser = $wire->users->get($config->superUserPageID);
$guest = $wire->users->getGuestUser();
function htAs(StaticPages $sp, $as, callable $fn) {
	global $wire, $cliFlag, $adminFlag, $superuser, $guest, $userAtStart;
	$cliFlag->setValue($sp, $as === 'cli');
	$adminFlag->setValue($sp, $as === 'admin' || $as === 'editor');
	$wire->users->setCurrentUser($as === 'admin' ? $superuser : $guest);
	try {
		return $fn();
	} finally {
		$cliFlag->setValue($sp, true);
		$adminFlag->setValue($sp, false);
		$wire->users->setCurrentUser($userAtStart);
	}
}
function htShutdown(StaticPages $sp, $as) {
	return htAs($sp, $as, fn() => $sp->shutdownDrain());
}
function htStamps($tmp) {
	clearstatcache();
	return array_map('basename', (array) glob("$tmp/cache/StaticPages/htaccess-*.seen"));
}
function htForget($tmp) {
	foreach((array) glob("$tmp/cache/StaticPages/htaccess-*.seen") as $f) @unlink($f);
	@unlink("$tmp/cache/StaticPages/htaccess.reported");
	clearstatcache();
}
function htPending(StaticPages $sp) {
	$p = $sp->wire()->modules->getConfig('StaticPages', 'htaccessNotice');
	return is_array($p) ? $p : array();
}
function htClearPending(StaticPages $sp) {
	$sp->wire()->modules->configs->saveConfig($sp, 'htaccessNotice', null);
	$sp->remove('htaccessNotice');
}
//onUninstall() as ___uninstall() runs it, then the flag taken back: core would delete the
//module's row (and the flag with it), this script goes on with the module installed
function htUninstall(StaticPages $sp) {
	try {
		return $sp->htaccess()->onUninstall();
	} finally {
		$sp->markUninstalling(false);
	}
}
//the uninstall flag as stored in the module's row right now, null for none
function htFlagStored(StaticPages $sp) {
	$query = $sp->wire()->database->prepare('SELECT data FROM modules WHERE class=:class');
	$query->bindValue(':class', 'StaticPages');
	$query->execute();
	$data = json_decode((string) $query->fetchColumn(), true);
	return is_array($data) && isset($data['uninstalling']) ? $data['uninstalling'] : null;
}
//the static-pages log lines written since $before that name the .htaccess
function htLogSince($tmp, $before) {
	return array_values(array_filter(explode("\n", substr(logText($tmp), strlen($before))), fn($l) => stripos($l, 'htaccess') !== false));
}
function htNoticeTexts($wire, $from) {
	$out = array();
	foreach(array_slice(iterator_to_array($wire->notices), $from) as $n) $out[] = get_class($n) . ': ' . $n->text;
	return $out;
}
function htSave(StaticPages $sp, array $set, array $unset = array()) {
	$modules = $sp->wire()->modules;
	$data = $modules->getConfig('StaticPages');
	foreach($set as $k => $v) $data[$k] = $v;
	foreach($unset as $k) unset($data[$k]);
	$modules->saveConfig('StaticPages', $data);
	return $modules->getConfig('StaticPages');
}
$settingsUrl = $config->urls->admin . 'module/edit?name=StaticPages';
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//onInstall(): absent gets the block; candidate and no file get nothing
htPut($htFile, $stock);
$r = $h->onInstall();
check('onInstall(), absent: the block is written, after one backup', $r['state'] === 'absent' && $r['result']['done'] === 'written' && file_get_contents($htFile) === $withBlock && count(htBackups($tmpRoot)) === 1, json_encode(array($r['state'], $r['result']['message'] ?? '')));
htClean($tmpRoot);
list($candidateFixture, $candidateLines) = htHand($stock, '# PW-PAGENAME', $readme);
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$n0 = count($wire->notices);
$r = $h->onInstall();
check('onInstall(), candidate: nothing written, nothing created in the root, a warning naming the settings page', $r['state'] === 'candidate' && $r['result'] === null && file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && !htBackups($tmpRoot) && count($r['notices']) === 1 && strpos($r['notices'][0][1], $settingsUrl) !== false && !htPending($sp), json_encode($r['notices']));
htClean($tmpRoot);
$listing = files($tmpRoot);
$r = $h->onInstall();
check('onInstall(), no .htaccess: nothing written, none created', $r['state'] === 'none' && $r['result'] === null && !file_exists($htFile) && files($tmpRoot) === $listing && !$r['notices']);
htForget($tmp);

//the check after a superuser's admin request: absent gets the block, one notice kept for the
//next admin page, one log line, one backup; a second one reads nothing
htPut($htFile, $stock);
$log0 = logText($tmp);
htShutdown($sp, 'admin');
$pending = htPending($sp);
$lines = htLogSince($tmp, $log0);
$stamps = htStamps($tmp);
check('admin shutdown, absent: the block is written, one backup, one log line, one notice kept for the next admin page, one stamp', file_get_contents($htFile) === $withBlock && count(htBackups($tmpRoot)) === 1 && count($lines) === 1 && strpos($lines[0], 'the StaticPages block was added') !== false && count($pending) === 1 && $pending[0]['type'] === 'message' && strpos($pending[0]['text'], 'block was added') !== false && count($stamps) === 1, json_encode(array($lines, $pending, $stamps)));
$stampFile = "$tmp/cache/StaticPages/{$stamps[0]}";
touch($stampFile, time() - 50);
clearstatcache();
$stampTime = filemtime($stampFile);
//a byte changed inside the block, the size and the mtime kept: read, it would be outdated
$htTime = filemtime($htFile);
$changed = str_replace('# The home page, from its copy.', '# The home page, from its copY.', $withBlock);
htPut($htFile, $changed);
touch($htFile, $htTime);
clearstatcache();
$log0 = logText($tmp);
htShutdown($sp, 'admin');
clearstatcache();
check('admin shutdown again, nothing changed by mtime or size: the stamp is the same file, untouched; the .htaccess is not read (a same-size edit inside the block stays), no backup, no log line, no new notice', htStamps($tmp) === $stamps && filemtime($stampFile) === $stampTime && file_get_contents($htFile) === $changed && count(htBackups($tmpRoot)) === 1 && !htLogSince($tmp, $log0) && count(htPending($sp)) === 1, json_encode(htStamps($tmp)));
htPut($htFile, $withBlock);

//the kept notice: not for an editor, not for an ajax request; shown once to a superuser, cleared
$n0 = count($wire->notices);
$editor = htAs($sp, 'editor', fn() => $sp->showPendingNotices());
$config->ajax = true;
$ajax = htAs($sp, 'admin', fn() => $sp->showPendingNotices());
$config->ajax = false;
$keptFor = count(htPending($sp));
$shown = htAs($sp, 'admin', fn() => $sp->showPendingNotices());
$texts = htNoticeTexts($wire, $n0);
$again = htAs($sp, 'admin', fn() => $sp->showPendingNotices());
check('the kept notice is not shown to a non-superuser nor on an ajax request, and stays; shown once to a superuser, then cleared from the stored config', $editor === 0 && $ajax === 0 && $keptFor === 1 && $shown === 1 && count($texts) === 1 && strpos($texts[0], 'NoticeMessage') !== false && strpos($texts[0], 'block was added') !== false && $again === 0 && !htPending($sp) && !array_key_exists('htaccessNotice', $modules->getConfig('StaticPages')) && $sp->get('htaccessNotice') === null, json_encode(array($editor, $ajax, $keptFor, $shown, $texts)));
htClean($tmpRoot);
htForget($tmp);

//Q3 [decided by user]: the module's own block edited by hand is rewritten by the check
htPut($htFile, str_replace('# END StaticPages', "# a hand edit\n# END StaticPages", $withBlock));
$log0 = logText($tmp);
htShutdown($sp, 'admin');
$lines = htLogSince($tmp, $log0);
$pending = htPending($sp);
check('admin shutdown, outdated (the block edited by hand): rewritten, one backup, one log line, one notice kept', file_get_contents($htFile) === $withBlock && count(htBackups($tmpRoot)) === 1 && count($lines) === 1 && strpos($lines[0], 'the StaticPages block was rewritten') !== false && count($pending) === 1 && strpos($pending[0]['text'], 'block was rewritten') !== false, json_encode(array($lines, $pending)));
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//a visitor's request and an editor's: nothing read, nothing written, no stamp
htPut($htFile, $stock);
$listing = files($tmpRoot);
htShutdown($sp, 'front');
htShutdown($sp, 'editor');
check('front-end and editor shutdowns: no stamp created, the absent fixture not written, no backup, no notice kept', !htStamps($tmp) && file_get_contents($htFile) === $stock && files($tmpRoot) === $listing && !htPending($sp));

//a CLI run does what a superuser's admin request does, and keeps the notice too
htShutdown($sp, 'cli');
check('CLI shutdown, absent: the block is written and the notice kept for a superuser\'s next admin page', file_get_contents($htFile) === $withBlock && count(htPending($sp)) === 1 && count(htStamps($tmp)) === 1);
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//candidate: nothing written, one warning naming the settings page, one log line; not again
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$spans = implode(', ', array_map(fn($g) => $g['start'] . '-' . $g['end'], $h->status()['candidates']));
$log0 = logText($tmp);
htShutdown($sp, 'admin');
$lines = htLogSince($tmp, $log0);
$pending = htPending($sp);
check('admin shutdown, candidate: nothing written, one warning naming the settings page and the lines, one log line', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && count($lines) === 1 && strpos($lines[0], 'nothing written for them until Replace or Keep') !== false && count($pending) === 1 && $pending[0]['type'] === 'warning' && strpos($pending[0]['text'], $settingsUrl) !== false && strpos($pending[0]['text'], "(lines $spans)") !== false, json_encode(array($spans, $lines, $pending)));
$log0 = logText($tmp);
htShutdown($sp, 'admin');
touch($htFile, time() - 30);
clearstatcache();
htShutdown($sp, 'admin');
check('admin shutdown, candidate again, and after the file was touched (its candidates unchanged): no new warning, no log line', count(htPending($sp)) === 1 && !htLogSince($tmp, $log0) && file_get_contents($htFile) === $candidateFixture, json_encode(htLogSince($tmp, $log0)));
htClearPending($sp);

//Keep: nothing written, the hash stored, no htaccessAction stored; no warning until an edit
$status = $h->status();
$stored = htSave($sp, array('htaccessAction' => 'keep'));
check('settings save with Keep: nothing written, htaccessKeep stored (the candidates\' hash), htaccessAction not stored', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && ($stored['htaccessKeep'] ?? '') === $h->keepHash($status) && $h->keepHash($status) !== '' && !array_key_exists('htaccessAction', $stored), json_encode($stored));
htClearPending($sp);
htShutdown($sp, 'admin');
touch($htFile, time() - 20);
clearstatcache();
htShutdown($sp, 'admin');
check('after Keep: the admin shutdowns raise no warning, also after the file was touched', !htPending($sp));
htPut($htFile, str_replace('RewriteRule ^(.+)/$ /$1/index.html [L]', 'RewriteRule ^(.+)/$ /$1/index.html [L,NC]', $candidateFixture));
htShutdown($sp, 'admin');
$pending = htPending($sp);
check('after Keep, one candidate line edited: the warning comes again', count($pending) === 1 && $pending[0]['type'] === 'warning', json_encode($pending));
htSave($sp, array(), array('htaccessKeep'));
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//Replace from the settings: what step 4's Replace gives; with Dry run nothing, the text logged
htPut($htFile, $candidateFixture);
$expected = $h->write(true, true)['text'];
$stored = htSave($sp, array('htaccessAction' => 'replace'));
check('settings save with Replace: the file is what write() with Replace gives, one backup, the state current; htaccessAction not stored', is_string($expected) && file_get_contents($htFile) === $expected && count(htBackups($tmpRoot)) === 1 && $h->status()['state'] === 'current' && !array_key_exists('htaccessAction', $stored));
htClean($tmpRoot);
htForget($tmp);
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$log0 = logText($tmp);
$stored = htSave($sp, array('htaccessAction' => 'replace', 'wipeDryRun' => 1));
$logged = substr(logText($tmp), strlen($log0));
check('settings save with Replace and Dry run: nothing written, no backup; the log holds the new text line by line; neither box stored checked', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && strpos($logged, 'htaccess dry run: ') !== false && strpos($logged, ': # BEGIN StaticPages') !== false && strpos($logged, ': ' . $prefix . 'RewriteRule ^(.+)/$ /$1/index.html [L]') !== false && substr_count($logged, 'htaccess dry run: ') === count(explode("\n", $expected)) + 1 && empty($stored['wipeDryRun']) && !array_key_exists('htaccessAction', $stored), substr($logged, 0, 300));
$dryRunSave = new \ReflectionProperty(StaticPagesHtaccess::class, 'dryRunSave');
$dryRunSave->setAccessible(true);
htShutdown($sp, 'admin');
check('after a save with Replace and Dry run, the same request\'s shutdown writes nothing and warns about nothing', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && !htPending($sp) && !htStamps($tmp), json_encode(htPending($sp)));
$dryRunSave->setValue($h, false);
htClean($tmpRoot);
htForget($tmp);

//[R4] Dry run on a save without a choice: an absent block is not written by that save, nor by
//the check after its response; the next request's check writes it
htPut($htFile, $stock);
$listing = files($tmpRoot);
$log0 = logText($tmp);
htSave($sp, array('wipeDryRun' => 1));
$logged = substr(logText($tmp), strlen($log0));
htShutdown($sp, 'admin');
$held = file_get_contents($htFile) === $stock && files($tmpRoot) === $listing && !htPending($sp);
$dryRunSave->setValue($h, false);
htShutdown($sp, 'admin');
check('settings save with Dry run, absent: the save and its own shutdown write nothing, the log holds the new text; the check of the next request writes the block', $held && strpos($logged, ': # BEGIN StaticPages') !== false && file_get_contents($htFile) === $withBlock && count(htBackups($tmpRoot)) === 1, substr($logged, 0, 200));
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//a saved Static file name rewrites a current block, from the array saved (the loaded module still
//has the old name)
htPut($htFile, $withBlock);
htSave($sp, array('staticFile' => 'cache.htm'));
$new = (string) file_get_contents($htFile);
$liveName = $sp->staticFileName();
check('settings save changing Static file name, current: the block is rewritten to the new name, one backup', strpos($new, 'cache\\.htm') !== false && strpos($new, 'index\\.html') === false && count(htBackups($tmpRoot)) === 1 && $liveName === 'index.html', $liveName);
$bk = htBackups($tmpRoot);
htShutdown($sp, 'admin');
check('the same request\'s shutdown judges by the saved name: no rewrite back to the old one', file_get_contents($htFile) === $new && htBackups($tmpRoot) === $bk);
htSave($sp, array(), array('staticFile'));
check('settings save back to the default name: the block of index.html again', file_get_contents($htFile) === $withBlock);
$h->using(null);
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//[step 4 handed over] Replace leaving a group conditioned on the request method only (the gate
//shape): still candidate, so the warning stops only by Keep, and comes back on an edit
list($gateFixture) = htHand($stock, '# PW-PAGENAME', array_merge($gate, $hand['the README\'s first rule'][0]));
htPut($htFile, $gateFixture);
$n0 = count($wire->notices);
htSave($sp, array('htaccessAction' => 'replace'));
$after = $h->status();
$texts = htNoticeTexts($wire, $n0);
$warned = array_values(array_filter($texts, fn($t) => strpos($t, 'NoticeWarning: ') !== false && strpos($t, '(lines 439-441)') !== false));
check('gate shape: Replace leaves the S4 group, the state stays candidate, and the save warns about lines 439-441 besides saying what was written', $after['state'] === 'candidate' && count($after['candidates']) === 1 && $after['candidates'][0]['signatures'] === array('S4') && count($warned) === 1 && strpos(implode(' ', $texts), 'request method only and were left in place') !== false, json_encode(array(htSummary($after), $texts)));
htForget($tmp);
htShutdown($sp, 'admin');
$pending = htPending($sp);
check('gate shape: the check after an admin request warns about it too', count($pending) === 1 && $pending[0]['type'] === 'warning' && strpos($pending[0]['text'], '(lines 439-441)') !== false, json_encode($pending));
htClearPending($sp);
htSave($sp, array('htaccessAction' => 'keep'));
htClearPending($sp);
htShutdown($sp, 'admin');
$keptPending = htPending($sp);
$replaced = (string) file_get_contents($htFile);
htPut($htFile, str_replace('RewriteRule ^ gate.php [L]', 'RewriteRule ^ gate.php [L,NC]', $replaced));
htShutdown($sp, 'admin');
$pending = htPending($sp);
check('gate shape: after Keep no warning; after the gate rule is edited, the warning again', !$keptPending && count($pending) === 1 && $pending[0]['type'] === 'warning', json_encode(array($keptPending, $pending)));
htSave($sp, array(), array('htaccessKeep'));
$h->using(null);
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//[decided by user, audit 1, A1.1] Replace chosen on the settings form when the site's own rule 19
//differs from stock: refused, the reason shown and logged, nothing written
htPut($htFile, $ownRule19['flags in another order']);
$listing = files($tmpRoot);
$n0 = count($wire->notices);
$log0 = logText($tmp);
$stored = htSave($sp, array('htaccessAction' => 'replace'));
$texts = htNoticeTexts($wire, $n0);
$logged = substr(logText($tmp), strlen($log0));
$warned = array_values(array_filter($texts, fn($t) => strpos($t, 'NoticeWarning: ') !== false && strpos($t, 'no active rule outside the StaticPages block would hand requests to index.php') !== false && strpos($t, 'line 484') !== false));
check('settings save with Replace, the site\'s own rule 19 written differently from stock: refused, the reason shown as a warning and logged; nothing written, no backup; htaccessAction not stored', count($warned) === 1 && strpos($logged, 'no active rule outside the StaticPages block would hand requests to index.php') !== false && file_get_contents($htFile) === $ownRule19['flags in another order'] && files($tmpRoot) === $listing && !array_key_exists('htaccessAction', $stored), json_encode(array($texts, $logged)));
htClean($tmpRoot);
htForget($tmp);
htClearPending($sp);

//a failing check is caught and logged, and the drain before it has run
$x = publish($sp, $A, array($D->id));
$copy = "$tmpRoot$Pdir/a/index.html";
$sp->queue()->enqueue(StaticPages::modeAll);
$log0 = logText($tmp);
$h->file = array('not a path');
htShutdown($sp, 'admin');
$h->file = '';
check('a failing .htaccess check is caught and logged, the queued drain ran', !is_file($copy) && strpos(substr(logText($tmp), strlen($log0)), 'htaccess check failed') !== false, substr(logText($tmp), strlen($log0), 300));
htForget($tmp);

//onUninstall() [decided by user, Q13 (a)]: the copies by the index, the block and the prefixes
//out; the tables stay for ___uninstall() to drop
htPut($htFile, $candidateFixture);
$h->write(false, true);
$replaced = (string) file_get_contents($htFile);
$x = publish($sp, $A, array($D->id));
$recorded = tagsOf($sp, "$Pdir/a/index.html");
$backups = count(htBackups($tmpRoot));
$out = htUninstall($sp);
$tables = $database->query("SHOW TABLES LIKE 'static\\_pages\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
check('onUninstall() after a Replace: the copy deleted by the index, the candidate fixture given back, one more backup holding the replaced file, the index tables still there', is_file($copy) === false && count($recorded) > 0 && $out['copies']['files'] >= 1 && file_get_contents($htFile) === $candidateFixture && count(htBackups($tmpRoot)) === $backups + 1 && $out['htaccess']['backup'] !== '' && file_get_contents("$tmpRoot/{$out['htaccess']['backup']}") === $replaced && count($tables) === 3, json_encode(array($out['copies'], $out['htaccess']['message'], $tables)));
htClean($tmpRoot);
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$out = htUninstall($sp);
check('onUninstall() with a candidate never replaced: the .htaccess unchanged, no backup', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && $out['htaccess']['done'] === 'nothing', $out['htaccess']['message']);
htClean($tmpRoot);
//[R1] without index tables the manifest's copies go; with neither, nothing is scanned for
$tablesAtStart = $tablesReady->getValue($sp->index());
$tablesReady->setValue($sp->index(), false);
@mkdir("$tmpRoot$Pdir/a", 0777, true);
file_put_contents($copy, 'listed in the manifest');
@unlink($sp->manifestPath());
$sp->publisher()->logStaticFile($copy);
$out = htUninstall($sp);
$byManifest = !is_file($copy) && $out['copies'] !== null && $out['copies']['files'] === 1;
@unlink($sp->manifestPath());
@mkdir("$tmpRoot$Pdir/a", 0777, true);
file_put_contents($copy, 'recorded nowhere');
$log0 = logText($tmp);
$out = htUninstall($sp);
$tablesReady->setValue($sp->index(), $tablesAtStart);
check('onUninstall() without index tables deletes what the manifest lists; with neither tables nor manifest it scans for nothing: an unrecorded copy stays', $byManifest && is_file($copy) && $out['copies'] === null && strpos(substr(logText($tmp), strlen($log0)), 'neither index tables nor a manifest') !== false);
rrmdir("$tmpRoot$Pdir");
$out = htUninstall($sp);
check('onUninstall() without a .htaccess: nothing created, the refusal logged', !file_exists($htFile) && $out['htaccess']['state'] === 'none' && strpos(logText($tmp), 'uninstall: htaccess: Nothing was changed: the state of the .htaccess is none') !== false);
//[audit N1.3] an uninstall forgets the stamp and what was told: a reinstall tells its outcome
//again, the warning of a candidate and the log line of none. Other files of the dir stay
htForget($tmp);
htPut($htFile, $candidateFixture);
$first = $h->onInstall();
$again = $h->onInstall();
$cacheDir = "$tmp/cache/StaticPages";
file_put_contents("$cacheDir/htaccess-decoy.seen", 'not a stamp');
file_put_contents("$cacheDir/decoy.reported", 'not the record');
$stampsBefore = htStamps($tmp);
$reportedBefore = is_file("$cacheDir/htaccess.reported");
htUninstall($sp);
clearstatcache();
$stampsAfter = htStamps($tmp);
$reportedAfter = is_file("$cacheDir/htaccess.reported");
$decoysStay = is_file("$cacheDir/htaccess-decoy.seen") && is_file("$cacheDir/decoy.reported");
$reinstall = $h->onInstall();
htClean($tmpRoot);
htForget($tmp);
$h->onInstall();
$log0 = logText($tmp);
$h->onInstall();
$noneAgain = htLogSince($tmp, $log0);
htUninstall($sp);
$log0 = logText($tmp);
$h->onInstall();
$noneReinstall = htLogSince($tmp, $log0);
@unlink("$cacheDir/htaccess-decoy.seen");
@unlink("$cacheDir/decoy.reported");
check('onUninstall() deletes the .htaccess stamp and htaccess.reported, nothing else: a reinstall warns about a candidate again and logs none again', count($first['notices']) === 1 && !$again['notices'] && count($stampsBefore) === 2 && $reportedBefore && $stampsAfter === array('htaccess-decoy.seen') && !$reportedAfter && $decoysStay && count($reinstall['notices']) === 1 && $reinstall['notices'][0][0] === 'warning' && !$noneAgain && count($noneReinstall) === 1 && strpos($noneReinstall[0], 'htaccess: none:') !== false, json_encode(array(count($first['notices']), count($again['notices']), $stampsBefore, $stampsAfter, $reportedAfter, $decoysStay, $reinstall['notices'], $noneAgain, $noneReinstall)));
htForget($tmp);

//[decided by user, step 6 second doubt (a2)] the uninstall flag before anything is changed, the
//block out before the copies go, nothing cached while it runs (the writer's check request
//included), no block written back by the check after the uninstalling request
check('___uninstall() takes the flag back when dropping the tables throws; ready() removes a stale flag on admin requests', strpos($uninstallBody, 'markUninstalling(false)') > strpos($uninstallBody, 'dropTables()') && strpos($uninstallBody, 'throw $e') !== false && preg_match('/function ready\(\) \{(.*?)\n\t\}/s', $moduleSource, $m) && strpos($m[1], 'clearStaleUninstalling()') !== false);
$uninstallRun = new \ReflectionProperty(StaticPages::class, 'uninstallRun');
$uninstallRun->setAccessible(true);
$copyD = $tmpRoot . $wire->pages->get($D->id)->path . 'index.html';
foreach(array('admin', 'cli') as $as) {
	htClean($tmpRoot);
	htForget($tmp);
	htPut($htFile, $withBlock);
	$baseline = files($tmpRoot);
	publish($sp, $A, array($D->id));
	$before = (string) @file_get_contents($copy);
	$seen = array();
	//the check request remove() makes from a web request: what it sees, and two renders while the
	//uninstall runs, one by a request that loaded the config after the flag (the check request
	//itself), one by a request that loaded it before
	$h->fetch = $as === 'cli' ? null : function($url) use ($sp, $A, $D, $copy, $copyD, $htFile, $uninstallRun, $guest, $superuser, &$seen) {
		clearstatcache();
		//the check request is a visitor's
		$sp->wire()->users->setCurrentUser($guest);
		$seen['flag'] = htFlagStored($sp);
		$seen['blockGone'] = strpos((string) file_get_contents($htFile), StaticPagesHtaccess::markerBegin) === false;
		$seen['copyStill'] = is_file($copy);
		publish($sp, $A, array(), '<html><body>check request</body></html>');
		$seen['after'] = (string) @file_get_contents($copy);
		$uninstallRun->setValue($sp, false);
		$flag = $sp->get('uninstalling');
		$sp->remove('uninstalling');
		publish($sp, $D, array(), '<html><body>loaded before the flag</body></html>');
		$seen['early'] = is_file($copyD);
		$seen['earlyRows'] = count(tagsOf($sp, substr($copyD, strlen($sp->rootPath()))));
		$uninstallRun->setValue($sp, true);
		$sp->set('uninstalling', $flag);
		$sp->wire()->users->setCurrentUser($superuser);
		return 200;
	};
	$log0 = logText($tmp);
	$out = htAs($sp, $as, fn() => $sp->htaccess()->onUninstall());
	$h->fetch = null;
	$flagAfter = htFlagStored($sp);
	$stillMarked = $sp->uninstalling();
	htShutdown($sp, $as);
	clearstatcache();
	$logged = substr(logText($tmp), strlen($log0));
	$ok = file_get_contents($htFile) === $stock && !is_file($copy) && !is_file($copyD) && preg_replace('~ /\.htaccess\.sp-[^ ]+\.bak~', '', files($tmpRoot)) === $baseline && !htStamps($tmp) && strpos($logged, 'block was added') === false && $out['copies']['files'] >= 1 && is_numeric($flagAfter) && $stillMarked;
	if($as === 'admin') $ok = $ok && is_numeric($seen['flag']) && $seen['blockGone'] && $seen['copyStill'] && $seen['after'] === $before && $seen['early'] === false && $seen['earlyRows'] === 0 && substr_count($logged, 'the module is being uninstalled') === 2;
	check("an uninstall from " . ($as === 'admin' ? 'a superuser\'s admin request' : 'a CLI run') . ': the flag stored first and kept in the module\'s row, the block out before the copies go, ' . ($as === 'admin' ? 'the check request and a render that loaded the config before the flag write nothing, ' : '') . 'the check after the request writes no block back and no stamp; no copy and no unrecorded file left', $ok, json_encode(array($seen, $out['copies'], $flagAfter, $stillMarked, files($tmpRoot), htStamps($tmp))));
	$sp->markUninstalling(false);
	publish($sp, $A, array($D->id));
	check("after an uninstall from " . ($as === 'admin' ? 'an admin request' : 'a CLI run') . ' that core would complete by deleting the module\'s row: the flag lives in that row only; taken back, it is gone and pages are cached again', htFlagStored($sp) === null && !$sp->uninstalling() && !$sp->uninstalling(true) && is_file($copy) && !$uninstallRun->getValue($sp) && !$h->uninstallRun);
	unpublishA($sp, $tmpRoot, $Pdir);
}
htClean($tmpRoot);
htForget($tmp);

//a flag left by an uninstall that died halfway counts for uninstallingMaxAge only, and an admin
//request removes it then
$modules->configs->saveConfig($sp, 'uninstalling', time());
$sp->set('uninstalling', time());
publish($sp, $A, array($D->id));
$fresh = array($sp->uninstalling(), $sp->uninstalling(true), is_file($copy), htAs($sp, 'admin', fn() => $sp->clearStaleUninstalling()), htFlagStored($sp) !== null);
$stale = time() - StaticPages::uninstallingMaxAge - 5;
$modules->configs->saveConfig($sp, 'uninstalling', $stale);
$sp->set('uninstalling', $stale);
$log0 = logText($tmp);
publish($sp, $A, array($D->id));
$old = array($sp->uninstalling(), $sp->uninstalling(true), is_file($copy));
$removed = htAs($sp, 'admin', fn() => $sp->clearStaleUninstalling());
check('an uninstall flag stops caching while fresh (and stays); once older than uninstallingMaxAge it counts no more, pages are cached again, and an admin request removes it with a log line', $fresh === array(true, true, false, false, true) && $old === array(false, false, true) && $removed === true && htFlagStored($sp) === null && $sp->get('uninstalling') === null && strpos(substr(logText($tmp), strlen($log0)), 'the flag of an uninstall that did not complete was removed') !== false, json_encode(array($fresh, $old, $removed)));
unpublishA($sp, $tmpRoot, $Pdir);

//without index tables (the manifest): a render that loaded its config before the flag was
//stored writes, finds the flag in the module's row, and takes its file back
$tablesAtStart = $tablesReady->getValue($sp->index());
$tablesReady->setValue($sp->index(), false);
@unlink($sp->manifestPath());
$modules->configs->saveConfig($sp, 'uninstalling', time());
$sp->remove('uninstalling');
$log0 = logText($tmp);
publish($sp, $A, array($D->id));
$manifestCase = array(is_file($copy), is_dir("$tmpRoot$Pdir/a"), substr_count(substr(logText($tmp), strlen($log0)), 'the module is being uninstalled'));
$sp->markUninstalling(false);
$tablesReady->setValue($sp->index(), $tablesAtStart);
@unlink($sp->manifestPath());
check('without index tables, a render that loaded the config before the uninstall flag writes nothing that stays: the file and its dir are taken back, the skip logged', $manifestCase === array(false, false, 1) && htFlagStored($sp) === null, json_encode($manifestCase));
htClearPending($sp);
$h->using(null);
check('the .htaccess manager checks leave no .htaccess, no backup and no temporary file on the temporary root', !file_exists($htFile) && !htBackups($tmpRoot) && !htTmp($tmpRoot));

////  config hooks

$data = $modules->getConfig('StaticPages');
$data['forceWipeOnModuleSave'] = 1;
$data['wipeDryRun'] = 1;
$modules->saveConfig('StaticPages', $data);
check('the force wipe boxes are not stored checked', empty($modules->getConfig('StaticPages', 'forceWipeOnModuleSave')) && empty($modules->getConfig('StaticPages', 'wipeDryRun')));

$hooked = null;
foreach($wire->hooks->getHooks($A, 'render') as $hook) if($hook['toObject'] instanceof StaticPages) $hooked = $hook['options']['priority'];
check('Page::render hook on the module object at its priority', $hooked !== null && (int) $hooked === (int) $sp->priority, json_encode($hooked));

////  config file and module info (0.8.1)

//core includes StaticPages.config.php whenever it builds module info; 0.8.0 asked for the module
//from there and recursed until memory ran out on a module install or a cleared info cache (its
//`refreshing` guard covered a refresh only). A recursion ends this script with a fatal error.
//Run under the install's own root and cache dir: core finds module files, StaticPages.config.php
//among them, through paths->root. Under the temporary root it found none, so the config file
//was never included here; it also took a root namespace module already in memory (a compiled
//SettingsFactory) for one in ProcessWire's, compiled it again under the temporary cache and
//died on "Cannot redeclare class". Nothing below writes under the root: a wipe these calls
//queue is drained later, on the temporary root. Without index tables a queued wipe would run
//at once, on the real root: the block is skipped then
if($sp->index()->ensureTables()) {
	realPaths(true);
	$modules->info->clearModuleInfoCache();
	$cleared = $modules->isInstalled('StaticPages');
	$probe = '';
	foreach(array('TextformatterNewlineBR', 'TextformatterNewlineUL', 'TextformatterPstripper', 'TextformatterStripTags') as $name) {
		if($modules->isInstallable($name) && !$modules->isInstalled($name)) { $probe = $name; break; }
	}
	if($probe) {
		$modules->install($probe);
		$installed = $modules->isInstalled($probe);
		$modules->uninstall($probe);
		$uninstalled = !$modules->isInstalled($probe);
	}
	realPaths(false);
	check('clearing the module info cache returns', $cleared);
	if($probe) {
		check("a module install and uninstall return ($probe)", $installed && $uninstalled);
	} else {
		echo "skip a module install and uninstall: no uninstalled core textformatter to try\n";
	}
} else {
	echo "skip clearing the module info cache, a module install and uninstall: no index tables, a wipe they queued would run on the install's own root\n";
}

//no stored nonStaticDirs reaches the module as '' (the config file's default): that is the
//default list, never "protect nothing", and the settings form offers that list
@mkdir("$tmpRoot/smoke-keep", 0777, true);
$defaults = $sp->wiper()->defaultNonStaticDirs();
$sp->set('nonStaticDirs', '');
check('an empty nonStaticDirs is the default list', $sp->nonStaticDirsArray() === $defaults && in_array('smoke-keep', $defaults, true), json_encode($sp->nonStaticDirsArray()));
$data = $modules->getConfig('StaticPages');
unset($data['nonStaticDirs']);
$modules->saveConfig('StaticPages', $data);
//core finds the module's config file through paths->root, which points at the temp dir here:
//the real root for the form, and the default list computed under that same root
$tmpRootPath = $config->paths->root;
$config->paths->root = "$root/";
$form = $modules->getModuleConfigInputfields('StaticPages');
$field = $form ? $form->getChildByName('nonStaticDirs') : null;
$formDefaults = implode("\n", $sp->wiper()->defaultNonStaticDirs());
$config->paths->root = $tmpRootPath;
check('the settings form offers the default list when none is stored', $field && $formDefaults !== '' && (string) $field->attr('value') === $formDefaults, $field ? json_encode((string) $field->attr('value')) : 'no field');
$field = $form ? $form->getChildByName('cacheNonHtml') : null;
$notes = $field ? (string) $field->notes : '';
check('the switch "Cache XML and plain text responses" is a checkbox whose notes name the template Content-Type setting and the Content-Type header', $field instanceof InputfieldCheckbox && strpos($notes, 'Content-Type setting') !== false && strpos($notes, 'Content-Type header') !== false, $notes);
$field = $form ? $form->getChildByName('rootFiles') : null;
$notes = $field ? (string) $field->notes : '';
check('"Replace these root files with pages" is an empty textarea whose notes name the dot backup .robots.txt and when it can be fetched', $field instanceof InputfieldTextarea && (string) $field->attr('value') === '' && strpos($notes, '.robots.txt') !== false && strpos($notes, 'can be fetched') !== false && strpos($notes, 'without the stock rule') !== false, $notes);

//the settings form groups its fields in fieldsets (0.8.2), the force wipe and the main switch
//ungrouped at the bottom. ProcessModule stores getAll(): wrappers are left out of it, so a
//fieldset name never reaches the stored config
$layout = array();
if($form) foreach($form->children() as $child) {
	$name = (string) $child->attr('name');
	if($child instanceof InputfieldFieldset) {
		$names = array();
		foreach($child->children() as $c) $names[] = (string) $c->attr('name');
		$layout[$name] = $names;
	} else if(strpos($name, '_') !== 0 && $child->className() !== 'InputfieldSubmit') {
		$layout[$name] = true;
	}
}
$layout = array_slice($layout, -8, null, true);
$expected = array(
	'_general' => array('priority'),
	'_static' => array('newDirPermissions', 'staticFile', 'cacheNonHtml', 'rootFiles'),
	'_exclusions' => array('nonStaticDirs', 'nonStaticTemplates', 'trackedPaths', 'noCacheCookies'),
	'_system' => array('csrfPatterns', 'useShell', 'wipeDryRun', 'logSkips'),
	'_wipe' => array('wipeOnConfigSaveModules', 'wipeOnSaveField', 'wipeAllTemplates', 'wipeAllFields', 'ignoreTemplates'),
	'htaccessAction' => true,
	'forceWipeOnModuleSave' => true,
	'isEnabled' => true,
);
check('the settings form groups fields in fieldsets, three ungrouped at the bottom', $layout === $expected, json_encode($layout));
$stored = array();
if($form) foreach($form->getAll() as $f) $stored[] = (string) $f->attr('name');
check('no fieldset name is among the fields a form save stores', $stored && !array_intersect($stored, array_keys(array_filter($expected, 'is_array'))), json_encode($stored));
$sp->set('nonStaticDirs', "site\nvendor");
rrmdir("$tmpRoot/smoke-keep");

////  cleanup

cleanup();
rrmdir($tmp);

echo "\n" . ($fails ? "$fails FAILED" : 'all passed') . "\n";
exit($fails ? 1 : 0);
