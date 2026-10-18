<?php
/*
StaticPages: writing static files
AT
18.10.26
*/

namespace ProcessWire;

/*
The atomic write of a static file and the preparation of the markup that goes into it.
StaticPages::cachePage() decides whether a response is cached at all.
*/

class StaticPagesPublisher extends Wire {

	/** @var StaticPages */
	protected $sp;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function unpublish($path, $createdDir){
		@unlink($path);
		if( $createdDir === '' ) return;
		$this->sp->wiper()->pruneEmptyDirs(
			$this->sp->normalizePath( rtrim($createdDir, '/\\') ),
			$this->sp->normalizePath( $this->sp->rootPath() )
			);
	}

	public function stripCsrf($html) {
		/*
		A CSRF token belongs to one session. A static file is served off disk to
		everyone, with no PHP and no session, so a token frozen into it is dead for
		every visitor after the one whose render filled the cache -- and every POST
		carrying it was answered with a fatal until api/index.php stopped calling
		SessionCSRF::validate().

		Emptying the holders rather than dropping them: the markers have to survive
		for @atispro/core 00-csrf.js to write the live pair into them once
		z-app-init.js adopts what GET /api/data answers.

		Attribute quoting is not guaranteed -- the markup passes through a minifier
		that drops quotes around simple values -- so both forms are matched. Quote
		characters are spelled \x22 and \x27 to keep them out of the PHP string.
		*/

		//<meta name=csrf-token content=...> and its -name twin
		$html = preg_replace(
			'/(<meta\s+name=([\x22\x27]?)csrf-token(?:-name)?\2\s+content=)(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s>]*)/i',
			'$1""',
			$html
			);
		//preg_replace answers null on a PCRE failure, and the caller must not take that
		//for markup: writing null produces an empty file rather than a failed write
		if( $html === null ) return null;

		//<input type=hidden rel=csrf name=... value=...>
		$html = preg_replace_callback(
			'/<input\b[^>]*\brel=([\x22\x27]?)csrf\1[^>]*>/i',
			function($tag){
				return preg_replace(
					'/\b(value|name)=(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s>\/]*)/i',
					'$1=""',
					$tag[0]
					);
				},
			$html
			);
		if( $html === null ) return null;

		return $html;
	}

	public function publish($path, $content){
		//write to a temporary name, then move it into place. file_put_contents() fills
		//the target in place and its LOCK_EX means nothing to the web server, so every
		//concurrent request arriving during a write was answered from a directory holding
		//no index.html yet, or holding a truncated one. rename() is atomic within a
		//filesystem and replaces an existing target on Linux and Windows alike, so a
		//reader sees either the whole previous file or the whole new one

		//the leading dot keeps a leftover from a killed process out of reach: the root
		//htaccess denies every dot path that is not .well-known
		$tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . getmypid() . '.tmp';
		//the name has to stay matchable by tmpFileGlob(), the wipes sweep it by that

		if( file_put_contents($tmp, $content, LOCK_EX) === false ){
			@unlink($tmp);
			return false;
		}

		if( !@rename($tmp, $path) ){
			@unlink($tmp);
			return false;
		}

		return true;
	}

	public function tmpFileGlob(){
		//the shape publish() writes, as a glob: '.index.html.*.tmp'
		return '.' . $this->sp->staticFileName() . '.*.tmp';
	}

	public function responseCode(){
		//CLI and any SAPI not tracking a status answer false, which is not an error here
		$code = function_exists('http_response_code') ? http_response_code() : 200;
		return $code === false ? 200 : (int) $code;
	}

	public function cookieSkip(array $cookies = null){
		//the name of a request cookie listed in Never cache with these cookies, or ''. Output
		//that depends on a cookie (a currency, a consent banner, a region) would be frozen
		//into the file everybody gets. "*" matches any run of characters, "?" one
		$patterns = $this->sp->linesToArray( $this->sp->configValue('noCacheCookies', '') );
		if( !count($patterns) ) return '';
		if( $cookies === null ) $cookies = $this->wire()->input->cookie->getArray();
		foreach( array_keys($cookies) as $name ){
			$name = (string) $name;
			foreach( $patterns as $pattern ) if( fnmatch( $pattern, $name ) ) return $name;
		}
		return '';
	}

	public function headerSkip(array $headers = null){
		//why the response is not one file for everybody, judged by the headers PHP is about
		//to send, or ''. A cookie set for this visitor means output made for this visitor;
		//ProcessWire's own session cookies are set on a first visit and say nothing about the
		//markup. A Vary on anything but Accept-Encoding says the answer depends on a request
		//header (Accept-Language, Cookie), which one static file can not. headers_list() is
		//always empty under CLI
		if( $headers === null ) $headers = headers_list();
		if( !count($headers) ) return '';

		$config = $this->wire()->config;
		$sessionName = (string) $config->sessionName;
		$sessionNameSecure = (string) $config->sessionNameSecure;
		if( $sessionNameSecure === '' ) $sessionNameSecure = $sessionName . 's';
		$own = array();
		foreach( array($sessionName, $sessionNameSecure) as $name ){
			if( $name === '' || $name === 's' ) continue;
			$own[$name] = true;
			$own[$name . Session::challengeSuffix] = true;
		}

		foreach( $headers as $header ){
			$pos = strpos( (string) $header, ':' );
			if( $pos === false ) continue;
			$name = strtolower( trim( substr($header, 0, $pos) ) );
			$value = trim( substr($header, $pos + 1) );

			if( $name === 'set-cookie' ){
				$cookie = trim( strpos($value, '=') !== false ? substr( $value, 0, strpos($value, '=') ) : $value );
				if( isset($own[$cookie]) ) continue;
				return "the response sets the cookie $cookie";
			}

			if( $name === 'vary' ){
				foreach( explode(',', $value) as $vary ){
					$vary = strtolower( trim($vary) );
					if( $vary === '' || $vary === 'accept-encoding' ) continue;
					return "the response varies by $vary";
				}
			}
		}

		return '';
	}

	public function logStaticFile($path){
		//root-relative path, one entry per line
		$relative = substr( $path, strlen($this->sp->rootPath()) );
		file_put_contents( $this->sp->manifestPath(), $relative . "\n", FILE_APPEND | LOCK_EX );
	}

}
