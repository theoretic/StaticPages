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
  - any other request for an existing directory goes to ProcessWire with the "it" value the
    stock rule 19 passes, unless the directory holds an index.php of its own: then it, and the
    home page with it, gets what the stock file answers
  - everything else is left to the stock rules
A missing or outdated block may only cost speed, never correctness: without a rule a request
reaches PHP.
*/

class StaticPagesHtaccess extends Wire {

	//the first and the last line of the block, alone on their lines
	const markerBegin = '# BEGIN StaticPages';
	const markerEnd = '# END StaticPages';

	//what a value taken from a setting or $config must match before it is put into a rule
	const plainNamePattern = '/^[A-Za-z0-9_-]+$/';

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
		//holding no copy. Exactly the stock rule 19, so ProcessWire gets the same "it"
		$rules[] = array(
			'comment' => 'Any other request for an existing directory without an index file of its own goes to ProcessWire.',
			'conds' => array(
				array( '%{REQUEST_FILENAME}', '-d' ),
				array( '%{REQUEST_FILENAME}/index.php', '!-f' ),
				),
			'pattern' => '^(.*)$',
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

	/** #pw-internal */
	public function escape($value) {
		//for a regex; in a test string or a substitution mod_rewrite reads a backslash as
		//"the next character as it is", so the same text works there
		return preg_quote( (string) $value );
	}

}
