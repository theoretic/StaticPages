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

//removes the fixture pages and puts the module config back. Called at the end, and from a
//shutdown function when a fatal error or an exit() ends the script before that: a PHP fatal
//still runs the shutdown functions, this one ahead of the module's drain (which the module
//registers from inside the shutdown sequence, so behind every function registered before)
$cleanedUp = false;
function cleanup() {
	global $wire, $sp, $fixtures, $configAtStart, $cleanedUp;
	if($cleanedUp) return;
	$cleanedUp = true;
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
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$log0 = logText($tmp);
$stored = htSave($sp, array('htaccessAction' => 'replace', 'wipeDryRun' => 1));
$logged = substr(logText($tmp), strlen($log0));
check('settings save with Replace and Dry run: nothing written, no backup; the log holds the new text line by line; neither box stored checked', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && strpos($logged, 'htaccess dry run: ') !== false && strpos($logged, ': # BEGIN StaticPages') !== false && strpos($logged, ': ' . $prefix . 'RewriteRule ^(.+)/$ /$1/index.html [L]') !== false && substr_count($logged, 'htaccess dry run: ') === count(explode("\n", $expected)) + 1 && empty($stored['wipeDryRun']) && !array_key_exists('htaccessAction', $stored), substr($logged, 0, 300));
htClean($tmpRoot);
htForget($tmp);

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
$out = $h->onUninstall();
$tables = $database->query("SHOW TABLES LIKE 'static\\_pages\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
check('onUninstall() after a Replace: the copy deleted by the index, the candidate fixture given back, one more backup holding the replaced file, the index tables still there', is_file($copy) === false && count($recorded) > 0 && $out['copies']['files'] >= 1 && file_get_contents($htFile) === $candidateFixture && count(htBackups($tmpRoot)) === $backups + 1 && $out['htaccess']['backup'] !== '' && file_get_contents("$tmpRoot/{$out['htaccess']['backup']}") === $replaced && count($tables) === 3, json_encode(array($out['copies'], $out['htaccess']['message'], $tables)));
htClean($tmpRoot);
htPut($htFile, $candidateFixture);
$listing = files($tmpRoot);
$out = $h->onUninstall();
check('onUninstall() with a candidate never replaced: the .htaccess unchanged, no backup', file_get_contents($htFile) === $candidateFixture && files($tmpRoot) === $listing && $out['htaccess']['done'] === 'nothing', $out['htaccess']['message']);
htClean($tmpRoot);
$out = $h->onUninstall();
check('onUninstall() without a .htaccess: nothing created, the refusal logged', !file_exists($htFile) && $out['htaccess']['state'] === 'none' && strpos(logText($tmp), 'uninstall: htaccess: Nothing was changed: the state of the .htaccess is none') !== false);
htForget($tmp);
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
	'_static' => array('newDirPermissions', 'staticFile'),
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
