<?php
/*
StaticPages: the root .htaccess
AT
17.11.26
*/

namespace ProcessWire;

/*
The rules that tell Apache to answer a request from a static copy, and (from 0.10.0 on) the
block of them kept in the site's root .htaccess. Three layers, each testable alone, and when
they run:

  the generator  settings in, text out: rules(), apacheBlock(). Reads the module's settings
                 and $config only, never the disk and never the database
  the reader     the file in, a state out, nothing written
  the writer     splices the one marked block in, after a backup
  the manager    when the writer runs: on install, after a superuser's admin request or a CLI
                 run (a stamp keeps that to three stat() calls while nothing changed), on a
                 settings save (the operator's Replace or Keep), on uninstall

The block goes immediately before the stock "# PW-PAGENAME" line: inside mod_rewrite, after
the access rules, ahead of the 16A condition. Anywhere later its rules would take the stock
conditions of rule 19 for themselves.

What the generated rules do, for a site at the web root or in a subdirectory alike (patterns
are matched against the path below the .htaccess dir, substitutions are relative, as in the
stock rule 19):
  - a GET or HEAD request without a query string and without the login cookie, for the home
    page or a directory holding a copy, gets the copy
  - any other request for an existing directory, with its trailing slash, goes to ProcessWire
    with the "it" value the stock rule 19 passes, unless the directory holds an index.php of
    its own (then it, and the home page with it, gets what the stock file answers) or the URL
    holds a character the stock 16A condition refuses (then it gets the stock 403)
  - everything else is left to the stock rules (a directory requested without its trailing
    slash gets mod_dir's 301 to the slashed form, as without the block)
A missing or outdated block may only cost speed, never correctness: without a rule a request
reaches PHP.
*/

class StaticPagesHtaccess extends Wire {

	//the first and the last line of the block, alone on their lines
	const markerBegin = '# BEGIN StaticPages';
	const markerEnd = '# END StaticPages';

	//what a value taken from a setting or $config must match before it is put into a rule
	const plainNamePattern = '/^[A-Za-z0-9_-]+$/';

	//the stock 16A condition's pattern, quotes included, as htaccess.txt (@htaccessVersion 301)
	//writes it: the URLs ProcessWire accepts
	const stockPageNameCond = '"^/~?[-_.a-zA-Z0-9/]*$"';

	//the stock line the block goes in front of, alone on its line (htaccess.txt :439)
	const pageNameMarker = '# PW-PAGENAME';

	//a line holding this says the file is ProcessWire's (htaccess.txt :2)
	const stockHeader = 'START PROCESSWIRE HTACCESS DIRECTIVES';

	//stock's own rules to index.php (htaccess.txt :361, :484, :490), whitespace collapsed: the
	//only ones S2 leaves alone, see ruleGroups()
	const stockToIndex = array(
		'RewriteRule ^(.*)$ index.php?it=/http404/ [L,QSA]',
		'RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]',
		'RewriteRule ^(.*)$ /index.php?it=$1 [L,QSA]',
		);

	//the stock rule 19 (htaccess.txt :484): the catch-all that hands every page URL to
	//ProcessWire, which Replace must leave one of, see catchAlls()
	const stockCatchAll = 'RewriteRule ^(.*)$ index.php?it=$1 [L,QSA]';

	//the states of the file, see status(), in the order they are tested
	const states = array('none', 'locked', 'damaged', 'unrecognised', 'candidate', 'absent', 'current', 'outdated');

	//what Replace puts at the start of a hand-written line, and remove() takes off again
	const replacedPrefix = '# StaticPages-replaced: ';

	//the stock line (htaccess.txt :170) Replace writes after a hand DirectoryIndex it prefixes
	const stockDirectoryIndex = 'DirectoryIndex index.php index.html index.htm';

	//backups kept, seconds to wait for the lock, seconds for the check request
	const keepBackups = 5;
	const lockWait = 10;
	const fetchTimeout = 5;

	//directives whose commented-out stock form counts as stock uncommented too, see stockKeysOf()
	const optionalDirectives = 'Rewrite[A-Za-z]+|Options|Header|DirectoryIndex|SetEnv[A-Za-z]*|LimitRequestBody|Deny|Allow|Order|Require|AddDefaultCharset';

	//every line of htaccess.txt of 3.0.274 (@htaccessVersion 301) as lineKey() gives it, plus its
	//commented-out directives uncommented: stockKeysOf() of tests/fixtures/htaccess-301.txt,
	//compared by the smoke test. What isStockLine() asks: a line Replace never prefixes. Kept
	//here, not read from the fixture, because a site's copy of the module need not ship tests/
	const stockLineKeys =
		'01abfc750a0c 02f5f966c125 04870a8f3aa6 04c00c85f796 055e3bd27716 05e8b985d06b 07a926d3a416 08a8df16706e ' .
		'0936d30f83de 09dbdfb6f6be 0a29d6dd2cf3 0a69f6ca9ea1 0a72fb574a76 0b44a0a5e545 0c01093d5d8e 0c10b82e760f ' .
		'0cb1c9503f27 0cdea7fbbff4 0d063b828c52 0df38a8de79e 0e1803db0be1 0ebc2541a3b6 11b685f90c9f 1249f50356f5 ' .
		'129efa6567be 15428f32b0b2 159d4c368695 16255cf35630 170ac7610a16 18a32bc23f84 18fa64aaadf3 18ff874746be ' .
		'199088965585 1a643f9262de 1a765dfbbc8a 1c0ba9111c7b 1c2863b9b90e 1c708df60cf7 1d3f11bde674 1e1b2bace272 ' .
		'2054390d5414 2063e5fb0840 210cf42d72c2 2487a9493d8e 248a7785fe49 258fe68324f8 261e24dd702b 26c87a375b16 ' .
		'277a2f3b7fe2 27c95da9505d 28e8f4a8f705 29849a3620a4 2a49420f5433 2a4b22731414 2b263bd14e97 2bf6de603135 ' .
		'2e43548f95e2 2f7b5eec872d 30ba00b34c10 32391cb66450 3259eb970990 32af50d25817 335cd46ce5f7 33df1eb087f9 ' .
		'3668290c8086 37cff0934868 396e8cebb2f0 3ac9f6bfe88b 3aeed8576dba 3af2cdfec37e 3b03a6044d47 3b72535ebae2 ' .
		'3b9f543e4fd8 3c3098805176 3cf91278e0a8 3de02dadff3a 3e4c52daa480 3ef56a36098b 3f32fb5e0482 40afe384a8aa ' .
		'40f1e7bb8573 41aa5831deb1 42b762e75ad9 42e1601885d7 4554e728874b 45bd4ac3ba33 47437c1ebd75 47e7a50edf20 ' .
		'488d50140508 4935e0a5ea1e 494857d35fcd 49ee93a5d73f 4a0593efbd34 4ab2780b2b85 4b37d1ac8d27 4d90a71b4349 ' .
		'4d975e6d7f60 4dabe7f63770 4ea3f6e85614 50d50536372b 518294ad08d7 53ecaeb957c1 544c9c1c3942 54c8b5310a8b ' .
		'55e9e0b6aece 579ba39f1adc 57ef3f1dd179 594f9c416a38 597b20acb614 59ef1431b38b 59f1ea40024b 5bb0ae5ea243 ' .
		'5c46061d12af 5cc8a02be988 5d48f5f41422 5da68097cb48 5de6c5fade16 5ea8ad1558cb 5fa02de72e9c 609023884abe ' .
		'60e10952a884 610c4e82fde8 616e2974a478 6183087a7aaa 6190aaedba9a 61cb8c36d193 627169d98bc2 635eaa511aff ' .
		'63d69bf887fb 645ecd385193 648af639ed4e 64abad78d06b 66f1b258769d 69622b536eff 6a756878164d 6acd055bc50f ' .
		'6ae2bbcbe874 6c02ea8dd5be 6c2fec133661 6c7b6967641e 6ca2a34e2f58 6d40aff5a94c 6d423d327e64 6daf27bcb999 ' .
		'6e36c7782572 6e840f9ca71f 709c70aeda69 71ccb8279eab 732f4066c708 7332d982d2e4 733c11dbaf40 755e044eaa41 ' .
		'75ba14acacde 75dda649d90b 75e84b7344cb 76c6d2825198 782d5678d1b0 783493117ebc 794ea4dab40a 7a783f7b68d6 ' .
		'7a9e10fd6da8 7c372c8e1bd4 7d68c5fa51e8 7e0248d1dde5 7e692d047b58 7f3761da6c44 81265b97d740 82728f08666e ' .
		'82806b6488b1 830d1d3be571 84a8de6628f7 853a45fb5511 85692ebae22e 85725a40d27b 85be96ad1427 86a5e0bae366 ' .
		'8bf04c06dd71 8c0bc9c9863e 8c0c8b5bce54 8c51f4727f58 8f4b90fbb2b9 8f4c5342126a 919084e387db 92be8330fade ' .
		'933346163c70 935f074fb875 9399067d7935 9612f04ee4cd 975132cb3911 9b01420d1357 9c4cfa6fc4a2 9f2438dc1309 ' .
		'a0eb8b2eb9c5 a196a9bcaf3f a1efb3cbd771 a3ca9deb6198 a4adbbde61be a5d7ab98c0c0 a6cb1f3486ce a80462458dca ' .
		'a9cb92b0e9ae aa45d2dfd6bc ab3a9a7827fa ab4bbb952b8c acaddc8d02d5 ad963fae7c3b ae32c7b13ab8 af14fecee2c5 ' .
		'b21e3b0fa111 b26b9802e38a b2ce9ff64494 b2ecc52de787 b3313744e749 b600851042da b60a8991d259 bb8a965736a4 ' .
		'bc18638e042a bccd1aa04b0e bd0d2bccacc2 bdd68ae3d344 be957757f47c bfad419d8da5 c08896a8ec1d c1407690eecb ' .
		'c14ce88fddca c170e2d5c11e c178a19482ea c187323d4822 c2eacac65387 c42fa6ff73bd c51a35c7cdd2 c69cf8128bcb ' .
		'c7d00da10e24 c7d0fd970fca c80b7f922f70 ca4c62cd739e caf64c6d6065 cb85616a99ef cc71503e902e cdfe09f629da ' .
		'ce68ceb19f81 cf96496fe813 cfaa055b6e8c d05e9b727c5f d3600c4ba1b0 d41d8cd98f00 d766815b70aa da65e30c134d ' .
		'db71cdd94c5b dbabccb80f31 dbc9fa605ae9 dc12af065020 deb8a1b2b223 ded61a14c215 e1c529128396 e23fdd874ff3 ' .
		'e2f405c25ef2 e2fec11f5285 e4b459e81144 e58bf6c4c83c e6b9fa045d22 e7723455f6fa e7c91313c7fc e90a4b59dad2 ' .
		'e94912dc3859 e9c76dd20d2d ea6dae5ed196 ea88e22daca3 ea8bf380bf84 ec4fef3ab7ee ed29f323fbd4 ee22ea06bc2a ' .
		'efb087dde877 f05d35e539e3 f0970d1915e3 f12d266ce61f f18fdec57b86 f2d9c7be80a3 f305c4d17de2 f45c93217b80 ' .
		'f573c9e40c7c f5e374bf2a23 f6d0eabb5355 f702576917cc f83a97499ce5 f89b1778005f f93209ae6da3 f94d88a333b4 ' .
		'f952e57fdd3f f953c534245c fa3e98db7c5a fa87c7ea50e7 fafef7940ed2 fb688888c464 fbfd63ab904c fd0383dfff12 ' .
		'fdfbd5b13e1a feb93476a898 feea1b78eec8 ff1526cb0a34 ff543a630fbc ff5b26f0e9a3 ffff2c7a9a68 ';

	/**
	 * The file status() reads; '' is the .htaccess in the site root. Set by the tests only: they
	 * build the settings form under the install's own root, where no fixture may go
	 *
	 * #pw-internal
	 *
	 * @var string
	 *
	 */
	public $file = '';

	/**
	 * Test seams of the writer: $fetch, a callable($url) giving the root URL's status code,
	 * asked in place of a WireHttp request (and then under CLI too); $reread, a callable(array
	 * $status, string &$bytes) giving the status the writer judges after its write (it may change
	 * the bytes read back, taken by reference)
	 *
	 * #pw-internal
	 *
	 * @var callable|null
	 *
	 */
	public $fetch = null;
	public $reread = null;

	//the settings a save is storing, see using(); null: the module's own properties
	protected $settings = null;

	//a settings save of this request was a dry run: the check after the response writes
	//nothing either, the next request's does [R4]
	protected $dryRunSave = false;

	//this request ran onUninstall(): the check after its response writes nothing (the block it
	//took out would otherwise come back as "absent"). Public for the tests, which go on after one
	public $uninstallRun = false;

	/** @var StaticPages */
	protected $sp;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	/**
	 * The rule list as data
	 *
	 * Every value taken from a setting or $config is validated first and left out, with a
	 * note, when it is not plain; regex metacharacters are escaped. The same settings give
	 * the same list: the block is compared byte for byte, so nothing that depends on the
	 * request, the disk or the database may go into it.
	 *
	 * @return array 'rules': a list of array('comment', 'conds' => array(array(test, pattern)),
	 *   'pattern', 'target', 'flags'); 'notes': why a value was left out
	 *
	 */
	public function rules() {
		$notes = array();

		//validated as staticFileName() validates: a plain name, or ''
		$name = $this->fileName();
		if( $name === '' ){
			$notes[] = 'The Static file name setting is not a plain file name: no rules are generated.';
			return array( 'rules' => array(), 'notes' => $notes );
		}
		$file = $this->escape($name);

		//when a copy may answer at all: what the cache itself asks of a request, see
		//StaticPages::isCacheableRequest()
		$serve = array(
			array( '%{REQUEST_METHOD}', '^(GET|HEAD)$' ),
			array( '%{QUERY_STRING}', '^$' ),
			);
		$cookies = $this->loginCookies($notes);
		if( count($cookies) ) $serve[] = array( '%{HTTP_COOKIE}', '!(^|;\s*)(' . implode( '|', array_map( array($this, 'escape'), $cookies ) ) . ')=' );

		$rules = array();

		//the home page: its dir holds ProcessWire's own index.php, which the stock
		//DirectoryIndex answers whenever this rule does not
		$rules[] = array(
			'comment' => 'The home page, from its copy.',
			'conds' => array_merge( $serve, array(
				array( '%{REQUEST_FILENAME}/' . $file, '-f' ),
				) ),
			'pattern' => '^$',
			'target' => $file,
			'flags' => '[L]',
			);

		//any other page: a dir holding an index.php of its own (an API endpoint, a tool) is
		//answered as the stock file answers it
		$rules[] = array(
			'comment' => 'A page, from its copy, unless its directory holds an index file of its own.',
			'conds' => array_merge( $serve, array(
				array( '%{REQUEST_FILENAME}/index.php', '!-f' ),
				array( '%{REQUEST_FILENAME}/' . $file, '-f' ),
				) ),
			'pattern' => '^(.+/)$',
			'target' => '$1' . $file,
			'flags' => '[L]',
			);

		//the stock rules answer an existing dir from disk: DirectoryIndex would serve the copy
		//to a POST, a query string and a logged in user, and Options -Indexes a 403 to a dir
		//holding no copy. The stock rule 19's substitution, so ProcessWire gets the same "it".
		//Only with the trailing slash: for a dir requested without it mod_dir answers the
		//rewritten request with a 301 to "<dir>/?it=<dir>" (seen in the probe of step 2); left
		//alone, the stock answer is mod_dir's plain 301 to "<dir>/", and that request then
		//meets the rules of this block.
		//Only for a URL the stock 16A condition lets through, the same line as stock :445: a
		//dir named outside ProcessWire's page name characters gets what the stock file gives it
		//(a 403), not ProcessWire [decided by user, step 2]. The stock condition rather than its
		//class in the pattern: it tests the whole REQUEST_URI, the path of a ProcessWire
		//installed in a subdirectory included, as 16A does. A site that switched to the UTF-8
		//alternative 16B keeps the stock answer for a dir with such a name
		$rules[] = array(
			'comment' => 'Any other request for an existing directory, with its trailing slash, a URL the stock 16A condition accepts and without an index file of its own, goes to ProcessWire.',
			'conds' => array(
				array( '%{REQUEST_URI}', self::stockPageNameCond ),
				array( '%{REQUEST_FILENAME}', '-d' ),
				array( '%{REQUEST_FILENAME}/index.php', '!-f' ),
				),
			'pattern' => '^(.+/)$',
			'target' => 'index.php?it=$1',
			'flags' => '[L,QSA]',
			);

		return array( 'rules' => $rules, 'notes' => $notes );
	}

	/**
	 * The block for Apache's .htaccess: the rule list as text, LF line ends, no final line end
	 *
	 * @return string
	 *
	 */
	public function apacheBlock() {
		$result = $this->rules();

		$lines = array(
			self::markerBegin,
			'# Generated by the StaticPages module and replaced on every update: change its settings, not these lines.',
			);
		if( !count($result['rules']) ) $lines[] = '# No rules: see the StaticPages settings.';

		foreach( $result['rules'] as $rule ){
			$lines[] = '# ' . $rule['comment'];
			foreach( $rule['conds'] as $cond ) $lines[] = 'RewriteCond ' . $cond[0] . ' ' . $cond[1];
			$lines[] = 'RewriteRule ' . $rule['pattern'] . ' ' . $rule['target'] . ' ' . $rule['flags'];
		}

		$lines[] = self::markerEnd;

		return implode( "\n", $lines );
	}

	/**
	 * The names of the cookies ProcessWire sets on login, or none
	 *
	 * The challenge cookie exists only while a user is logged in (Session::getCookieNames()).
	 * Both the plain and the secure name: which one is sent depends on the request, the block
	 * may not. Used only when $config->sessionChallenge is on and both names are plain; the
	 * plain session cookie itself is set for guests too and says nothing.
	 *
	 * @param array $notes Gets a line when the names are left out
	 * @return array
	 *
	 */
	public function loginCookies(array &$notes = array()) {
		$config = $this->wire()->config;

		if( !$config->sessionChallenge ){
			$notes[] = 'Login sessions have no challenge cookie ($config->sessionChallenge): copies are served to logged in users too.';
			return array();
		}

		$name = (string) $config->sessionName;
		$secure = (string) $config->sessionNameSecure;
		if( $secure === '' ) $secure = $name . 's';

		if( !preg_match( self::plainNamePattern, $name ) || !preg_match( self::plainNamePattern, $secure ) ){
			$notes[] = 'The session name ($config->sessionName or $config->sessionNameSecure) is not a plain name: copies are served to logged in users too.';
			return array();
		}

		return array_values( array_unique( array( $name . Session::challengeSuffix, $secure . Session::challengeSuffix ) ) );
	}

////  the reader: the file in, a state out, nothing written

	/**
	 * The path of the .htaccess this part reads
	 *
	 * @return string
	 *
	 */
	public function filePath() {
		return $this->file !== '' ? $this->file : $this->sp->rootPath() . '/.htaccess';
	}

	/**
	 * What the root .htaccess holds, and what a write would make of it
	 *
	 * Reads the file once and writes nothing, anywhere. The state is always derived from the
	 * file and the generated block, never from a stored flag.
	 *
	 * @return array See classify(); 'file' is the path read
	 *
	 */
	public function status() {
		list($result) = $this->read();
		return $result;
	}

	protected function read() {
		//array(status, the bytes classified or null): the writer works from the very bytes it
		//classified, read once
		$path = $this->filePath();

		//nothing is ever created where there is no file: nginx, IIS, Caddy, rules in the vhost
		if( !file_exists($path) && !is_link($path) )
			return array( $this->result( 'none', 'There is no .htaccess in the site root.', $path ), null );

		//a write goes to a temporary file in the same dir and is renamed over the original:
		//that needs the dir writable, and would replace a link by a plain file
		$why = '';
		if( is_link($path) ) $why = 'it is a symbolic link';
		else if( !is_file($path) ) $why = 'it is not a file';
		else if( !is_readable($path) ) $why = 'it cannot be read';
		else if( !is_writable($path) ) $why = 'it is not writable';
		else if( !is_writable( dirname($path) ) ) $why = 'its directory is not writable';
		$bytes = $why === '' ? @file_get_contents($path) : false;
		if( $why === '' && $bytes === false ) $why = 'it cannot be read';
		if( $why !== '' ) return array( $this->result( 'locked', "The .htaccess is left alone: $why.", $path ), null );

		$result = $this->classify($bytes);
		$result['file'] = $path;
		return array( $result, $bytes );
	}

	/**
	 * The state of .htaccess bytes, the candidates the heuristic finds in them, and the bytes a
	 * write would give the file
	 *
	 * A function of the bytes and the generated block alone: no file is read.
	 *
	 * States, tested in this order: damaged (the markers not exactly once each, in order),
	 * unrecognised (no ProcessWire header; or not exactly one "# PW-PAGENAME" line inside
	 * <IfModule mod_rewrite.c> while there is no block of ours to replace), candidate
	 * (hand-written StaticPages-like rules outside the block), absent, current, outdated.
	 * status() adds none and locked before these.
	 *
	 * @param string $bytes
	 * @return array 'state', 'reason' (English, for the log and the form), 'candidates' (see
	 *   ruleGroups()), 'routes' (root file routes, never candidates), 'related' (line => text:
	 *   the stock "!-d" condition of section 17 commented out, shown with candidates), 'block'
	 *   (the generated block), 'notes' (the generator's), 'eol', 'blockLines' (array(first,
	 *   last) or null), 'pageNameLine', 'text' (the bytes a write would give the file: for
	 *   absent, outdated and current; null otherwise)
	 *
	 */
	public function classify($bytes) {
		$bytes = (string) $bytes;
		$lines = $this->lines($bytes);
		$result = $this->result( '', '', '' );
		$result['eol'] = $this->lineEnd($bytes);

		//our markers: exactly one pair, in order, or none at all
		$begins = $ends = array();
		foreach( $lines as $n => $line ){
			$t = trim($line['text']);
			if( $t === self::markerBegin ) $begins[] = $n;
			else if( $t === self::markerEnd ) $ends[] = $n;
		}
		if( count($begins) || count($ends) ){
			if( count($begins) > 1 || count($ends) > 1 ) $why = 'the StaticPages block is there more than once';
			else if( !count($ends) ) $why = 'a "' . self::markerBegin . '" line has no "' . self::markerEnd . '" line';
			else if( !count($begins) ) $why = 'an "' . self::markerEnd . '" line has no "' . self::markerBegin . '" line';
			else if( $ends[0] < $begins[0] ) $why = 'the "' . self::markerEnd . '" line comes before the "' . self::markerBegin . '" line';
			else $why = '';
			if( $why !== '' ) return $this->state( $result, 'damaged', "The .htaccess is damaged: $why (lines " . implode( ', ', array_merge($begins, $ends) ) . ').' );
			$result['blockLines'] = array( $begins[0], $ends[0] );
		}

		//ProcessWire's file: its header, and the one place the block goes
		$header = false;
		$pageName = array();
		$open = array();
		foreach( $lines as $n => $line ){
			$t = trim($line['text']);
			if( strpos( $t, self::stockHeader ) !== false ) $header = true;
			if( $t === self::pageNameMarker ) $pageName[$n] = in_array( 'rewrite', $open, true );
			if( preg_match( '#^<IfModule\s+(!?)\s*([^\s>]+)\s*>#i', $t, $m ) ) $open[] = $m[1] === '' && preg_match( '#^(mod_rewrite(\.c)?|rewrite_module)$#i', $m[2] ) ? 'rewrite' : 'other';
			else if( preg_match( '#^</IfModule\s*>#i', $t ) ) array_pop($open);
		}
		$anchor = count($pageName) === 1 && reset($pageName);
		if( !$header ) return $this->state( $result, 'unrecognised', 'The .htaccess has no "' . self::stockHeader . '" line: it is not recognised as ProcessWire\'s.' );
		if( !$anchor && !$result['blockLines'] ){
			$why = !count($pageName) ? 'it has no "' . self::pageNameMarker . '" line' : ( count($pageName) > 1 ? 'it has ' . count($pageName) . ' "' . self::pageNameMarker . '" lines' : 'its "' . self::pageNameMarker . '" line is outside <IfModule mod_rewrite.c>' );
			return $this->state( $result, 'unrecognised', "The .htaccess is not recognised: $why, the place the StaticPages block goes." );
		}
		if( $anchor ) $result['pageNameLine'] = key($pageName);

		//the heuristic: hand-written rules outside our block
		$groups = $this->ruleGroups( $lines, $result['blockLines'] );
		$result['candidates'] = $groups['candidates'];
		$result['routes'] = $groups['routes'];
		if( $result['pageNameLine'] ) foreach( $lines as $n => $line ){
			if( $n > $result['pageNameLine'] && preg_match( '#^\#\s*RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-d\s*$#i', trim($line['text']) ) ) $result['related'][$n] = $line['text'];
		}
		if( count($result['candidates']) ){
			$at = array();
			foreach( $result['candidates'] as $group ) $at[] = $group['start'] === $group['end'] ? $group['start'] : $group['start'] . '-' . $group['end'];
			return $this->state( $result, 'candidate', 'The .htaccess holds hand-written rules that look like StaticPages rules (lines ' . implode( ', ', $at ) . ').' );
		}

		if( !$result['blockLines'] ){
			$result['text'] = $this->applyEdits( $bytes, array( $this->blockEdit( $lines, $result ) ) );
			return $this->state( $result, 'absent', 'The .htaccess has no StaticPages block yet.' );
		}

		if( $this->blockIsCurrent( $lines, $result ) ){
			$result['text'] = $bytes;
			return $this->state( $result, 'current', 'The StaticPages block in the .htaccess is up to date.' );
		}

		$result['text'] = $this->applyEdits( $bytes, array( $this->blockEdit( $lines, $result ) ) );
		return $this->state( $result, 'outdated', 'The StaticPages block in the .htaccess differs from the one the current settings generate.' );
	}

	/**
	 * The edit that puts the generated block in: array(offset, length, text)
	 *
	 * Without a block, it goes immediately before the "# PW-PAGENAME" line: inside mod_rewrite,
	 * after the access rules, ahead of the 16A condition, which belongs to the stock rule 19.
	 * With one, its lines are replaced, the last one without its line end: no other byte changes.
	 * The block takes the file's own line ends.
	 *
	 * @param array $lines From lines()
	 * @param array $result From classify(): 'blockLines', 'pageNameLine', 'eol', 'block'
	 * @return array
	 *
	 */
	protected function blockEdit(array $lines, array $result) {
		$eol = $result['eol'] ? $result['eol'] : "\n";
		$block = str_replace( "\n", $eol, $result['block'] );
		if( !$result['blockLines'] ) return array( $lines[ $result['pageNameLine'] ]['offset'], 0, $block . $eol );
		list($first, $last) = $result['blockLines'];
		$from = $lines[$first]['offset'];
		$to = $lines[$last]['offset'] + strlen( $lines[$last]['text'] );
		return array( $from, $to - $from, $block );
	}

	protected function blockIsCurrent(array $lines, array $result) {
		//the block's lines equal the generated block, line ends aside
		if( !$result['blockLines'] ) return false;
		list($first, $last) = $result['blockLines'];
		$current = array();
		for( $n = $first; $n <= $last; $n++ ) $current[] = $lines[$n]['text'];
		return implode( "\n", $current ) === $result['block'];
	}

	/**
	 * $bytes with edits applied, each array(offset, length, text) on the original offsets; edits
	 * must not overlap
	 *
	 * @param string $bytes
	 * @param array $edits
	 * @return string
	 *
	 */
	public function applyEdits($bytes, array $edits) {
		usort( $edits, function($a, $b) { return $b[0] - $a[0]; } );
		foreach( $edits as $edit ) $bytes = substr_replace( $bytes, $edit[2], $edit[0], $edit[1] );
		return $bytes;
	}

	/**
	 * Rule groups outside the block, and which of them are StaticPages-like
	 *
	 * A rule group is a run of RewriteCond lines and the RewriteRule that ends it, comments and
	 * blank lines between them included: mod_rewrite gives every condition to the next rule,
	 * whatever stands between. A run the file ends (or our block interrupts) before its rule
	 * is a group as well. Lines that are comments never count. A group is a candidate when one
	 * of its lines carries a signature:
	 *   S1  the static file name, literal or regex-escaped, in a RewriteCond test string or
	 *       pattern, or in a RewriteRule pattern or substitution
	 *   S2  a RewriteRule to index.php (with or without "?it=…") that is none of stock's own
	 *       (stockToIndex: sections 14, 19, 20), or whose group tests -d without negation, or
	 *       whose pattern is ^$ (or ^/?$): a directory, the home page, or anything a hand rule
	 *       chose, handed to ProcessWire beside the stock rule [decided by user, step 3]
	 *   S3  a DirectoryIndex line whose first entry is the static file name (a group of its own)
	 *   S4  a RewriteCond testing %{REQUEST_METHOD}: copies are served to GET and HEAD only, so a
	 *       hand rule conditioned on the method is one that answers a guest's request in place of
	 *       ProcessWire (a gate script sending stored copies, say), whatever it names [decided by
	 *       user, step 3]. Stock's only such condition, the commented section 11E method block,
	 *       is a candidate too once uncommented: a false positive costs a warning
	 * Not a candidate by itself: a directory without its trailing slash redirected (-d, R=301 to
	 * "$1/") [decided by user, step 3].
	 * Never a candidate: a root file route, a RewriteRule whose pattern is one file name
	 * (^sitemap\.xml$) and whose substitution starts with "index.php?it=".
	 *
	 * Which way it fails: a false positive costs a warning and a choice; a miss lets the block in
	 * next to rules it did not see. Hence S1 is a plain substring, case-insensitive: "%1index.html"
	 * names the file as much as "/index.html" does.
	 *
	 * @param array $lines From lines()
	 * @param array|null $block array(first, last) line of our block, skipped
	 * @return array 'candidates': list of array('start', 'end' (line numbers, from 1), 'lines'
	 *   (line => text, the whole span), 'signatures' (list of S1 to S4), 'comments' (line =>
	 *   text: comment lines just above the group that name StaticPages)); 'routes': list of
	 *   array('line', 'name', 'target', 'text')
	 *
	 */
	public function ruleGroups(array $lines, $block = null) {
		$name = $this->fileName();
		$candidates = array();
		$routes = array();
		$run = null;
		$last = 0;

		$close = function($end) use (&$run, &$candidates, $lines) {
			if( $run === null ) return;
			$group = $run;
			$run = null;
			if( !count($group['signatures']) ) return;
			$candidates[] = $this->candidate( $lines, $group['start'], $end, $group['signatures'] );
		};

		foreach( $lines as $n => $line ){
			if( $block && $n >= $block[0] && $n <= $block[1] ){
				//a hand-written run cut by our block: what it had so far
				$close($last);
				continue;
			}
			$directive = $this->directive( $line['text'] );
			if( $directive === null ) continue;
			list($word, $args) = $directive;
			$a0 = isset($args[0]) ? $args[0] : '';
			$a1 = isset($args[1]) ? $args[1] : '';

			if( $word === 'directoryindex' ){
				if( $name !== '' && strcasecmp( $a0, $name ) === 0 ) $candidates[] = $this->candidate( $lines, $n, $n, array('S3') );
				$last = $n;
				continue;
			}

			if( $word !== 'rewritecond' && $word !== 'rewriterule' ){
				//any other directive leaves a run open: mod_rewrite still hands its
				//conditions to the next rule
				$last = $n;
				continue;
			}

			if( $run === null ) $run = array( 'start' => $n, 'signatures' => array(), 'dir' => false );
			if( $this->namesFile($a0, $name) || $this->namesFile($a1, $name) ) $run['signatures'][] = 'S1';
			$last = $n;

			if( $word === 'rewritecond' ){
				if( $a1 === '-d' ) $run['dir'] = true;
				if( strcasecmp( $a0, '%{REQUEST_METHOD}' ) === 0 ) $run['signatures'][] = 'S4';
				continue;
			}

			//the rule ends the group
			if( $this->isRootFileRoute($a0, $a1, $routeName) ){
				$routes[] = array( 'line' => $n, 'name' => $routeName, 'target' => $a1, 'text' => $line['text'] );
				$run = null;
				continue;
			}
			if( preg_match( '#(^|/)index\.php(\?it=.*)?$#i', $a1 ) && ( $run['dir'] || $a0 === '^$' || $a0 === '^/?$' || !in_array( preg_replace( '/\s+/', ' ', trim($line['text']) ), self::stockToIndex, true ) ) ) $run['signatures'][] = 'S2';
			$close($n);
		}
		$close($last);

		usort( $candidates, function($a, $b) { return $a['start'] - $b['start']; } );

		return array( 'candidates' => $candidates, 'routes' => $routes );
	}

	/**
	 * The lines of $bytes, numbered from 1: array('text' (without its line end), 'offset' (of the
	 * text's first byte))
	 *
	 * Split on LF alone, byte by byte: a CR before it is dropped from the text, nothing else is
	 * read as a line end (the stock file holds UTF-8 letters on its 16B line).
	 *
	 * @param string $bytes
	 * @return array
	 *
	 */
	public function lines($bytes) {
		$lines = array();
		$offset = 0;
		foreach( explode( "\n", (string) $bytes ) as $raw ){
			$text = substr($raw, -1) === "\r" ? substr($raw, 0, -1) : $raw;
			//a byte order mark is no part of the first line's text
			$skip = !count($lines) && strpos($text, "\xEF\xBB\xBF") === 0 ? 3 : 0;
			$lines[ count($lines) + 1 ] = array( 'text' => (string) substr($text, $skip), 'offset' => $offset + $skip );
			$offset += strlen($raw) + 1;
		}
		return $lines;
	}

	/**
	 * The file's own line end, from its first line break: "\r\n" or "\n"
	 *
	 * @param string $bytes
	 * @return string
	 *
	 */
	public function lineEnd($bytes) {
		$bytes = (string) $bytes;
		$at = strpos( $bytes, "\n" );
		return $at !== false && $at > 0 && $bytes[$at - 1] === "\r" ? "\r\n" : "\n";
	}

	/**
	 * The arguments of a directive as mod_rewrite reads them (parseargline()): a quoted argument
	 * runs to its closing quote, a blank ends an argument unless a backslash comes before it. The
	 * quotes are taken off, backslashes stay
	 *
	 * @param string $text
	 * @return array
	 *
	 */
	public function args($text) {
		$text = (string) $text;
		$args = array();
		$i = 0;
		$length = strlen($text);
		while( $i < $length ){
			while( $i < $length && ctype_space($text[$i]) ) $i++;
			if( $i >= $length ) break;
			$quote = ( $text[$i] === '"' || $text[$i] === "'" ) ? $text[$i++] : '';
			$arg = '';
			while( $i < $length ){
				$c = $text[$i];
				if( $quote !== '' ? $c === $quote : ctype_space($c) ) break;
				if( $c === '\\' && $i + 1 < $length && ctype_space( $text[$i + 1] ) ){
					$arg .= $c . $text[$i + 1];
					$i += 2;
					continue;
				}
				$arg .= $c;
				$i++;
			}
			if( $quote !== '' && $i < $length ) $i++;
			$args[] = $arg;
		}
		return $args;
	}

	protected function directive($text) {
		//array(lower-cased directive name, arguments), or null for a comment or a blank line
		$t = trim( (string) $text );
		if( $t === '' || $t[0] === '#' ) return null;
		if( !preg_match( '/^(\S+)(.*)$/s', $t, $m ) ) return null;
		return array( strtolower($m[1]), $this->args($m[2]) );
	}

	protected function namesFile($arg, $name) {
		//the name as written, or with mod_rewrite's escapes taken off (index\.html, my\-cache\.htm)
		if( $name === '' || $arg === '' ) return false;
		return stripos( $arg, $name ) !== false || stripos( preg_replace( '/\\\\(.)/s', '$1', $arg ), $name ) !== false;
	}

	protected function isRootFileRoute($pattern, $target, &$name = null) {
		//exactly one file name, ^sitemap\.xml$, handed to ProcessWire: what a site that keeps its
		//own rules adds by hand for a root file
		if( strpos( $target, 'index.php?it=' ) !== 0 ) return false;
		if( !preg_match( '#^\^/?((?:[A-Za-z0-9_-]|\\\\?\.)+)\$$#', $pattern, $m ) ) return false;
		$name = str_replace( '\\.', '.', $m[1] );
		return strpos( $name, '.' ) !== false;
	}

	protected function candidate(array $lines, $start, $end, array $signatures) {
		$span = array();
		for( $n = $start; $n <= $end; $n++ ) $span[$n] = $lines[$n]['text'];

		//the comment lines right above the group that name StaticPages: shown with it, never a
		//signature by themselves
		$comments = array();
		for( $n = $start - 1; $n > 0 && $n >= $start - 20; $n-- ){
			$t = trim( $lines[$n]['text'] );
			if( $t === '' ) continue;
			if( $t[0] !== '#' ) break;
			if( stripos( $t, 'StaticPages' ) !== false ) $comments[$n] = $lines[$n]['text'];
		}
		ksort($comments);

		$signatures = array_values( array_unique($signatures) );
		sort($signatures);

		return array(
			'start' => $start,
			'end' => $end,
			'lines' => $span,
			'signatures' => $signatures,
			'comments' => $comments,
			);
	}

	protected function result($state, $reason, $file) {
		$generated = $this->rules();
		return array(
			'state' => $state,
			'reason' => $reason,
			'file' => $file,
			'candidates' => array(),
			'routes' => array(),
			'related' => array(),
			'block' => $this->apacheBlock(),
			'notes' => $generated['notes'],
			'eol' => null,
			'blockLines' => null,
			'pageNameLine' => null,
			'text' => null,
			);
	}

	protected function state(array $result, $state, $reason) {
		$result['state'] = $state;
		$result['reason'] = $reason;
		return $result;
	}

////  the writer: the block spliced in, hand rules prefixed on Replace, always after a backup

	/**
	 * Write the generated block into the root .htaccess
	 *
	 * Writes from the states absent and outdated, and from candidate on the operator's Replace;
	 * current needs nothing; none, locked, damaged and unrecognised are refused. On Replace every
	 * hand-written line of a candidate group (S1, S2) gets the prefix "# StaticPages-replaced: "
	 * and nothing is deleted. A line the stock htaccess.txt also holds is never prefixed (the 16A
	 * condition stays, so it joins the next rule again), and neither is a group that is a
	 * candidate only by the request method (S4): the block must then go below it, else Replace is
	 * refused [decided by user, step 4]. A hand DirectoryIndex line naming the copy first (S3) is
	 * prefixed and the stock DirectoryIndex line written right after it [decided by user, step 4].
	 * Refused as well: a rule written like a stock line (so kept) whose hand conditions would be
	 * prefixed, and any write that would leave an active RewriteCond right above the block,
	 * where it would join the block's first rule [decided by user, step 4]; and a Replace after
	 * which no active catch-all to index.php (the stock rule 19, see catchAlls()) would be left
	 * outside the block, as when the site's own rule 19 differs from the stock text and is an S2
	 * candidate [decided by user, audit 1, A1.1].
	 *
	 * Steps: the lock; read and refuse unless the state allows it; a dry run stops here; a backup
	 * (.htaccess.sp-<YmdHis>.bak, the five newest kept); the new bytes to a temporary file in the
	 * same dir, renamed over the file; read again, and put the backup back unless it is what was
	 * meant; from a web request (or with $fetch set), one request for the site's root URL, and
	 * the backup back on a 5xx; one log line.
	 *
	 * @param bool $dryRun Only say what would be written
	 * @param bool $replace The operator chose Replace for the hand-written rules found
	 * @return array status() plus 'ok', 'done' (refused, nothing, dry run, written, restored),
	 *   'message', 'messages' (what the operator must know besides), 'text', 'backup',
	 *   'prefixed' (line numbers), 'directoryIndex' (line numbers), 'kept' (S4 groups left,
	 *   "first-last"), 'regrouped' (stock conditions that join a hand rule from now on), 'fetch'
	 *   (the root URL's status code, 0 for no answer, null when not asked), 'restored', 'busy'
	 *   (the lock was not taken: nothing was read for the decision, try again later)
	 *
	 */
	public function write($dryRun = false, $replace = false) {
		return $this->change( $replace ? 'replace' : 'write', $dryRun );
	}

	/**
	 * Take the block and the "# StaticPages-replaced: " prefixes out of the root .htaccess again,
	 * with the stock DirectoryIndex line a Replace added
	 *
	 * Gives back, byte for byte, what the file held before the block came in, hand rules
	 * included. The same steps as write().
	 *
	 * @param bool $dryRun
	 * @return array See write()
	 *
	 */
	public function remove($dryRun = false) {
		return $this->change( 'remove', $dryRun );
	}

	protected function change($mode, $dryRun) {
		$index = $this->sp->index();
		$lock = $index->lockName('htaccess');
		try {
			$locked = $index->getLock( $lock, self::lockWait );
		}
		catch( \Throwable $e ){
			$locked = false;
		}
		if( !$locked ){
			$result = $this->outcome( $this->status(), $mode, $dryRun );
			$result['busy'] = true;
			$result['message'] = 'The .htaccess lock could not be taken (another request is changing the file, or the database is not there): nothing was written.';
			return $result;
		}
		try {
			return $this->changeLocked( $mode, $dryRun );
		}
		finally {
			try {
				$index->releaseLock($lock);
			}
			catch( \Throwable $e ){
			}
		}
	}

	protected function changeLocked($mode, $dryRun) {
		list($status, $bytes) = $this->read();
		$result = $this->outcome( $status, $mode, $dryRun );

		$plan = $mode === 'remove' ? $this->planRemove( $status, $bytes ) : $this->planWrite( $status, $bytes, $mode === 'replace' );
		foreach( $plan['info'] as $key => $value ) $result[$key] = $value;
		$result['messages'] = $plan['messages'];
		if( $plan['refuse'] !== '' ){
			$result['message'] = $plan['refuse'];
			return $result;
		}
		$text = $plan['text'];
		$result['text'] = $text;

		if( $text === $bytes ){
			$result['ok'] = true;
			$result['done'] = 'nothing';
			$result['message'] = $mode === 'remove' ? 'The .htaccess holds no StaticPages block and no prefixed line: nothing to do.' : 'The StaticPages block in the .htaccess is up to date: nothing to do.';
			return $result;
		}
		if( $dryRun ){
			$result['ok'] = true;
			$result['done'] = 'dry run';
			$result['message'] = 'Dry run: the .htaccess would get the text shown, nothing was written.';
			return $result;
		}

		$path = $status['file'];
		$backup = $this->backup( $path, $bytes );
		if( $backup === '' ){
			$result['message'] = 'No backup of the .htaccess could be made: nothing was written.';
			return $result;
		}
		$result['backup'] = basename($backup);
		$this->pruneBackups($path);

		if( !$this->replaceFile( $path, $text ) ){
			$result['message'] = 'The new .htaccess could not be written: the file is unchanged, the backup ' . $result['backup'] . ' was made.';
			$this->sp->logDrain( "htaccess: could not write $path, unchanged" );
			return $result;
		}

		//read again: the file must be what was meant
		list($after, $afterBytes) = $this->read();
		if( is_callable($this->reread) ){
			$reread = $this->reread;
			$after = $reread( $after, $afterBytes );
		}
		$why = $this->verify( $mode, $after, $afterBytes, $text );
		if( $why === '' ){
			$code = $this->loopback();
			$result['fetch'] = $code;
			if( $code >= 500 && $code <= 599 ) $why = "the site's root URL answered $code";
			else if( $code === 0 ) $this->sp->logDrain( "htaccess: the check request for the site's root URL got no answer, the new $path stays" );
		}
		if( $why !== '' ){
			$back = $this->replaceFile( $path, $bytes ) && @file_get_contents($path) === $bytes;
			$result['restored'] = $back;
			$result['done'] = 'restored';
			$result['message'] = $back
				? "The new .htaccess was not kept: $why. The backup " . $result['backup'] . ' was put back.'
				: "The new .htaccess was not kept: $why. The backup " . $result['backup'] . ' could NOT be put back: restore it by hand.';
			$this->sp->logDrain( "htaccess: $path written and " . ( $back ? 'put back from ' : 'NOT put back from ' ) . $result['backup'] . ": $why" );
			return $result;
		}

		$what = array(
			'write' => $status['state'] === 'outdated' ? 'the StaticPages block was rewritten' : 'the StaticPages block was added',
			'replace' => count($result['prefixed']) . ' hand-written lines were commented out with the prefix "' . trim(self::replacedPrefix) . '"' . ( count($result['directoryIndex']) ? ', the stock DirectoryIndex line added after ' . count($result['directoryIndex']) . ' hand-written one' . ( count($result['directoryIndex']) > 1 ? 's' : '' ) : '' ) . ' and the StaticPages block written',
			'remove' => 'the StaticPages block and the prefixes were taken out',
			);
		$result['ok'] = true;
		$result['done'] = 'written';
		$result['message'] = ucfirst( $what[$mode] ) . '; the backup is ' . $result['backup'] . '.';
		$this->sp->logDrain( "htaccess: $path: " . $what[$mode] . ', backup ' . $result['backup'] . ( count($result['messages']) ? '. ' . implode( ' ', $result['messages'] ) : '' ) );
		return $result;
	}

	protected function outcome(array $status, $mode, $dryRun) {
		//what change() returns: the status read, and what came of it
		return array_merge( $status, array(
			'mode' => $mode,
			'dryRun' => (bool) $dryRun,
			'ok' => false,
			'done' => 'refused',
			'message' => '',
			'messages' => array(),
			'backup' => '',
			'prefixed' => array(),
			'directoryIndex' => array(),
			'kept' => array(),
			'regrouped' => array(),
			'fetch' => null,
			'restored' => false,
			'busy' => false,
			) );
	}

	/**
	 * What write() would give the file: array('refuse' => why or '', 'text', 'info', 'messages')
	 *
	 * @param array $status
	 * @param string|null $bytes
	 * @param bool $replace
	 * @return array
	 *
	 */
	protected function planWrite(array $status, $bytes, $replace) {
		$plan = array( 'refuse' => '', 'text' => null, 'info' => array(), 'messages' => array() );
		$state = $status['state'];

		if( !in_array( $state, array('candidate', 'absent', 'current', 'outdated'), true ) ){
			$plan['refuse'] = "Nothing was written: the state of the .htaccess is $state. " . $status['reason'];
			return $plan;
		}
		if( $state === 'current' ){
			$plan['text'] = $bytes;
			return $plan;
		}
		$lines = $this->lines($bytes);
		$edits = array();

		if( $state === 'candidate' ){
			if( !$replace ){
				$plan['refuse'] = 'Nothing was written: the .htaccess holds hand-written rules that look like StaticPages rules. Choose Replace or Keep for them first.';
				return $plan;
			}
			$at = $status['blockLines'] ? $status['blockLines'][0] : $status['pageNameLine'];
			$eol = $status['eol'];
			$prefixed = array();
			$kept = array();
			$directoryIndex = array();

			foreach( $status['candidates'] as $group ){
				$span = $group['start'] === $group['end'] ? (string) $group['start'] : $group['start'] . '-' . $group['end'];

				//by the request method only (the shape of a gate script that sends stored
				//copies): never commented out, and the block goes below it
				if( $group['signatures'] === array('S4') ){
					if( $group['end'] >= $at ){
						$plan['refuse'] = "Nothing was written: the hand-written rules on lines $span are conditioned on the request method only, which Replace leaves in place, and they lie below the place the StaticPages block goes (line $at), where the block would answer before them. Move them above that line, or choose Keep.";
						return $plan;
					}
					$kept[] = $span;
					continue;
				}

				//a hand DirectoryIndex naming the copy first: prefixed, and the stock line
				//written right after it, else Apache's default would never offer index.php
				if( $group['signatures'] === array('S3') ){
					$n = $group['start'];
					$text = $lines[$n]['text'];
					if( $this->isStockLine($text) || strpos( $text, self::replacedPrefix ) === 0 ) continue;
					preg_match( '/^[ \t]*/', $text, $indent );
					$edits[] = array( $lines[$n]['offset'], 0, self::replacedPrefix );
					$edits[] = array( $lines[$n]['offset'] + strlen($text), 0, $eol . $indent[0] . self::stockDirectoryIndex );
					$directoryIndex[] = $n;
					continue;
				}

				$conds = array();
				foreach( $group['lines'] as $n => $text ){
					if( $this->isStockLine($text) || strpos( $text, self::replacedPrefix ) === 0 ) continue;
					$edits[] = array( $lines[$n]['offset'], 0, self::replacedPrefix );
					$prefixed[$n] = true;
					$directive = $this->directive($text);
					if( $directive && $directive[0] === 'rewritecond' ) $conds[] = $n;
				}

				//the group's rule written like a stock line stays, so it would lose the hand
				//conditions above it and apply to requests they kept from it: refused [decided
				//by user, step 4]
				$end = $group['end'];
				$rule = $this->directive( $lines[$end]['text'] );
				if( count($conds) && $rule && $rule[0] === 'rewriterule' && !isset($prefixed[$end]) ){
					$plan['refuse'] = 'Nothing was written: the rule on line ' . $end . ' is written like a line of the stock .htaccess, which Replace never comments out, but the condition' . ( count($conds) > 1 ? 's' : '' ) . ' on line' . ( count($conds) > 1 ? 's ' : ' ' ) . implode( ', ', $conds ) . ' above it would be: the rule would then apply to requests ' . ( count($conds) > 1 ? 'they' : 'it' ) . ' kept from it. Change these lines by hand, or choose Keep.';
					return $plan;
				}
			}

			//a stock condition kept in a group now joins the next rule left standing: rule 19 in
			//the stock layout, a hand rule where the site put one in between [decided by user,
			//step 4: Replace goes ahead and says so]
			$regrouped = array();
			foreach( $status['candidates'] as $group ){
				if( $group['signatures'] === array('S4') || $group['signatures'] === array('S3') ) continue;
				foreach( $group['lines'] as $n => $text ){
					if( isset($prefixed[$n]) ) continue;
					$directive = $this->directive($text);
					if( !$directive || $directive[0] !== 'rewritecond' ) continue;
					$rule = null;
					for( $m = $n + 1; $m <= count($lines); $m++ ){
						if( isset($prefixed[$m]) ) continue;
						if( $status['blockLines'] && $m >= $status['blockLines'][0] && $m <= $status['blockLines'][1] ) continue;
						$d = $this->directive( $lines[$m]['text'] );
						if( $d && $d[0] === 'rewriterule' ){
							$rule = $m;
							break;
						}
					}
					if( $rule !== null && $this->isStockLine( $lines[$rule]['text'] ) ) continue;
					$regrouped[] = array( 'line' => $n, 'text' => $text, 'ruleLine' => $rule, 'rule' => $rule === null ? '' : $lines[$rule]['text'] );
					$plan['messages'][] = $rule === null
						? "The stock condition on line $n stays and is followed by no rule."
						: "The stock condition on line $n stays and from now on belongs to the hand-written rule on line $rule, not to a stock rule: a rule the heuristic does not count was written between it and the stock rule 19.";
				}
			}

			$plan['info'] = array( 'prefixed' => array_keys($prefixed), 'kept' => $kept, 'directoryIndex' => $directoryIndex, 'regrouped' => $regrouped );
			if( count($kept) ) $plan['messages'][] = 'The hand-written rules on lines ' . implode( ', ', $kept ) . ' are conditioned on the request method only and were left in place.';
			if( count($directoryIndex) ) $plan['messages'][] = 'The hand-written DirectoryIndex line on line ' . implode( ', ', $directoryIndex ) . ' was commented out, and the stock line "' . self::stockDirectoryIndex . '" added after it.';
		}

		$edits[] = $this->blockEdit( $lines, $status );
		$text = $this->applyEdits( $bytes, $edits );

		//the site's own catch-all to ProcessWire (rule 19) written differently from stock is an S2
		//candidate: without an active one left, every page URL would reach ProcessWire only as
		//the 404 error document, so Replace is refused [decided by user, audit 1, A1.1]
		if( $state === 'candidate' && !count( $this->catchAlls($text) ) ){
			$lost = array();
			foreach( $plan['info']['prefixed'] as $n ){
				$directive = $this->directive( $lines[$n]['text'] );
				if( $directive && $directive[0] === 'rewriterule' && isset($directive[1][1]) && $this->isCatchAll( $directive[1][0], $directive[1][1] ) ) $lost[] = $n;
			}
			$plan['refuse'] = 'Nothing was written: after Replace no active rule outside the StaticPages block would hand requests to index.php, as the stock rule "' . self::stockCatchAll . '" does' . ( count($lost) ? ': the rule' . ( count($lost) > 1 ? 's' : '' ) . ' on line' . ( count($lost) > 1 ? 's ' : ' ' ) . implode( ', ', $lost ) . ', written differently from the stock line, would be commented out' : '' ) . '. ProcessWire would then get its pages only as the 404 error document. ' . ( count($lost) ? 'Write that rule like the stock line by hand' : 'Add such a rule by hand' ) . ', or choose Keep.';
			$plan['info'] = array();
			$plan['messages'] = array();
			return $plan;
		}

		//a condition left standing right above the block would join the block's first rule
		$dangling = $this->conditionAboveBlock($text);
		if( $dangling ){
			$plan['refuse'] = "Nothing was written: the RewriteCond on line $dangling would stand right above the StaticPages block, with no rule between them, and so join the block's first rule.";
			return $plan;
		}

		$plan['text'] = $text;
		return $plan;
	}

	protected function planRemove(array $status, $bytes) {
		$plan = array( 'refuse' => '', 'text' => null, 'info' => array(), 'messages' => array() );
		$state = $status['state'];
		if( !in_array( $state, array('candidate', 'absent', 'current', 'outdated'), true ) ){
			$plan['refuse'] = "Nothing was changed: the state of the .htaccess is $state. " . $status['reason'];
			return $plan;
		}

		$lines = $this->lines($bytes);
		$edits = array();
		$block = $status['blockLines'];
		if( $block ){
			//the block and the line end write() gave it
			list($first, $last) = $block;
			$from = $lines[$first]['offset'];
			$to = isset( $lines[$last + 1] ) ? $lines[$last + 1]['offset'] : strlen($bytes);
			$edits[] = array( $from, $to - $from, '' );
		}

		$prefixed = array();
		$directoryIndex = array();
		$skip = 0;
		foreach( $lines as $n => $line ){
			if( $n === $skip ) continue;
			if( $block && $n >= $block[0] && $n <= $block[1] ) continue;
			if( strpos( $line['text'], self::replacedPrefix ) !== 0 ) continue;
			$edits[] = array( $line['offset'], strlen(self::replacedPrefix), '' );
			$prefixed[] = $n;
			//the stock DirectoryIndex line a Replace wrote right after a hand one
			$rest = substr( $line['text'], strlen(self::replacedPrefix) );
			if( preg_match( '/^\s*DirectoryIndex\s/i', $rest ) && isset( $lines[$n + 1] ) && trim( $lines[$n + 1]['text'] ) === self::stockDirectoryIndex ){
				$from = $line['offset'] + strlen( $line['text'] );
				$to = $lines[$n + 1]['offset'] + strlen( $lines[$n + 1]['text'] );
				$edits[] = array( $from, $to - $from, '' );
				$directoryIndex[] = $n + 1;
				$skip = $n + 1;
			}
		}

		$plan['info'] = array( 'prefixed' => $prefixed, 'directoryIndex' => $directoryIndex );
		$plan['text'] = $this->applyEdits( $bytes, $edits );
		return $plan;
	}

	protected function verify($mode, array $after, $afterBytes, $text) {
		//'' when the file read back is what was meant
		if( $afterBytes !== $text ) return 'the file read back does not hold the bytes written';
		$lines = $this->lines($afterBytes);
		if( $mode === 'remove' ){
			//no block, not even one of its marker lines, and no prefixed line
			foreach( $lines as $line ){
				$t = trim( $line['text'] );
				if( $t === self::markerBegin || $t === self::markerEnd ) return 'a StaticPages marker line is left';
				if( strpos( $line['text'], self::replacedPrefix ) === 0 ) return 'a prefixed line is left';
			}
			return '';
		}
		if( $after['state'] === 'current' ) return '';
		//on Replace, groups conditioned on the request method only stay candidates
		if( $mode === 'replace' && $after['state'] === 'candidate' && $this->blockIsCurrent( $lines, $after ) ){
			foreach( $after['candidates'] as $group ) if( $group['signatures'] !== array('S4') ) return 'hand-written rules are left on lines ' . $group['start'] . '-' . $group['end'];
			return '';
		}
		return 'the file read back is ' . $after['state'] . ', not current';
	}

	protected function conditionAboveBlock($text) {
		//the line of an active RewriteCond that no RewriteRule stands between and the block's
		//first line, or 0
		$lines = $this->lines($text);
		$begin = 0;
		foreach( $lines as $n => $line ) if( trim( $line['text'] ) === self::markerBegin ){
			$begin = $n;
			break;
		}
		for( $n = $begin - 1; $n > 0; $n-- ){
			$t = trim( $lines[$n]['text'] );
			if( preg_match( '#^<IfModule\s+(mod_rewrite(\.c)?|rewrite_module)\s*>#i', $t ) ) return 0;
			$directive = $this->directive($t);
			if( !$directive ) continue;
			if( $directive[0] === 'rewriterule' ) return 0;
			if( $directive[0] === 'rewritecond' ) return $n;
		}
		return 0;
	}

	/**
	 * The lines of the active catch-alls to ProcessWire in $text, outside the StaticPages block
	 *
	 * A catch-all is a RewriteRule with the pattern ^(.*)$ and a substitution ending in
	 * "index.php?it=$1", with or without a path before it (stock rule 19, rule 20, a subdirectory
	 * install's "/pw/index.php?it=$1"), whatever its flags and conditions. Section 14's
	 * "?it=/http404/" and a home rule to index.php are none.
	 *
	 * #pw-internal
	 *
	 * @param string $text
	 * @return array Line numbers, from 1
	 *
	 */
	public function catchAlls($text) {
		$found = array();
		$inBlock = false;
		foreach( $this->lines($text) as $n => $line ){
			$t = trim( $line['text'] );
			if( $t === self::markerBegin ) $inBlock = true;
			else if( $t === self::markerEnd ) $inBlock = false;
			if( $inBlock || $t === self::markerEnd ) continue;
			$directive = $this->directive($t);
			if( !$directive || $directive[0] !== 'rewriterule' || !isset($directive[1][1]) ) continue;
			if( $this->isCatchAll( $directive[1][0], $directive[1][1] ) ) $found[] = $n;
		}
		return $found;
	}

	protected function isCatchAll($pattern, $target) {
		return $pattern === '^(.*)$' && preg_match( '#^(\S*/)?index\.php\?it=\$1$#i', $target ) === 1;
	}

	protected function backup($path, $bytes) {
		//<file>.sp-<YmdHis>.bak next to the file, never over an existing one: a counter for a
		//second backup within the same second. '' when none could be made
		$stamp = date('YmdHis');
		for( $n = 1; $n <= 99; $n++ ){
			$backup = $path . '.sp-' . $stamp . ( $n > 1 ? "-$n" : '' ) . '.bak';
			if( file_exists($backup) || is_link($backup) ) continue;
			$handle = @fopen( $backup, 'xb' );
			if( !$handle ) return '';
			$ok = @fwrite( $handle, $bytes ) === strlen($bytes);
			@fclose($handle);
			if( $ok ) return $backup;
			@unlink($backup);
			return '';
		}
		return '';
	}

	/**
	 * The backups of the file, newest first: name => array(YmdHis, counter)
	 *
	 * #pw-internal
	 *
	 * @param string $path
	 * @return array
	 *
	 */
	public function backups($path = '') {
		if( $path === '' ) $path = $this->filePath();
		$dir = dirname($path);
		$pattern = '/^' . preg_quote( basename($path) . '.sp-', '/' ) . '(\d{14})(?:-(\d{1,2}))?\.bak$/';
		$found = array();
		foreach( (array) @scandir($dir) as $name ){
			if( !preg_match( $pattern, (string) $name, $m ) ) continue;
			if( is_link("$dir/$name") || !is_file("$dir/$name") ) continue;
			$found[$name] = array( $m[1], isset($m[2]) ? (int) $m[2] : 1 );
		}
		uasort( $found, function($a, $b) { return $a[0] === $b[0] ? $b[1] - $a[1] : strcmp( $b[0], $a[0] ); } );
		return $found;
	}

	protected function pruneBackups($path) {
		//only names of the backup pattern, plain files, next to the file
		$dir = dirname($path);
		foreach( array_slice( array_keys( $this->backups($path) ), self::keepBackups ) as $name ) @unlink("$dir/$name");
	}

	protected function replaceFile($path, $bytes) {
		//a temporary file in the same dir with the original's permissions, renamed over it
		$tmp = $path . '.sp-' . getmypid() . '-' . bin2hex( random_bytes(4) ) . '.tmp';
		try {
			if( @file_put_contents( $tmp, $bytes ) !== strlen($bytes) ) return false;
			$perms = @fileperms($path);
			if( $perms !== false ) @chmod( $tmp, $perms & 0777 );
			return @rename( $tmp, $path );
		}
		finally {
			clearstatcache();
			if( file_exists($tmp) ) @unlink($tmp);
		}
	}

	protected function loopback() {
		//the status code of the site's root URL, 0 for no answer, null when not asked: from a
		//web request only, or through the injected $fetch
		$fetch = $this->fetch;
		if( $fetch === null ){
			if( PHP_SAPI === 'cli' ) return null;
			$fetch = function($url) {
				$http = $this->wire( new WireHttp() );
				$http->setTimeout( self::fetchTimeout );
				return $http->status($url);
			};
		}
		$url = (string) $this->wire()->config->urls->httpRoot;
		try {
			return (int) call_user_func( $fetch, $url );
		}
		catch( \Throwable $e ){
			return 0;
		}
	}

	/**
	 * Whether a line is one the stock htaccess.txt also holds
	 *
	 * Compared trimmed, blanks collapsed, case ignored; a commented-out stock directive counts
	 * uncommented as well (an optional section switched on). See stockLineKeys.
	 *
	 * #pw-internal
	 *
	 * @param string $text
	 * @return bool
	 *
	 */
	public function isStockLine($text) {
		static $keys = null;
		if( $keys === null ) $keys = array_flip( preg_split( '/\s+/', trim(self::stockLineKeys) ) );
		return isset( $keys[ $this->lineKey($text) ] );
	}

	/** #pw-internal */
	public function lineKey($text) {
		return substr( md5( strtolower( preg_replace( '/[ \t]+/', ' ', trim( (string) $text ) ) ) ), 0, 12 );
	}

	/**
	 * The keys of every line of a stock .htaccess text, sorted: what stockLineKeys holds for
	 * htaccess.txt of 3.0.274 (the smoke test compares them)
	 *
	 * #pw-internal
	 *
	 * @param string $stock
	 * @return array
	 *
	 */
	public function stockKeysOf($stock) {
		$keys = array();
		foreach( $this->lines($stock) as $line ){
			$keys[ $this->lineKey( $line['text'] ) ] = true;
			if( preg_match( '/^\s*#+\s*(' . self::optionalDirectives . ')\s(.*)$/i', $line['text'], $m ) ) $keys[ $this->lineKey( $m[1] . ' ' . $m[2] ) ] = true;
		}
		//keys of digits only came back as integers from array_keys()
		$keys = array_map( 'strval', array_keys($keys) );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/** #pw-internal */
	public function escape($value) {
		//for a regex; in a test string or a substitution mod_rewrite reads a backslash as
		//"the next character as it is", so the same text works there
		return preg_quote( (string) $value );
	}

////  when the manager runs: install, after an admin or CLI request, a settings save, uninstall

	/**
	 * Read the settings from an array instead of the module's properties
	 *
	 * Modules::saveConfig() does not update the loaded module: a settings save hands the array it
	 * stores here, and the part keeps reading it for the rest of the request, so the check after
	 * the response sees what was saved, not the values the request started with. null goes back
	 * to the module's properties.
	 *
	 * #pw-internal
	 *
	 * @param array|null $data
	 * @return $this
	 *
	 */
	public function using(?array $data = null) {
		$this->settings = $data;
		return $this;
	}

	protected function fileName() {
		//what the generator and the heuristic call the static file
		if( $this->settings === null ) return $this->sp->staticFileName();
		return $this->sp->plainFileName( isset($this->settings['staticFile']) ? $this->settings['staticFile'] : 'index.html' );
	}

	protected function keep() {
		//the hash Keep stored for the candidates, see keepHash()
		$keep = $this->settings === null ? $this->sp->get('htaccessKeep') : ( isset($this->settings['htaccessKeep']) ? $this->settings['htaccessKeep'] : '' );
		return is_string($keep) ? $keep : '';
	}

	/**
	 * What Keep stores for the candidates of a status: a hash of their text, line numbers left out
	 * (lines moved by an edit elsewhere in the file are still the rules kept); '' for none
	 *
	 * #pw-internal
	 *
	 * @param array $status
	 * @return string
	 *
	 */
	public function keepHash(array $status) {
		if( empty($status['candidates']) ) return '';
		$texts = array();
		foreach( $status['candidates'] as $group ) $texts[] = implode( "\n", $group['lines'] );
		return md5( implode( "\n\n", $texts ) );
	}

	/**
	 * The stamp the lazy check compares: a file whose name is the hash of what the state of the
	 * file depends on, so that comparing it costs three stat() calls (the .htaccess, this part's
	 * file, the stamp) and no read. Inputs: the file's path, its mtime and size (or its absence),
	 * this part's mtime (a module update), the generated block (every setting the generator
	 * reads), the static file name (the heuristic), the hash Keep stored
	 *
	 * #pw-internal
	 *
	 * @return string
	 *
	 */
	public function stampPath() {
		clearstatcache();
		$path = $this->filePath();
		$stat = @stat($path);
		$inputs = array(
			$path,
			$stat ? $stat['mtime'] . ':' . $stat['size'] : 'none',
			(int) @filemtime(__FILE__),
			$this->apacheBlock(),
			$this->fileName(),
			$this->keep(),
			);
		return $this->wire()->config->paths->cache . 'StaticPages/htaccess-' . md5( serialize($inputs) ) . '.seen';
	}

	/**
	 * The lazy check, run by StaticPages::shutdownDrain() after a superuser's admin request and
	 * in a CLI run, never after a visitor's request
	 *
	 * With the stamp unchanged nothing is read, nor after a settings save of this request that was
	 * a dry run (the next request's check writes). Otherwise, the response finished first: absent
	 * and outdated get the block [decided by user; Q3], a candidate a warning, once per change of
	 * the candidates and never for the ones Keep was chosen for, the other states one log line
	 * when first seen. The notices are kept in the module config until a superuser's next admin
	 * page shows them [decided by user, step 5 doubt (a)].
	 *
	 * #pw-internal
	 *
	 * @return array|null null when the stamp was unchanged, else see act()
	 *
	 */
	public function check() {
		if( $this->dryRunSave ) return null;
		//this request uninstalled the module: the block it took out stays out
		if( $this->uninstallRun ) return null;
		if( is_file( $this->stampPath() ) ) return null;
		$this->sp->queue()->finishResponse();
		return $this->act( '', false, true );
	}

	/**
	 * When the module is installed: what the lazy check does, whatever the stamp says
	 *
	 * #pw-internal
	 *
	 * @return array See act()
	 *
	 */
	public function onInstall() {
		return $this->act( '', false, false );
	}

	/**
	 * After a save of the module's whole config, with the array stored
	 *
	 * The operator's choice for hand-written rules ($action 'replace' or 'keep', a one shot value
	 * never stored) is acted on here; without one the save does what the lazy check does (an
	 * outdated block is rewritten: a changed Static file name changes the block). With $dryRun
	 * nothing is written and the new text goes to the static-pages log. Keep's hash was stored by
	 * the save itself, see StaticPages::hookBeforeSaveConfig().
	 *
	 * #pw-internal
	 *
	 * @param array $data The config being stored
	 * @param string $action '', 'replace' or 'keep'
	 * @param bool $dryRun
	 * @return array See act()
	 *
	 */
	public function onSave(array $data, $action = '', $dryRun = false) {
		$this->using($data);
		if( $dryRun ) $this->dryRunSave = true;
		return $this->act( $action, $dryRun, false );
	}

	/**
	 * Before the tables are dropped: the uninstall flag stored, the block and the prefixes of a
	 * Replace taken out, then the static copies deleted by what recorded them [decided by user,
	 * Q13 (a), R1; step 6 second doubt (a2)]
	 *
	 * The flag (StaticPages::markUninstalling()) keeps every request from caching from then on,
	 * and the check after this request's response from writing the block back. The copies go by
	 * the index tables, or by the manifest where there are none; never by a scan (wipeCaches()
	 * would fall back to one). Each half is caught and logged, so the uninstall completes. The
	 * root file originals come back here too once there are root files (step 10b).
	 *
	 * #pw-internal
	 *
	 * @return array 'copies' (the wipe's counts, or null), 'htaccess' (remove()'s result, or null)
	 *
	 */
	public function onUninstall() {
		$out = array( 'copies' => null, 'htaccess' => null );
		$sp = $this->sp;

		//first the flag: from now on no request caches, the check request remove() makes among
		//them, and renders already running are refused by the generation [decided by user, step 6
		//second doubt (a2)]. This request's own check after the response writes nothing
		$this->uninstallRun = true;
		$sp->markUninstalling(true);
		$sp->queue()->bumpGen();

		//the block out before the copies go: once the copies are gone the old rules hand every
		//request to PHP, and a render then has nothing left to be refused by but the flag
		try {
			$out['htaccess'] = $result = $this->remove();
			//a written remove() logs its own line
			if( $result['done'] !== 'written' ) $sp->logDrain( 'uninstall: htaccess: ' . $result['message'] );
		}
		catch( \Throwable $e ){
			$sp->logDrain( 'uninstall: htaccess: ' . $e->getMessage() );
		}

		try {
			$wiper = $sp->wiper();
			if( $sp->index()->ensureTables() ) $out['copies'] = $wiper->wipeIndexed(null);
			else if( is_file( $sp->manifestPath() ) ) $out['copies'] = $wiper->wipeCachesManifest();
			if( $out['copies'] !== null ){
				$sp->queue()->bumpGen();
				$sp->logDrain( 'uninstall: ' . $wiper->describeWipe( $out['copies'] ) );
			}
			else $sp->logDrain( 'uninstall: neither index tables nor a manifest, no static copy deleted' );
		}
		catch( \Throwable $e ){
			$sp->logDrain( 'uninstall: the static copies could not be deleted: ' . $e->getMessage() );
		}

		return $out;
	}

	/**
	 * Read the file, act, tell, stamp
	 *
	 * @param string $action '', 'replace' or 'keep'
	 * @param bool $dryRun
	 * @param bool $later The notices are kept for a superuser's next admin page (after the
	 *   response, or in a CLI run), else added to this request's notices
	 * @return array 'state' (as read first), 'result' (write()'s, or null), 'notices' (list of
	 *   array('type', 'text')), 'stamped' (bool)
	 *
	 */
	protected function act($action, $dryRun, $later) {
		$status = $this->status();
		$state = $status['state'];
		$result = null;
		$notices = array();
		$log = array();
		//automatic outcomes nobody asked for are told once per change, see the reported key
		$once = $action === '';

		if( $action === 'keep' ){
			if( $state === 'candidate' ){
				$notices[] = array( 'message', sprintf( $this->_('StaticPages: the hand-written rules in the root .htaccess (lines %s) are kept. Nothing was written, and there is no warning about them again until they change.'), $this->spans($status) ) );
				$log[] = 'htaccess: Keep chosen for the hand-written rules on lines ' . $this->spans($status) . ', nothing written';
			}
			else $notices[] = array( 'message', sprintf( $this->_('StaticPages: the root .htaccess holds no hand-written rules to keep (state %s). Nothing was written.'), $state ) );
		}
		else if( $action === 'replace' || $state === 'absent' || $state === 'outdated' ){
			$result = $this->write( $dryRun, $action === 'replace' );
			$messages = trim( $result['message'] . ' ' . implode( ' ', $result['messages'] ) );
			if( $result['done'] === 'written' ) $notices[] = array( 'message', 'StaticPages, root .htaccess: ' . $messages );
			else if( $result['done'] === 'dry run' ){
				$notices[] = array( 'message', 'StaticPages, root .htaccess: ' . $messages . ' ' . $this->_('The new text is in the static-pages log.') );
				$log[] = 'htaccess dry run: ' . $messages;
				foreach( $this->lines( (string) $result['text'] ) as $n => $line ) $log[] = "htaccess dry run: $n: " . $line['text'];
			}
			else if( $result['done'] !== 'nothing' ){
				//refused (the writer logs nothing then) or restored (it logged)
				$notices[] = array( 'warning', 'StaticPages, root .htaccess: ' . $messages );
				if( $result['done'] === 'refused' ) $log[] = 'htaccess: ' . $messages;
			}
		}
		else if( $state !== 'current' && $state !== 'candidate' ){
			//none, locked, damaged, unrecognised: shown on the settings form
			$log[] = 'htaccess: ' . $state . ': ' . $status['reason'];
		}

		//hand-written rules found, or left by a Replace (groups that depend on the request method
		//only): a warning, until Keep is chosen for them
		$final = $result && $result['done'] === 'written' ? $this->status() : $status;
		$keepHash = $this->keepHash($final);
		if( $action !== 'keep' && $final['state'] === 'candidate' && $keepHash !== $this->keep() ){
			$notices[] = array( 'warning', sprintf(
				$this->_('StaticPages: the root .htaccess holds hand-written rules that look like StaticPages rules (lines %1$s). Nothing was written for them. Choose Replace or Keep under "Hand-written rules found" in the module settings: %2$s'),
				$this->spans($final),
				$this->wire()->config->urls->admin . 'module/edit?name=' . $this->sp->className()
				) );
			$log[] = 'htaccess: hand-written rules that look like StaticPages rules on lines ' . $this->spans($final) . ', nothing written for them until Replace or Keep is chosen in the settings';
		}

		//what was told, so that an unchanged outcome is not told again when only the stamp moved
		//(the file touched, a setting saved that the block does not depend on)
		$reported = $this->wire()->config->paths->cache . 'StaticPages/htaccess.reported';
		$key = md5( implode( "\n", array( $state, $result ? $result['done'] . ' ' . $result['message'] : '', $keepHash, $this->keep() ) ) );
		if( $once && ( $result === null || $result['done'] === 'refused' ) && (string) @file_get_contents($reported) === $key ){
			$notices = array();
			$log = array();
		}

		foreach( $log as $line ) $this->sp->logDrain($line);
		$this->tell( $notices, $later );

		//a dry run wrote nothing, so the next check does it; a lock held by another request is
		//tried again. Otherwise the stamp, from the stats after whatever happened: a refused or a
		//restored write is not tried again until something changes
		$stamped = false;
		if( !$dryRun && !( $result && $result['busy'] ) && $this->sp->trackedHashDir() !== '' ){
			foreach( (array) glob( $this->wire()->config->paths->cache . 'StaticPages/htaccess-*.seen' ) as $old ) if( is_string($old) && is_file($old) ) @unlink($old);
			$stamped = @touch( $this->stampPath() );
			@file_put_contents( $reported, $key, LOCK_EX );
		}

		return array( 'state' => $state, 'result' => $result, 'notices' => $notices, 'stamped' => $stamped );
	}

	protected function spans(array $status) {
		//"440-443, 444-446"
		$at = array();
		foreach( $status['candidates'] as $group ) $at[] = $group['start'] === $group['end'] ? (string) $group['start'] : $group['start'] . '-' . $group['end'];
		return implode( ', ', $at );
	}

	protected function tell(array $notices, $later) {
		//now: this request's notices (a settings save redirects, core carries them to the next
		//page). Later: kept in the module config, past the config hooks like indexSchema, until
		//StaticPages::showPendingNotices() shows them on a superuser's admin page
		if( !count($notices) ) return;
		if( !$later ){
			foreach( $notices as $notice ) $notice[0] === 'warning' ? $this->sp->warning( $notice[1] ) : $this->sp->message( $notice[1] );
			return;
		}
		$pending = $this->sp->get('htaccessNotice');
		if( !is_array($pending) ) $pending = array();
		foreach( $notices as $notice ){
			$item = array( 'type' => $notice[0], 'text' => $notice[1] );
			if( !in_array( $item, $pending, true ) ) $pending[] = $item;
		}
		$pending = array_values( array_slice( $pending, -10 ) );
		try {
			$this->wire()->modules->configs->saveConfig( $this->sp, 'htaccessNotice', $pending );
			$this->sp->set( 'htaccessNotice', $pending );
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'htaccess: the notice for the next admin page could not be stored: ' . $e->getMessage() );
		}
	}

}
