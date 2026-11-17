<?php
/*
StaticPages: the root .htaccess
AT
17.11.26
*/

namespace ProcessWire;

/*
The rules that tell Apache to answer a request from a static copy, and (from 0.10.0 on) the
block of them kept in the site's root .htaccess. Three layers, each testable alone:

  the generator  settings in, text out: rules(), apacheBlock(). Reads the module's settings
                 and $config only, never the disk and never the database
  the reader     the file in, a state out, nothing written
  the writer     splices the one marked block in, after a backup

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

	//the states of the file, see status(), in the order they are tested
	const states = array('none', 'locked', 'damaged', 'unrecognised', 'candidate', 'absent', 'current', 'outdated');

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

		//validated by staticFileName() already: a plain name, or ''
		$name = $this->sp->staticFileName();
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
		$path = $this->filePath();

		//nothing is ever created where there is no file: nginx, IIS, Caddy, rules in the vhost
		if( !file_exists($path) && !is_link($path) )
			return $this->result( 'none', 'There is no .htaccess in the site root.', $path );

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
		if( $why !== '' ) return $this->result( 'locked', "The .htaccess is left alone: $why.", $path );

		$result = $this->classify($bytes);
		$result['file'] = $path;
		return $result;
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

		$eol = $result['eol'];
		$block = str_replace( "\n", $eol, $result['block'] );

		if( !$result['blockLines'] ){
			//immediately before the "# PW-PAGENAME" line: inside mod_rewrite, after the access
			//rules, ahead of the 16A condition, which belongs to the stock rule 19
			$at = $lines[ $result['pageNameLine'] ]['offset'];
			$result['text'] = substr( $bytes, 0, $at ) . $block . $eol . substr( $bytes, $at );
			return $this->state( $result, 'absent', 'The .htaccess has no StaticPages block yet.' );
		}

		list($first, $last) = $result['blockLines'];
		$current = array();
		for( $n = $first; $n <= $last; $n++ ) $current[] = $lines[$n]['text'];
		if( implode( "\n", $current ) === $result['block'] ){
			$result['text'] = $bytes;
			return $this->state( $result, 'current', 'The StaticPages block in the .htaccess is up to date.' );
		}

		//the block's lines only, the last one without its line end: no other byte changes
		$from = $lines[$first]['offset'];
		$to = $lines[$last]['offset'] + strlen( $lines[$last]['text'] );
		$result['text'] = substr( $bytes, 0, $from ) . $block . substr( $bytes, $to );
		return $this->state( $result, 'outdated', 'The StaticPages block in the .htaccess differs from the one the current settings generate.' );
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
		$name = $this->sp->staticFileName();
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

	/** #pw-internal */
	public function escape($value) {
		//for a regex; in a test string or a substitution mod_rewrite reads a backslash as
		//"the next character as it is", so the same text works there
		return preg_quote( (string) $value );
	}

}
