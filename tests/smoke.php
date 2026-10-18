<?php namespace ProcessWire;

/**
 * Smoke test: runs StaticPages against a bootstrapped ProcessWire install with the module
 * installed. Writes to the database (three fixture pages, the module's own tables, its config,
 * all restored or removed at the end) and to a temporary directory: the site root, the cache
 * dir and the log dir are pointed there right after the boot, so no file of the install is
 * written, wiped or logged to.
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

//everything on disk goes to a temporary dir from here on
//TEMP rather than sys_get_temp_dir(): a PHP build may keep its temp dir inside the sites tree
$tmpBase = getenv('STATICPAGES_SMOKE_TMP') ?: (getenv('TMPDIR') ?: (getenv('TEMP') ?: (getenv('TMP') ?: sys_get_temp_dir())));
$tmpReal = str_replace('\\', '/', (string) realpath($tmpBase));
if($tmpReal === '' || stripos($tmpReal, '/work/sites/') !== false || stripos($tmpReal, 'Z:/home') === 0) exit("Temp dir '$tmpBase' is missing or inside the sites tree: set STATICPAGES_SMOKE_TMP\n");
$tmp = rtrim($tmpReal, '/') . '/staticpages-smoke-' . getmypid();
foreach(array('root', 'cache', 'logs') as $dir) @mkdir("$tmp/$dir", 0777, true);
$config->paths->root = "$tmp/root/";
$config->paths->cache = "$tmp/cache/";
$config->paths->logs = "$tmp/logs/";
$tmpRoot = "$tmp/root";

/** @var StaticPages $sp */
$sp = $modules->get('StaticPages');
$configAtStart = $modules->getConfig('StaticPages');
$info = $modules->getModuleInfoVerbose('StaticPages');
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

////  fixture

$home = $pages->get('/');
$tpl = $wire->templates->get('default') ?: $home->template;
$fixtures = array();
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

////  config hooks

$data = $modules->getConfig('StaticPages');
$data['forceWipeOnModuleSave'] = 1;
$data['wipeDryRun'] = 1;
$modules->saveConfig('StaticPages', $data);
check('the force wipe boxes are not stored checked', empty($modules->getConfig('StaticPages', 'forceWipeOnModuleSave')) && empty($modules->getConfig('StaticPages', 'wipeDryRun')));

$hooked = null;
foreach($wire->hooks->getHooks($A, 'render') as $hook) if($hook['toObject'] instanceof StaticPages) $hooked = $hook['options']['priority'];
check('Page::render hook on the module object at its priority', $hooked !== null && (int) $hooked === (int) $sp->priority, json_encode($hooked));

////  cleanup

foreach(array_reverse($fixtures) as $f) if($f->id) $pages->delete($pages->get($f->id), true);
$sp->drain();
$modules->configs->saveConfig('StaticPages', $configAtStart);
//the restored config may say the migration is pending; this process ran it on the temp root
$sp->set('configVersion', StaticPages::configVersion);
rrmdir($tmp);

echo "\n" . ($fails ? "$fails FAILED" : 'all passed') . "\n";
exit($fails ? 1 : 0);
