<?php
/*
StaticPages: tracked path changes
AT
18.10.26
*/

namespace ProcessWire;

/*
Editing a template or an asset file fires no ProcessWire event. The files matching the tracked
patterns are hashed by name, modification time and size on admin requests, at most every
StaticPages::trackingInterval seconds, and a changed hash wipes every static file. With "Track
changes on front-end requests" also after a visitor's response, at most every
StaticPages::trackingIntervalFront seconds, by a stamp of its own.
*/

class StaticPagesTracker extends Wire {

	/** @var StaticPages */
	protected $sp;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function checkTrackedPaths( $force = false ){
		//hashes name, modification time and size of every file matching the tracked patterns
		//and wipes the static files when a hash differs from the one stored last time.
		//No file content is ever read.
		//Public so a deploy script can close the gap admin only checking leaves:
		//$modules->get('StaticPages')->checkTrackedPaths(true);

		$patterns = $this->trackedPathsArray();
		if( !count($patterns) ) return;

		$hashDir = $this->sp->trackedHashDir();
		if( $hashDir === '' ) return;

		if( !$force && !$this->trackingIntervalPassed($hashDir) ) return;

		return $this->compareHashes($patterns, $hashDir, false);
	}

	public function checkFrontend(){
		//"Track changes on front-end requests": the same check after a visitor's response, at most
		//every StaticPages::trackingIntervalFront seconds by a stamp of its own (the admin's is
		//not touched), the walk after the response is finished. No notice: there is no admin page
		//to show it on, a log line says it [decided by user, Q67 B (a)]

		$patterns = $this->trackedPathsArray();
		if( !count($patterns) ) return;

		$hashDir = $this->sp->trackedHashDir();
		if( $hashDir === '' ) return;

		if( !$this->trackingIntervalPassed($hashDir, 'front.stamp', StaticPages::trackingIntervalFront) ) return;

		$this->sp->queue()->finishResponse();

		return $this->compareHashes($patterns, $hashDir, true);
	}

	protected function compareHashes(array $patterns, $hashDir, $front){
		//the walk of checkTrackedPaths() and checkFrontend(): a changed hash queues modeAll

		//lines the operator removed or edited would leave their hash file behind forever
		$this->pruneHashFiles($patterns, $hashDir);

		$changed = array();

		foreach( $patterns as $pattern ){

			$hash = $this->hashPattern($pattern);
			$hashFile = $hashDir . md5($pattern) . '.hash';
			$stored = $this->readHashFile($hashFile);

			//first run, module upgrade, freshly added line, or a hash file that got lost or
			//damaged: seed it and wipe nothing. Every install would otherwise wipe once for no
			//reason, and a broken hash file would wipe on every single admin request
			if( $stored === null ){
				$this->writeHashFile($hashFile, $pattern, $hash);
				continue;
			}

			if( $stored === $hash ) continue;

			$this->writeHashFile($hashFile, $pattern, $hash);
			$changed[] = $pattern;
		}

		if( !count($changed) ) return;

		if( $front ) $this->sp->logDrain( 'Tracked path changed (front-end request): ' . implode(', ', $changed) );
		else $this->sp->message( sprintf( $this->_('Tracked path changed: %s'), implode(', ', $changed) ) );

		//every new hash is on disk before the wipe starts: should the wipe itself touch a
		//tracked file, the next check compares against the new state and stops there instead
		//of wiping again forever. A template or an asset feeds any number of pages: all of them
		return $this->sp->queue()->enqueue(StaticPages::modeAll);
	}

	public function trackingIntervalPassed($hashDir, $stampName = 'check.stamp', $interval = StaticPages::trackingInterval){
		//one stat instead of a walk over every tracked file: the admin fires a lot of ajax
		//(page tree, autocomplete, inline edits) and every one of those requests would
		//otherwise stat every tracked file again.
		//Stamping before the walk and not after is deliberate, two requests arriving
		//together must not both pay for it, which makes this the concurrency guard as well

		//$stampName and $interval: the front-end check's own, see checkFrontend()

		if( $interval < 1 ) return true;

		$stamp = $hashDir . $stampName;

		if( is_file($stamp) && time() - (int) @filemtime($stamp) < $interval ) return false;

		@touch($stamp);

		return true;
	}

	public function trackedPathsArray(){
		//validated, normalised, deduplicated spec lines

		$patterns = array();

		foreach( $this->sp->linesToArray( $this->sp->configValue('trackedPaths', StaticPages::trackedPathsDefault) ) as $line ){

			//operators on Windows type backslashes, the spec language uses forward slashes.
			//No escape syntax is supported, so the conversion is unambiguous
			$line = str_replace( '\\', '/', $line );
			$line = preg_replace( '#/+#', '/', $line );

			//a leading slash is a likely typo rather than an attempt at an absolute path,
			//dropping it is friendlier than dropping the line
			$line = ltrim( $line, '/' );
			if( $line === '' ) continue;

			//nothing outside the web root. Not a security boundary, whoever edits the module
			//config already runs php and nothing here reads file contents. It keeps a line
			//like ../.. from sending the walk up the whole disk and hanging every admin
			//request until php times out
			if( strpos($line, "\0") !== false ) continue;
			if( preg_match('#^[A-Za-z]:#', $line) ) continue;
			if( in_array( '..', explode('/', $line), true ) ) continue;

			//a line naming a directory means everything below it: a trailing slash says so
			//outright, otherwise the disk is asked
			if( substr($line, -1) === '/' )
				$line = rtrim($line, '/') . '/**/*';

			else if( strpbrk($line, '*?') === false && is_dir( $this->sp->normalizePath( $this->sp->rootPath() . '/' . $line ) ) )
				$line .= '/**/*';

			$patterns[] = $line;
		}

		return array_values( array_unique($patterns) );
	}

	public function globToRegex($pattern){
		//translates one spec line into a regex anchored over a root relative, forward slash
		//path. ** spans path segments and may collapse to nothing, * and ? never cross a slash

		$out = '';
		$len = strlen($pattern);

		for( $i = 0; $i < $len; $i++ ){

			$char = $pattern[$i];

			if( $char === '*' ){

				//a second star: ** spans segments
				if( $i+1 < $len && $pattern[$i+1] === '*' ){
					$i++;

					//**/ has to match zero segments too, site/templates/**/*.php is meant
					//to find site/templates/home.php as well
					if( $i+1 < $len && $pattern[$i+1] === '/' ){
						$i++;
						$out .= '(?:.*/)?';
					}

					//a trailing or bare ** matches anything, slashes included
					else
						$out .= '.*';

					continue;
				}

				//one star stays inside one segment
				$out .= '[^/]*';
				continue;
			}

			if( $char === '?' ){
				$out .= '[^/]';
				continue;
			}

			$out .= preg_quote( $char, '#' );
		}

		//case insensitive on purpose: the module is configured on a case insensitive Windows
		//or macOS filesystem and deployed to a case sensitive Linux one, and the failure mode
		//of a case mismatch is a pattern that silently matches nothing and never wipes
		return '#^' . $out . '$#i';
	}

	public function patternBaseDir($pattern){
		//longest leading run of segments holding no wildcard. The last segment is the file
		//name part and never contributes a directory to start the walk from

		$segments = explode('/', $pattern);
		array_pop($segments);

		$base = array();
		foreach( $segments as $segment ){
			if( $segment === '' ) continue;
			if( strpbrk($segment, '*?') !== false ) break;
			$base[] = $segment;
		}

		return implode('/', $base);
	}

	public function trackedPathWalk($baseDir, $pattern, $base){
		//flat when only the last segment of the pattern holds a wildcard, recursive
		//otherwise: site/assets/css/*.css must not walk the subdirectories of css/

		$paths = array();

		$segments = explode('/', $pattern);
		array_pop($segments);

		$recursive = false;
		foreach( $segments as $segment ){
			if( strpbrk($segment, '*?') === false ) continue;
			$recursive = true;
			break;
		}

		//an unreadable dir throws UnexpectedValueException on construction: hashing what
		//was reachable beats aborting the whole check
		try {

			if( !$recursive ){
				foreach( new \FilesystemIterator( $baseDir, \FilesystemIterator::SKIP_DOTS ) as $entry ){
					if( $entry->isDir() ) continue;
					$paths[] = $entry->getPathname();
				}
				return $paths;
			}

			$rii = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $baseDir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			//a pattern with no ** can not reach deeper than the segments it declares
			if( strpos($pattern, '**') === false ){
				$baseCount = strlen($base) ? count( explode('/', $base) ) : 0;
				$rii->setMaxDepth( max( 0, count( explode('/', $pattern) ) - $baseCount - 1 ) );
			}
			else
				$rii->setMaxDepth(StaticPages::trackingMaxDepth);

			foreach( $rii as $entry ){
				//an empty dir is a leaf and still turns up here
				if( $entry->isDir() ) continue;
				$paths[] = $entry->getPathname();
			}
		}

		catch( \Exception $e ){}

		return $paths;
	}

	public function staticDirPrefix(){
		//the base directory with a trailing separator while the module uses it: a "Static base
		//directory" is set, accepted and holds the marker [decided by user, Q36 (a)]; '' else.
		//A dir named by mistake, without the marker, stays tracked like any other
		if( $this->sp->staticBaseDir() === '' || !$this->sp->baseDirReady() ) return '';
		return rtrim( $this->sp->normalizePath( $this->sp->staticRoot() ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	}

	public function isExcludedFromTracking($path, $staticPrefix = null){
		//ProcessWire and this module write into these dirs themselves. The manifest,
		//WireCache.maint, the FileCompiler output, the temp, session and log files all
		//change on ordinary requests, and a pattern covering them would detect a change,
		//wipe, change the state again by wiping, and never settle.
		//$staticPrefix: staticDirPrefix(), asked here when not given

		//a generated static file is not a source file either: a name a scan matches, index.xml
		//and index.txt only while "Cache XML and plain text responses" is on
		if( in_array( basename($path), $this->sp->scanFileNames(), true ) ) return true;

		$path = $this->sp->normalizePath($path);

		//nor is anything below the base directory in use: the marker, copies, root file copies.
		//A marker left behind after the setting is cleared or changed is hashed again, one
		//"Tracked path changed" wipe [decided by user, Q37 (a)]
		if( $staticPrefix === null ) $staticPrefix = $this->staticDirPrefix();
		if( $staticPrefix !== '' ){
			$head = substr( $path, 0, strlen($staticPrefix) );
			if( DIRECTORY_SEPARATOR === '\\' ? strcasecmp($head, $staticPrefix) === 0 : $head === $staticPrefix ) return true;
		}

		$paths = $this->wire()->config->paths;

		foreach( array('cache', 'logs', 'sessions', 'tmp') as $key ){
			$dir = (string) $paths->$key;
			if( !strlen($dir) ) continue;
			$dir = rtrim( $this->sp->normalizePath($dir), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if( strpos($path, $dir) === 0 ) return true;
		}

		return false;
	}

	public function ownRootFiles(){
		//pathKey() => true for the root files this module wrote or keeps, no source files: each
		//recorded root file copy (/robots.txt, /<host>/robots.txt, as the index records them) and
		//the dot backup of each listed name in the site root (/.robots.txt), recorded copy or not.
		//A pattern covering the site root (*.txt) counted them: a wipe after every render of the
		//page. A hand-made file of a listed name the index does not record still counts. Empty
		//without a listed name, or when the index can not be read
		$names = array_keys( $this->sp->rootFilesArray() );
		if( !count($names) ) return array();
		$files = array();
		$root = rtrim( $this->sp->normalizePath( $this->sp->rootPath() ), DIRECTORY_SEPARATOR );
		foreach( $names as $name ) $files[ $this->pathKey( $root . DIRECTORY_SEPARATOR . '.' . $name ) ] = true;
		$static = rtrim( $this->sp->normalizePath( $this->sp->staticRoot() ), DIRECTORY_SEPARATOR );
		try {
			foreach( $this->sp->wiper()->recordedRootFiles() as $relative ) $files[ $this->pathKey( $static . $this->sp->normalizePath($relative) ) ] = true;
		}
		catch( \Throwable $e ){}
		return $files;
	}

	public function pathKey($path){
		//a path as ownRootFiles() keys it: native separators, letter case aside on Windows
		$path = $this->sp->normalizePath( (string) $path );
		return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
	}

	public function hashPattern($pattern){
		//md5 over "relative path|mtime|size" of every matching file, sorted so the order the
		//filesystem hands entries back in can not change the result. Paths are joined with
		//forward slashes so the same tree hashes identically on Windows and on Linux

		$regex = $this->globToRegex($pattern);
		$base = $this->patternBaseDir($pattern);

		$root = rtrim( $this->sp->normalizePath( $this->sp->rootPath() ), DIRECTORY_SEPARATOR );
		$baseDir = strlen($base) ? $root . DIRECTORY_SEPARATOR . $this->sp->normalizePath($base) : $root;

		//a base dir that is not there yet still gets a hash: the empty set has one, and
		//creating the dir later is then a change like any other
		if( !is_dir($baseDir) ) return md5('');

		$entries = array();

		//asked once per pattern, not once per file
		$staticPrefix = $this->staticDirPrefix();
		$ownFiles = $this->ownRootFiles();

		foreach( $this->trackedPathWalk($baseDir, $pattern, $base) as $path ){

			if( $this->isExcludedFromTracking($path, $staticPrefix) ) continue;
			if( count($ownFiles) && isset( $ownFiles[ $this->pathKey($path) ] ) ) continue;

			$relative = ltrim( str_replace( '\\', '/', substr($path, strlen($root)) ), '/' );
			if( !preg_match($regex, $relative) ) continue;

			//stat only, file contents are never read. filemtime rather than
			//SplFileInfo::getMTime() because getMTime() throws when the stat fails while
			//this returns false, and php's stat cache makes it free either way. False means
			//the file went away between the readdir and the stat, it is not part of the set
			$mtime = @filemtime($path);
			if( $mtime === false ) continue;

			$size = @filesize($path);
			if( $size === false ) $size = 0;

			$entries[] = "$relative|$mtime|$size";
		}

		sort( $entries, SORT_STRING );

		return md5( implode("\n", $entries) );
	}

	public function readHashFile($hashFile){
		//null means there is nothing usable stored yet and the caller seeds instead of
		//wiping. A missing, unreadable or truncated file has to read as null for the same
		//reason: a wipe is never the safe answer to a broken hash file, it would repeat on
		//every single admin request

		if( !is_file($hashFile) ) return null;

		$lines = @file( $hashFile, FILE_IGNORE_NEW_LINES );
		if( $lines === false || !count($lines) ) return null;

		$hash = trim( $lines[0] );
		if( !preg_match('/^[0-9a-f]{32}$/', $hash) ) return null;

		return $hash;
	}

	public function writeHashFile($hashFile, $pattern, $hash){
		//the spec line and the time go into the file next to the hash: the file name is an
		//md5 and says nothing, and these files have to be readable by whoever is debugging
		//why a wipe did or did not happen

		$content = $hash . "\n" . $pattern . "\n" . date('Y-m-d H:i:s') . "\n";

		return file_put_contents( $hashFile, $content, LOCK_EX ) !== false;
	}

	public function pruneHashFiles(array $patterns, $hashDir){
		//an edited or removed line leaves its hash file behind: never read again, and a
		//stale spec line on disk misleads whoever is debugging the next wipe

		$keep = array();
		foreach( $patterns as $pattern ) $keep[ md5($pattern) . '.hash' ] = true;

		try {
			$iterator = new \FilesystemIterator( $hashDir, \FilesystemIterator::SKIP_DOTS );
		}
		catch( \Exception $e ){ return; }

		foreach( $iterator as $entry ){
			//never recursing, never touching check.stamp or whatever a later version adds
			if( $entry->isDir() ) continue;
			$name = $entry->getFilename();
			if( substr($name, -5) !== '.hash' ) continue;
			if( isset($keep[$name]) ) continue;
			@unlink( $entry->getPathname() );
		}
	}

}
