<?php
/*
StaticPages: deleting static files
AT
18.10.26
*/

namespace ProcessWire;

/*
Three ways to find what to delete: the dependency index (every save and every drain), the
manifest (sites without index tables) and a scan of the site root (forced wipes, and sites
with neither). The scan runs in PHP, or as one find or one PowerShell process.

Whatever finds a path, nothing is deleted unless realpath() puts it below the site root, its
name is the configured static file name (or the temporary name publish() writes), and it is
outside every dir listed in Non-static directories. The scan only walks root dirs that are
static copies of pages, worked out when it runs: see scanTargets(). A root file listed in
"Replace these root files with pages" (robots.txt) goes by its index record only, directly in
the site root; its hand-made original is kept as .robots.txt, see takeOverRootFile().

With a "Static base directory" every one of these happens below it instead: realpath() against
the base directory, the scan walks only the base directory, Non-static directories does not
apply there, a root file's copy is <base>/robots.txt and nothing in the web root is taken over,
renamed or deleted. Without the marker file (StaticPages::baseDirMarker) in the base directory
nothing at all is deleted [decided by user, Q34].
*/

class StaticPagesWiper extends Wire {

	/** @var StaticPages */
	protected $sp;

	//root dirs the last scanTargets() left alone although no list names them
	protected $leftAlone = array();

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function wipeIndexed($tags, $dryRun = false){
		//$tags null: every indexed file. $dryRun: rows and files stay, the result lists them

		$files = $this->sp->index()->indexedFiles($tags);
		if( !count($files) ) return $this->emptyResult($dryRun);

		//the rows stay as well: the files they name are still there
		if( $this->baseDirRefuses('the wipe by the index') ) return $this->emptyResult($dryRun);

		if( $dryRun ) return $this->deleteStaticFiles($files, true);

		//rows first, files second: a render recording the same path meanwhile leaves a row for a
		//file that is gone, which is harmless, and never a file that nothing knows about
		if( !$this->sp->index()->dropIndexRows( array_keys($files) ) )
			throw new WireException('the index rows could not be dropped, the queue is kept');

		return $this->deleteStaticFiles($files);
	}

	public function emptyResult($dryRun = false){
		$result = array('files' => 0, 'bytes' => 0, 'dirs' => 0);
		if( $dryRun ) $result += array('dryRun' => true, 'paths' => array(), 'leftAlone' => array());
		return $result;
	}

	public function baseDirRefuses($what){
		//true, logged, when a base directory is set and holds no marker file: nothing is deleted
		//from it, by any way of finding files [decided by user, Q34 A (b)]
		if( $this->sp->baseDirReady() ) return false;
		$this->sp->logDrain( "$what deleted nothing: the Static base directory " . $this->sp->staticBaseDir() . ' holds no marker file ' . StaticPages::baseDirMarker );
		return true;
	}

	public function protectedDirs(){
		//Non-static directories as the deletes use it: none below a base directory, where only
		//static copies live [decided by user, Q34 B (a)]
		return $this->sp->staticBaseDir() === '' ? $this->sp->nonStaticDirsArray() : array();
	}

	public function deleteStaticFiles(array $files, $dryRun = false, $htmlOnly = false, ?array $rootNames = null){
		//$dryRun: nothing is deleted, the result lists what would go under 'paths', root
		//relative, dirs with a trailing slash. A file is deleted under a name of
		//knownFileNames(), whether or not the switch is on: a copy written while it was on
		//goes by its record after it went off. $htmlOnly: the HTML name alone, a manifest
		//line never deletes anything else. A root file name ("Replace these root files with
		//pages", robots.txt) only directly in the site root, only while it is listed and the
		//switch is on, never for a manifest line; $rootNames: those names instead (the give
		//back of a name leaving the list)

		$result = $this->emptyResult($dryRun);

		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ) return $result;
		$names = $htmlOnly ? array($staticFile) : $this->sp->knownFileNames();
		if( $htmlOnly ) $rootNames = array();
		else if( $rootNames === null ) $rootNames = $this->sp->configValue('cacheNonHtml', 0) ? array_keys( $this->sp->rootFilesArray() ) : array();

		if( $this->baseDirRefuses('a delete') ) return $result;

		//a copy of a page lives below the static root, a root file's copy directly in it: the
		//site root, or the base directory
		$root = $this->sp->staticRoot();
		$siteRoot = $root;
		$rootNorm = rtrim( $this->sp->normalizePath($siteRoot), DIRECTORY_SEPARATOR );
		$nonStaticDirs = $this->protectedDirs();
		//"Static files per host": a root file's copy also one host dir down, /<host>/robots.txt
		//[decided by user, Q38 C (b)]
		$hostKey = $this->sp->hostKeyOn();
		$dirs = array();
		$failed = array();
		$deleted = array();

		foreach( $files as $relative ){

			$relative = (string) $relative;

			//the index could have been tampered with, validating every entry before deletion
			if( $relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false ) continue;
			$path = $root . $relative;
			if( !in_array( basename($path), $names, true ) ){
				if( !in_array( basename($path), $rootNames, true ) ) continue;
				if( $hostKey && $this->isHostRootCopy($relative, basename($path)) ){
					$path = $siteRoot . $relative;
					if( rtrim( $this->sp->normalizePath( dirname( dirname($path) ) ), DIRECTORY_SEPARATOR ) !== $rootNorm ) continue;
				}
				else {
					if( $relative !== '/' . basename($path) ) continue;
					$path = $siteRoot . $relative;
					if( rtrim( $this->sp->normalizePath( dirname($path) ), DIRECTORY_SEPARATOR ) !== $rootNorm ) continue;
				}
			}
			if( !is_file($path) ) continue;
			$dirPath = dirname($path);
			if( $this->isProtectedPath($dirPath, $nonStaticDirs) ) continue;
			//a link leading out of the site, or a path the checks above did not see through
			if( !$this->insideRoot($path) ) continue;

			$size = (int) @filesize($path);

			if( $dryRun ){
				$result['paths'][] = $this->relativePath($path);
				$deleted[ $this->sp->normalizePath($path) ] = true;
			}
			else if( !@unlink($path) ){
				$failed[] = $relative;
				continue;
			}

			$result['files']++;
			$result['bytes'] += $size;
			$dirs[$dirPath] = true;
		}

		if( $dryRun ){
			$gone = $this->simulatePrune( array_keys($dirs), $this->sp->normalizePath($root), $deleted );
			foreach( $gone as $dir ) $result['paths'][] = $this->relativePath($dir) . '/';
			$result['dirs'] = count($gone);
			return $result;
		}

		//a file a reader holds open on Windows: the next drain of any kind tries again
		foreach( $failed as $relative ) $this->sp->index()->recordStaticFile( $relative, 0, array('*') );

		$result['dirs'] = $this->pruneDirs( array_keys($dirs), $this->sp->normalizePath($root) );

		return $result;
	}

	public function describeWipe($result){
		//a shell scan counts nothing
		if( !is_array($result) ) return 'Full disk scan done.';
		$bytes = $this->readableFilesize( $result['bytes'] );
		if( !empty($result['dryRun']) )
			return "Dry run: {$result['files']} static file(s) ($bytes) and {$result['dirs']} dir(s) would be deleted, nothing was.";
		return "{$result['files']} static file(s) ($bytes) and {$result['dirs']} dir(s) deleted.";
	}

	public function relativePath($path){
		//static root relative, forward slashes, leading slash: the form the index and the log use
		$root = rtrim( str_replace( '\\', '/', $this->sp->staticRoot() ), '/' );
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
	}

	public function simulatePrune(array $dirs, $rootNorm, array $deleted){
		//the dirs pruneDirs() would remove once the files in $deleted (normalised path => true)
		//were gone, without touching anything: a dir goes when everything in it goes
		$rootNorm = rtrim( $rootNorm, DIRECTORY_SEPARATOR );
		$rootLength = strlen($rootNorm);
		$set = array();
		foreach( $dirs as $dir ){
			$dir = rtrim( $this->sp->normalizePath($dir), DIRECTORY_SEPARATOR );
			while( strlen($dir) > $rootLength && !isset($set[$dir]) ){
				$set[$dir] = true;
				$parent = dirname($dir);
				if( $parent === $dir ) break;
				$dir = $parent;
			}
		}
		$list = array_keys($set);
		usort( $list, fn($a, $b) => strlen($b) - strlen($a) );

		$gone = array();
		foreach( $list as $dir ){
			if( !is_dir($dir) || is_link($dir) || !$this->insideRoot($dir) ) continue;
			$empty = true;
			try {
				foreach( new \FilesystemIterator( $dir, \FilesystemIterator::SKIP_DOTS ) as $entry ){
					$entryPath = $this->sp->normalizePath( $entry->getPathname() );
					if( isset($deleted[$entryPath]) || isset($gone[$entryPath]) ) continue;
					$empty = false;
					break;
				}
			}
			catch( \Throwable $e ){
				$empty = false;
			}
			if( $empty ) $gone[$dir] = true;
		}
		return array_keys($gone);
	}

	public function wipeCachesManifest($dryRun = false){
		//no index tables only: deletes the files the manifest lists, no site root scanning.
		//The manifest is moved aside first and an empty one put in its place: renders appending
		//meanwhile land in the new one instead of being lost, and an empty manifest means
		//"nothing cached", where a missing one sent the next save into the full disk scan

		$manifest = $this->sp->manifestPath();

		//the manifest stays as well: the files it lists are still there
		if( $this->baseDirRefuses('the wipe by the manifest') ) return $this->emptyResult($dryRun);

		if( $dryRun ){
			$lines = @file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			return $this->deleteStaticFiles( array_unique( $lines === false ? array() : $lines ), true, true );
		}

		$taken = $manifest . '.' . getmypid() . '.wipe';
		if( !@rename($manifest, $taken) ) return array('files' => 0, 'bytes' => 0, 'dirs' => 0);
		if( !is_file($manifest) ) @touch($manifest);

		$lines = @file( $taken, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		@unlink($taken);
		if( $lines === false ) $lines = array();

		//no manifest line names anything but an HTML copy: non-HTML is never written without
		//index tables
		return $this->deleteStaticFiles( array_unique($lines), false, true );
	}

	public function pruneDirs(array $dirs, $rootNorm){
		//removes every dir in $dirs and every ancestor below the site root that is empty, each
		//looked at once, deepest first: a parent is only looked at once its children are gone.
		//Walking up from every deleted file instead opened the same ancestors over and over

		$rootNorm = rtrim( $rootNorm, DIRECTORY_SEPARATOR );
		$rootLength = strlen($rootNorm);
		$set = array();

		foreach( $dirs as $dir ){
			$dir = rtrim( $this->sp->normalizePath($dir), DIRECTORY_SEPARATOR );
			while( strlen($dir) > $rootLength && !isset($set[$dir]) ){
				$set[$dir] = true;
				$parent = dirname($dir);
				if( $parent === $dir ) break;
				$dir = $parent;
			}
		}

		$list = array_keys($set);
		usort( $list, fn($a, $b) => strlen($b) - strlen($a) );

		$deletedDirs = 0;
		foreach( $list as $dir ){
			if( !is_dir($dir) || is_link($dir) ) continue;
			if( (new \FilesystemIterator($dir))->valid() ) continue;
			if( !$this->insideRoot($dir) ) continue;
			if( @rmdir($dir) ) $deletedDirs++;
		}

		return $deletedDirs;
	}

	public function pruneEmptyDirs($dirPath, $rootNorm){
		//removes $dirPath if empty, then its empty ancestors, never the site root nor anything
		//above it: the walk stops at the first dir that is not empty, not a dir, a link, or not
		//below the root once realpath() had its say

		$rootNorm = rtrim( $this->sp->normalizePath($rootNorm), DIRECTORY_SEPARATOR );
		$dir = rtrim( $this->sp->normalizePath($dirPath), DIRECTORY_SEPARATOR );
		$deletedDirs = 0;

		for( $i = 0; $i < 100 && strlen($dir) > strlen($rootNorm); $i++ ){
			if( !is_dir($dir) || is_link($dir) ) break;
			if( (new \FilesystemIterator($dir))->valid() ) break;
			if( !$this->insideRoot($dir) ) break;
			if( !@rmdir($dir) ) break;
			$deletedDirs++;
			$parent = dirname($dir);
			if( $parent === $dir ) break;
			$dir = $parent;
		}

		return $deletedDirs;
	}

	public function insideRoot($path){
		//realpath() resolves links, junctions and dots: whatever a path says, it has to end up
		//strictly below the static root to be deleted
		$rootReal = realpath( $this->sp->staticRoot() );
		$real = realpath($path);
		if( $rootReal === false || $real === false ) return false;
		$prefix = rtrim( $rootReal, '/\\' ) . DIRECTORY_SEPARATOR;
		if( strlen($real) <= strlen($prefix) ) return false;
		if( DIRECTORY_SEPARATOR === '\\' ) return strncasecmp( $real, $prefix, strlen($prefix) ) === 0;
		return strncmp( $real, $prefix, strlen($prefix) ) === 0;
	}

////  root files ("Replace these root files with pages")

	/**
	 * Take a hand-made root file over on a settings save: <root>/robots.txt is renamed to
	 * <root>/.robots.txt, so that the listed page's copy can take its place
	 *
	 * Only a file the index does not record, only with index tables, only when no dot backup
	 * exists yet (one is never overwritten) and only while the .htaccess hands the name to
	 * ProcessWire (StaticPagesHtaccess::rootRoute()); else the file stays and the save warns
	 * why [decided by user, Q5]. With $dryRun the rename is held back and logged [decided by
	 * user, Q16 (a)]. The caller asks only for a listed name while the switch is on.
	 *
	 * #pw-internal
	 *
	 * @param string $name A name of StaticPages::rootFilesArray()
	 * @param string $path Its page path
	 * @param array $data The settings being stored
	 * @param bool $dryRun
	 * @return string 'none' (no file, or a Static base directory is set), 'recorded' (the
	 *   module's copy), 'taken', 'dry run', 'refused'
	 *
	 */
	public function takeOverRootFile($name, $path, array $data, $dryRun = false){
		//with a base directory the copy is <base>/<name>: the web root's file is not touched
		if( $this->sp->staticBaseDir() !== '' ) return 'none';
		$file = $this->sp->rootPath() . "/$name";
		$backup = $this->sp->rootPath() . "/.$name";
		$line = "$name $path";
		if( !file_exists($file) && !is_link($file) ) return 'none';
		$why = '';
		if( !$this->sp->index()->ensureTables() ) $why = 'there are no index tables';
		else if( $this->sp->index()->hasFile("/$name") ) return 'recorded';
		else if( is_dir($file) && !is_link($file) ) $why = "/$name is a directory";
		else if( file_exists($backup) || is_link($backup) ) $why = "a backup /.$name is in the way, it is never overwritten";
		if( $why === '' && !$this->sp->htaccess()->using($data)->rootRoute($name, $path) ){
			$this->sp->warning( sprintf(
				$this->_('"Replace these root files with pages", line "%1$s": /%2$s is not taken over, the root .htaccess does not hand /%2$s to the page %3$s. A site that keeps its own rules adds these lines above the "# PW-PAGENAME" line: %4$s'),
				$line, $name, $path, str_replace( "\n", ' | ', $this->sp->htaccess()->rootRouteLines($name, $path) )
				) );
			$this->sp->logDrain( "root file $name: not taken over, the .htaccess holds no route to $path" );
			return 'refused';
		}
		if( $why !== '' ){
			$this->sp->warning( sprintf( $this->_('"Replace these root files with pages", line "%1$s": /%2$s is not taken over: %3$s.'), $line, $name, $why ) );
			$this->sp->logDrain( "root file $name: not taken over: $why" );
			return 'refused';
		}
		if( $dryRun ){
			$this->sp->logDrain( "dry run: root file $name: would be renamed to .$name, nothing was" );
			return 'dry run';
		}
		if( !@rename($file, $backup) ){
			$this->sp->warning( sprintf( $this->_('"Replace these root files with pages", line "%1$s": /%2$s could not be renamed to /.%2$s.'), $line, $name ) );
			$this->sp->logDrain( "root file $name: could not be renamed to .$name" );
			return 'refused';
		}
		clearstatcache();
		$this->sp->logDrain( "root file $name: taken over for $path, the original is kept as .$name" );
		return 'taken';
	}

	/**
	 * Give a root file back: the recorded copy <root>/robots.txt is deleted, then <root>/.robots.txt
	 * renamed back when it exists and no file of that name is in the way (the save warns then).
	 * When a name leaves the list, when the switch goes off, on uninstall. With $dryRun the
	 * copy still goes, the rename is held back and logged [decided by user, Q16 (a)]. Logged
	 * either way
	 *
	 * #pw-internal
	 *
	 * @param string $name
	 * @param bool $dryRun
	 * @param string $context For the log: 'settings save', 'uninstall'
	 * @return string 'none' (no backup), 'given back', 'dry run', 'in the way', 'failed', 'base dir'
	 *   (a Static base directory is set: the copy went, nothing renamed)
	 *
	 */
	public function giveBackRootFile($name, $dryRun = false, $context = 'settings save'){
		$relative = "/$name";
		$file = $this->sp->rootPath() . $relative;
		$backup = $this->sp->rootPath() . "/.$name";
		$log = "$context: root file $name";

		$this->deleteRootFileCopy($name, $context);

		//with a base directory nothing in the web root is renamed: the save that sets one gives
		//the backups back before it is used [decided by user, Q34 C (a)]
		if( $this->sp->staticBaseDir() !== '' ){
			if( file_exists($backup) || is_link($backup) ) $this->sp->logDrain( "$log: .$name is not renamed back, a Static base directory is set" );
			return 'base dir';
		}

		if( !file_exists($backup) && !is_link($backup) ){
			$this->sp->logDrain( "$log: no backup .$name to give back" );
			return 'none';
		}
		if( $dryRun ){
			$this->sp->logDrain( "dry run: $log: .$name would be renamed back to $name, nothing was" );
			return 'dry run';
		}
		if( file_exists($file) || is_link($file) ){
			$this->sp->warning( sprintf( $this->_('StaticPages: /.%1$s is not renamed back to /%1$s, a file /%1$s is in the way.'), $name ) );
			$this->sp->logDrain( "$log: .$name is not renamed back, a file $name is in the way" );
			return 'in the way';
		}
		if( !@rename($backup, $file) ){
			$this->sp->warning( sprintf( $this->_('StaticPages: /.%1$s could not be renamed back to /%1$s.'), $name ) );
			$this->sp->logDrain( "$log: .$name could not be renamed back" );
			return 'failed';
		}
		clearstatcache();
		$this->sp->logDrain( "$log: .$name renamed back to $name" );
		return 'given back';
	}

	/**
	 * Delete the recorded copy <root>/robots.txt by its index record, row first, through the
	 * checks of deleteStaticFiles(); a file the index does not know stays. Renames nothing. The
	 * first half of giveBackRootFile(), and a settings save that keeps a listed name and changes
	 * its page path [decided by user, Q26]. Runs with Dry run checked as well (Q16 a). Logged
	 *
	 * #pw-internal
	 *
	 * @param string $name
	 * @param string $context For the log
	 * @return bool Whether a copy was deleted
	 *
	 */
	public function deleteRootFileCopy($name, $context = 'settings save'){
		$relative = "/$name";
		$log = "$context: root file $name";
		if( !$this->sp->index()->ensureTables() ) return false;
		//"Static files per host": every recorded /<host>/<name> as well [decided by user, Q38 C (b)]
		if( $this->sp->hostKeyOn() ) $relatives = $this->recordedRootCopies($name);
		else $relatives = $this->sp->index()->hasFile($relative) ? array($relative) : array();
		if( !count($relatives) ) return false;
		if( $this->baseDirRefuses("$log: the copy") ) return false;
		$gone = false;
		foreach( $relatives as $one ){
			$which = $one === $relative ? '' : " $one";
			if( $this->sp->index()->dropIndexRows( array( md5($one) ) ) ){
				$deleted = $this->deleteStaticFiles( array($one), false, false, array($name) );
				if( $deleted['files'] > 0 ) $gone = true;
				$this->sp->logDrain( "$log: the copy$which " . ( $deleted['files'] > 0 ? 'was deleted' : 'was not there or could not be deleted' ) );
			}
			else $this->sp->logDrain( "$log$which: the index row could not be dropped, the copy is kept" );
		}
		clearstatcache();
		return $gone;
	}

	/**
	 * The recorded copies of the root file $name: '/<name>' when the index has it, and with
	 * "Static files per host" every '/<host>/<name>' of the files table, found by its path, so a
	 * host since removed from $config->httpHosts is found as well [decided by user, Q38 C (b)].
	 * Index tables assumed; a failed query finds no host copy, logged
	 *
	 * #pw-internal
	 *
	 * @param string $name
	 * @return array Static root relative paths
	 *
	 */
	public function recordedRootCopies($name){
		$name = (string) $name;
		$files = array();
		if( $this->sp->index()->hasFile("/$name") ) $files[] = "/$name";
		if( !$this->sp->hostKeyOn() ) return $files;
		try {
			$query = $this->wire()->database->prepare('SELECT path FROM `' . StaticPages::tableFiles . "` WHERE path LIKE :like ESCAPE '!'");
			$query->bindValue( ':like', '/%/' . str_replace( array('!', '%', '_'), array('!!', '!%', '!_'), $name ) );
			$query->execute();
			foreach( $query->fetchAll(\PDO::FETCH_COLUMN) as $path ){
				if( $this->isHostRootCopy($path, $name) && !in_array( $path, $files, true ) ) $files[] = $path;
			}
			$query->closeCursor();
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( "could not look up the host copies of $name: " . $e->getMessage() );
			if( $this->sp->index()->isMissingTable($e) ) $this->sp->index()->tablesFailed($e);
		}
		return $files;
	}

	/** #pw-internal */
	public function isHostRootCopy($relative, $name){
		//$relative is '/<host>/<name>', <host> one dir name a host may be stored under
		$parts = explode( '/', (string) $relative );
		return count($parts) === 3 && $parts[0] === '' && $parts[2] === (string) $name && $this->sp->isHostDir($parts[1]);
	}

////  protected dirs

	public function defaultNonStaticDirs(){
		//the site root's own dirs (site, wire, api...) minus the dirs that only hold static
		//copies of pages. Every root dir used to be listed, and once static copies existed
		//that took in the page dirs too (/about/, /en/): their copies were then never wiped
		//and went stale
		$root = $this->sp->rootPath();
		if( $root === '' || !is_dir($root) || !is_readable($root) ) return array();

		$pageDirs = $this->rootPageDirs();
		$dirs = array();
		try {
			foreach( new \DirectoryIterator($root) as $fileInfo ){
				if( $fileInfo->isDot() || !$fileInfo->isDir() ) continue;
				$name = $fileInfo->getFilename();
				if( $this->isStaticCopyDir($name, $pageDirs) ) continue;
				$dirs[] = $name;
			}
		}
		catch( \Throwable $e ){
			return array();
		}
		sort($dirs);
		return $dirs;
	}

	public function rootPageDirs(){
		//names of the first level page dirs, in every language: the children of the home
		//page, and the home page itself where a language has a name for it (/en/)
		$names = array();
		try {
			$home = $this->wire()->pages->get('/');
			if( !$home->id ) return $names;
			$languages = $this->wire()->modules->isInstalled('LanguageSupportPageNames') ? $this->wire()->languages : null;
			$pages = $home->children('include=all');
			foreach( $languages ?: array(null) as $language ){
				if( $language ){
					$homeName = $home->localName($language);
					if( strlen($homeName) && $homeName !== Pages::defaultRootName ) $names[$homeName] = true;
				}
				foreach( $pages as $page ){
					$name = $language ? $page->localName($language) : $page->name;
					if( strlen($name) ) $names[$name] = true;
				}
			}
		}
		catch( \Throwable $e ){}
		return $names;
	}

	public function isStaticCopyDir($name, array $pageDirs){
		//a root dir named like a page is that page's static copy dir, unless it holds
		//anything besides static copies: a page named like a code dir (an /api/ page next
		//to the api/ endpoints) must leave that dir protected. Static copies: the names of
		//scanFileNames() and their temporary names, the HTML ones alone while the switch is off
		if( !isset($pageDirs[$name]) ) return false;
		$names = $this->sp->scanFileNames();
		if( !count($names) ) return false;
		$dir = $this->sp->rootPath() . DIRECTORY_SEPARATOR . $name;
		if( is_link($dir) || !is_dir($dir) ) return false;
		$tmpGlobs = $this->tmpFileGlobs($names);
		try {
			$rii = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			foreach( $rii as $entry ){
				//a link inside is something nobody would put into a static copy
				if( $entry->isLink() ) return false;
				if( $entry->isDir() ) continue;
				$basename = $entry->getFilename();
				if( $this->matchesStaticName( $basename, $names, $tmpGlobs ) ) continue;
				return false;
			}
		}
		catch( \Throwable $e ){
			return false;
		}
		return true;
	}

	public function tmpFileGlobs(array $names){
		//the temporary name of each static name, in the same order
		return array_map( fn($name) => $this->sp->publisher()->tmpFileGlob($name), array_values($names) );
	}

	public function matchesStaticName($basename, array $names, array $tmpGlobs){
		//$basename is one of $names or matches one of the globs
		if( in_array( $basename, $names, true ) ) return true;
		foreach( $tmpGlobs as $glob ) if( fnmatch( $glob, $basename ) ) return true;
		return false;
	}

	public function warnPageDirsProtected($data){
		//a list saved before the default was fixed (or typed by hand) can still name page dirs
		if( !is_array($data) || !isset($data['nonStaticDirs']) ) return;
		$rootPageDirs = $this->rootPageDirs();
		$listed = $this->sp->linesToArray( $data['nonStaticDirs'] );
		$pageDirs = array_values( array_filter( $listed, fn($dir) => $this->isStaticCopyDir($dir, $rootPageDirs) ) );
		if( !count($pageDirs) ) return;
		$this->sp->warning( sprintf(
			$this->_('Non-static directories lists page directories: %s. Static copies inside them are never wiped and go stale; remove them from the list.'),
			implode(', ', $pageDirs)
			) );
	}

	public function isProtectedPath($dirPath, array $nonStaticDirs){
		//$dirPath is a listed dir or below one. Case insensitive on Windows, where the
		//filesystem is, and slashes either way in the list
		$windows = DIRECTORY_SEPARATOR === '\\';
		$dirPath = rtrim( $this->sp->normalizePath($dirPath), DIRECTORY_SEPARATOR );
		$root = rtrim( $this->sp->normalizePath( $this->sp->rootPath() ), DIRECTORY_SEPARATOR );
		if( $windows ){
			$dirPath = strtolower($dirPath);
			$root = strtolower($root);
		}
		foreach( $nonStaticDirs as $nonStaticDir ){
			$nonStaticDir = trim( $this->sp->normalizePath( (string) $nonStaticDir ), DIRECTORY_SEPARATOR . " \t" );
			if( $nonStaticDir === '' ) continue;
			$sample = $root . DIRECTORY_SEPARATOR . ( $windows ? strtolower($nonStaticDir) : $nonStaticDir );
			if( $dirPath === $sample ) return true;
			if( strpos( $dirPath, $sample . DIRECTORY_SEPARATOR ) === 0 ) return true;
		}
		return false;
	}

////  the full disk scan

	public function wipeScan($dryRun = false){
		//walks the site root for static files, finding the ones nothing recorded as well: forced
		//wipes, and installs with neither index nor manifest. Returns the counts, or null from a
		//shell, which counts nothing. $dryRun: the php walk lists what would go, nothing is
		//deleted, no row and no manifest line either

		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ){
			$this->sp->logDrain( 'full scan refused: Static file name is not a plain file name' );
			return $this->emptyResult($dryRun);
		}

		//the rows and the manifest stay as well: the files they name are still there
		if( $this->baseDirRefuses('the full scan') ) return $this->emptyResult($dryRun);

		//the listed root files the index records (robots.txt): no scan matches their names, they
		//go by their record, read before the rows are cleared. The original kept as .robots.txt
		//stays where it is, a wipe renames nothing
		$rootCopies = $this->recordedRootFiles();

		//every other path the index or the manifest knows, read before both are emptied: a copy
		//outside the scan targets (the home page with a URL segment) was left without its row.
		//Each path once, the root files' pass first; a manifest line the HTML name alone
		$recorded = array_values( array_diff( array_unique( array_values( $this->recordedFiles() ) ), $rootCopies ) );
		$manifestLines = array_values( array_diff( $this->manifestLines(), $rootCopies, $recorded ) );

		if( $dryRun ){
			$targets = $this->scanTargets();
			$result = $this->wipeScanPHP($targets, true);
			$result['leftAlone'] = $this->leftAlone;
			$byRecord = array();
			if( count($rootCopies) ) $byRecord[] = $this->deleteStaticFiles($rootCopies, true);
			if( count($recorded) ) $byRecord[] = $this->deleteStaticFiles($recorded, true);
			if( count($manifestLines) ) $byRecord[] = $this->deleteStaticFiles($manifestLines, true, true);
			return count($byRecord) ? $this->mergeDryRuns($result, $byRecord) : $result;
		}

		//rows first: a render recording while the scan runs keeps a row for a file the scan then
		//removes, which is harmless, and never the reverse
		if( $this->sp->index()->ensureTables() ) $this->sp->index()->clearRows();

		//empty rather than gone: a missing manifest is what sends the next wipe into a scan
		$manifest = $this->sp->manifestPath();
		if( is_file($manifest) ) @file_put_contents( $manifest, '', LOCK_EX );

		//rows gone first: a copy that can not be unlinked gets its row back
		$rootResult = count($rootCopies) ? $this->deleteStaticFiles($rootCopies) : null;
		if( count($recorded) ) $rootResult = $this->addCounts( $rootResult ?? $this->emptyResult(), $this->deleteStaticFiles($recorded) );
		if( count($manifestLines) ) $rootResult = $this->addCounts( $rootResult ?? $this->emptyResult(), $this->deleteStaticFiles($manifestLines, false, true) );

		$targets = $this->scanTargets();
		if( count($this->leftAlone) )
			$this->sp->logDrain( 'full scan left alone root dirs no list names, they are no static copies of pages: ' . implode(', ', $this->leftAlone) );

		//a protected entry naming a dir below the root level: only the php walk looks at every
		//dir on its way down, the shells are handed whole root dirs. None below a base directory
		$nested = false;
		foreach( $this->protectedDirs() as $nonStaticDir )
			if( strpbrk( trim( $nonStaticDir, '/\\' ), '/\\' ) !== false ) $nested = true;

		if( $this->sp->useShell && !$nested ){
			switch( PHP_OS_FAMILY ){
				case 'Windows':
					if( $this->wipeScanWindows($targets) ) return null;
				break;

				case 'Linux':
				case 'Darwin':
				case 'BSD':
				case 'Solaris':
					if( $this->wipeScanUnix($targets) ) return null;
				break;
			}
		}

		$result = $this->wipeScanPHP($targets);
		return $rootResult ? $this->addCounts($result, $rootResult) : $result;
	}

	public function recordedRootFiles(){
		//'/<name>' for each listed root file name the index has a row for. Empty without index
		//tables, with the switch off or the list empty
		if( !$this->sp->configValue('cacheNonHtml', 0) ) return array();
		$names = array_keys( $this->sp->rootFilesArray() );
		if( !count($names) || !$this->sp->index()->ensureTables() ) return array();
		$files = array();
		foreach( $names as $name ){
			//"Static files per host": every recorded /<host>/<name> as well [decided by user, Q38 C (b)]
			if( $this->sp->hostKeyOn() ){
				foreach( $this->recordedRootCopies($name) as $relative ) $files[] = $relative;
				continue;
			}
			$relative = '/' . $name;
			if( $this->sp->index()->hasFile($relative) ) $files[] = $relative;
		}
		return $files;
	}

	public function recordedFiles(){
		//hash => static root relative path of every indexed file, for the forced scan. Empty
		//without index tables or when the query fails: the scan still runs
		if( !$this->sp->index()->ensureTables() ) return array();
		try {
			return $this->sp->index()->indexedFiles(null);
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'full scan could not read the index: ' . $e->getMessage() );
			return array();
		}
	}

	public function manifestLines(){
		//the manifest's lines, each once; empty without a manifest
		$manifest = $this->sp->manifestPath();
		if( !is_file($manifest) ) return array();
		$lines = @file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return $lines === false ? array() : array_values( array_unique($lines) );
	}

	private function mergeDryRuns(array $scan, array $byRecord){
		//a dry run scan and the dry runs by record as one list: a path any of them lists once,
		//its file counted once, and the dirs worked out again for every file listed, as the
		//deletes and pruneDirs() would leave them. Files listed by record are never temporary
		//names, so each one the scan did not list adds one file
		$root = $this->sp->staticRoot();
		$files = array();
		$dirs = array();
		foreach( $scan['paths'] as $relative ){
			if( substr($relative, -1) === '/' ) $dirs[ rtrim( $root . $relative, '/' ) ] = true;
			else $files[$relative] = true;
		}
		foreach( $byRecord as $more ){
			foreach( $more['paths'] as $relative ){
				if( substr($relative, -1) === '/' || isset($files[$relative]) ) continue;
				$files[$relative] = true;
				$scan['files']++;
				$scan['bytes'] += (int) @filesize( $root . $relative );
			}
		}
		$deleted = array();
		foreach( array_keys($files) as $relative ){
			$deleted[ $this->sp->normalizePath( $root . $relative ) ] = true;
			$dirs[ dirname( $root . $relative ) ] = true;
		}
		$gone = $this->simulatePrune( array_keys($dirs), $this->sp->normalizePath($root), $deleted );
		$scan['paths'] = array_keys($files);
		foreach( $gone as $dir ) $scan['paths'][] = $this->relativePath($dir) . '/';
		$scan['dirs'] = count($gone);
		return $scan;
	}

	private function addCounts(array $result, array $more){
		//$more's files, bytes, dirs and dry run paths added to $result
		foreach( array('files', 'bytes', 'dirs') as $key ) $result[$key] += $more[$key];
		if( isset($more['paths']) ) $result['paths'] = array_merge( $result['paths'], $more['paths'] );
		return $result;
	}

	public function scanTargets(){
		//the root dirs a scan walks. Worked out now rather than taken from Non-static
		//directories alone: that list is a snapshot of the root from the last config save, and a
		//dir added since (vendor/, a hand made landing page) was in no list and lost every
		//index.html and every empty dir it had. A root dir is walked only when no list names
		//it, it is no link, and it is a static copy of a first level page: named like one, in
		//any language, and holding nothing but static files. Everything else is left alone.
		//Protected dirs are never entered, where every scan used to walk all of
		//site/assets/files only to skip each file in it

		$this->leftAlone = array();

		//a base directory: every dir in it, which is the base directory itself below its top
		//level. Its top level is the root level of wipeScanPHP() and the shells, the HTML name
		//alone [decided by user, Q34 B (a), D (a)]
		if( $this->sp->staticBaseDir() !== '' ) return $this->baseDirTargets();

		$root = $this->sp->rootPath();
		$windows = DIRECTORY_SEPARATOR === '\\';

		$protected = array();
		foreach( $this->sp->nonStaticDirsArray() as $nonStaticDir ){
			$name = trim( str_replace( '\\', '/', $nonStaticDir ), '/' );
			$protected[ $windows ? strtolower($name) : $name ] = true;
		}

		$rootReal = realpath($root);
		if( $rootReal === false ) return array();

		$pageDirs = $this->rootPageDirs();
		$targets = array();

		try {
			foreach( new \DirectoryIterator($root) as $entry ){

				if( $entry->isDot() || !$entry->isDir() ) continue;

				$name = $entry->getFilename();
				if( isset( $protected[ $windows ? strtolower($name) : $name ] ) ) continue;

				//a link, a junction among them, leads out of the site: nothing in there was
				//written by this module, and nothing in there is its to delete
				if( $entry->isLink() ) continue;
				$path = $root . DIRECTORY_SEPARATOR . $name;
				$real = realpath($path);
				if( $real === false || strcasecmp( $real, $rootReal . DIRECTORY_SEPARATOR . $name ) !== 0 ) continue;

				if( !$this->isStaticCopyDir($name, $pageDirs) ){
					$this->leftAlone[] = $name;
					continue;
				}

				$targets[] = $path;
			}
		}
		catch( \Throwable $e ){
			return array();
		}

		sort($targets);
		sort($this->leftAlone);
		return $targets;
	}

	public function baseDirTargets(){
		//the dirs directly in the base directory, links left out as in the site root
		$root = $this->sp->staticRoot();
		$rootReal = realpath($root);
		if( $rootReal === false || !is_dir($rootReal) ) return array();
		$targets = array();
		try {
			foreach( new \DirectoryIterator($root) as $entry ){
				if( $entry->isDot() || !$entry->isDir() || $entry->isLink() ) continue;
				$name = $entry->getFilename();
				$path = $root . DIRECTORY_SEPARATOR . $name;
				$real = realpath($path);
				if( $real === false || strcasecmp( $real, $rootReal . DIRECTORY_SEPARATOR . $name ) !== 0 ) continue;
				$targets[] = $path;
			}
		}
		catch( \Throwable $e ){
			return array();
		}
		sort($targets);
		return $targets;
	}

	public function leftAlone(){
		return $this->leftAlone;
	}

	public function wipeScanPHP(array $targets, $dryRun = false){
		//one walk per root dir: files on the way down, empty dirs afterwards, deepest first.
		//$dryRun: the same walk and the same checks, the result lists what would go

		$result = $this->emptyResult($dryRun);

		$root = $this->sp->staticRoot();
		$nonStaticDirs = $this->protectedDirs();
		//the root level (the site root's, or the base directory's): the HTML name and its temporary name alone, the home page is never
		//written as anything else and index.xml or robots.txt at the root is no copy of a page.
		//Inside the targets: every name of scanFileNames()
		$staticFile = $this->sp->staticFileName();
		$rootNames = array($staticFile);
		$rootGlobs = array( $this->sp->publisher()->tmpFileGlob() );
		$names = $this->sp->scanFileNames();
		$tmpGlobs = $this->tmpFileGlobs($names);
		$dirs = array();
		$deleted = array();

		$delete = function($path, array $names, array $tmpGlobs) use (&$result, &$deleted, $dryRun){
			$name = basename($path);
			//a temporary file left behind by a killed publish keeps its directory non-empty,
			//and an orphan directory holding no index.html is the 403
			$tmp = $this->matchesStaticName( $name, array(), $tmpGlobs );
			if( !$tmp && !in_array( $name, $names, true ) ) return;
			if( !$this->insideRoot($path) ) return;
			$size = (int) @filesize($path);
			if( $dryRun ){
				$result['paths'][] = $this->relativePath($path);
				$deleted[ $this->sp->normalizePath($path) ] = true;
			}
			else if( !@unlink($path) ) return;
			if( $tmp ) return;
			$result['files']++;
			$result['bytes'] += $size;
		};

		//the home page's copy, at root level
		try {
			foreach( new \FilesystemIterator( $root, \FilesystemIterator::SKIP_DOTS ) as $entry )
				if( $entry->isFile() ) $delete( $entry->getPathname(), $rootNames, $rootGlobs );
		}
		catch( \Throwable $e ){}

		foreach( $targets as $target ){

			$dirs[$target] = true;

			try {
				$rii = new \RecursiveIteratorIterator(
					new \RecursiveCallbackFilterIterator(
						new \RecursiveDirectoryIterator( $target, \FilesystemIterator::SKIP_DOTS ),
						//never into a link, never into a protected dir further down
						fn($entry) => !$entry->isDir() || ( !$entry->isLink() && !$this->isProtectedPath( $entry->getPathname(), $nonStaticDirs ) )
					),
					\RecursiveIteratorIterator::SELF_FIRST,
					\RecursiveIteratorIterator::CATCH_GET_CHILD
				);

				foreach( $rii as $entry ){
					if( $entry->isDir() ) $dirs[ $entry->getPathname() ] = true;
					else $delete( $entry->getPathname(), $names, $tmpGlobs );
				}
			}
			catch( \Throwable $e ){}

			//the directory handles are closed before any dir goes, Windows refuses an open one
			$rii = null;
		}

		if( $dryRun ){
			$gone = $this->simulatePrune( array_keys($dirs), $this->sp->normalizePath($root), $deleted );
			foreach( $gone as $dir ) $result['paths'][] = $this->relativePath($dir) . '/';
			$result['dirs'] = count($gone);
			return $result;
		}

		$result['dirs'] = $this->pruneDirs( array_keys($dirs), $this->sp->normalizePath($root) );

		return $result;
	}

	public function wipeScanUnix(array $targets){
		//one find for the root level files, one for everything below: -depth reaches a dir after
		//its contents, so a dir emptied by the -delete of its files is empty by the time -empty
		//looks at it, where the empty dir removal used to take a pass per nesting level.
		//-delete can not be combined with -prune: the protected dirs are left out by never
		//being named as a starting point. The names are plain file names, staticFileName()
		//sees to that: -name takes a pattern. Every argument goes through escapeshellarg().
		//False when the shell did not run (shell_exec in disable_functions, no shell to start):
		//the php walk takes over, where this used to die on an undefined function or report
		//a scan that never happened

		if( !function_exists('shell_exec') || !function_exists('escapeshellarg') ) return false;

		$output = @shell_exec( $this->unixScanCommand($targets) );

		return is_string($output) && strpos( $output, 'StaticPages-scan-done' ) !== false;
	}

	public function unixScanCommand(array $targets){
		//the shell line wipeScanUnix() runs, ending in a marker that tells it the shell ran

		//the root find: the HTML name and its temporary name, as wipeScanPHP(). The targets find:
		//every name of scanFileNames() and its temporary name, in that order; the switch off,
		//the same pair as the root find
		$match = '\\( -name ' . escapeshellarg( $this->sp->staticFileName() ) . ' -o -name ' . escapeshellarg( $this->sp->publisher()->tmpFileGlob() ) . ' \\)';

		$root = escapeshellarg( $this->sp->staticRoot() );
		$commands = array( "find $root -mindepth 1 -maxdepth 1 -type f $match -delete 2>/dev/null" );

		if( count($targets) ){
			$names = $this->sp->scanFileNames();
			$tmpGlobs = $this->tmpFileGlobs($names);
			$pairs = array();
			foreach( $names as $i => $name )
				$pairs[] = '-name ' . escapeshellarg($name) . ' -o -name ' . escapeshellarg( $tmpGlobs[$i] );
			$targetMatch = '\\( ' . implode( ' -o ', $pairs ) . ' \\)';
			$dirs = implode( ' ', array_map( 'escapeshellarg', $targets ) );
			$commands[] = "find $dirs -depth \\( -type f $targetMatch -delete \\) -o \\( -type d -empty -delete \\) 2>/dev/null";
		}

		$commands[] = 'echo StaticPages-scan-done';

		return implode( '; ', $commands );
	}

	public function wipeScanWindows(array $targets){
		//one powershell process instead of one per pass, fed on stdin: nothing to quote for
		//cmd.exe, no command line length limit, no execution policy in the way. Every path
		//travels base64 encoded, the script stays ascii whatever code page stdin is read in.
		//Walked by hand rather than with Get-ChildItem -Recurse, which follows junctions in
		//Windows PowerShell 5.1. False when it did not run: the php walk takes over

		if( !function_exists('proc_open') ) return false;

		$text = fn($value) => "([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('" . base64_encode( (string) $value ) . "')))";

		//one statement per line: powershell reading a script from stdin runs it line by line.
		//The root level: $name and $tmp, the HTML name and its temporary name, as wipeScanPHP().
		//Inside the targets: $names and $tmps, every name of scanFileNames() and its temporary name
		$names = $this->sp->scanFileNames();
		$lines = array(
			"\$ErrorActionPreference = 'SilentlyContinue'",
			'$name = ' . $text( $this->sp->staticFileName() ),
			'$tmp = ' . $text( $this->sp->publisher()->tmpFileGlob() ),
			'$names = @(' . implode( ', ', array_map( $text, $names ) ) . ')',
			'$tmps = @(' . implode( ', ', array_map( $text, $this->tmpFileGlobs($names) ) ) . ')',
			'function Wipe-Files([string]$dir, [bool]$all){ try { $files = [IO.Directory]::GetFiles($dir) } catch { return }; foreach($file in $files){ $n = [IO.Path]::GetFileName($file); $hit = $false; if($all){ foreach($x in $names){ if($n -eq $x){ $hit = $true } }; foreach($x in $tmps){ if($n -like $x){ $hit = $true } } } else { $hit = ($n -eq $name -or $n -like $tmp) }; if($hit){ try { [IO.File]::Delete($file) } catch {} } } }',
			'function Wipe-Tree([string]$dir){ try { $subs = [IO.Directory]::GetDirectories($dir) } catch { return }; foreach($sub in $subs){ if(-not ([IO.File]::GetAttributes($sub) -band [IO.FileAttributes]::ReparsePoint)){ Wipe-Tree $sub } }; Wipe-Files $dir $true; try { if([IO.Directory]::GetFileSystemEntries($dir).Length -eq 0){ [IO.Directory]::Delete($dir) } } catch {} }',
			'Wipe-Files ' . $text( $this->sp->staticRoot() ) . ' $false',
		);
		foreach( $targets as $target ) $lines[] = 'Wipe-Tree ' . $text($target);
		$lines[] = "Write-Output 'StaticPages-scan-done'";

		$process = @proc_open(
			'powershell -NoProfile -NonInteractive -Command -',
			array( 0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', 'NUL', 'w') ),
			$pipes
			);
		if( !is_resource($process) ) return false;

		fwrite( $pipes[0], implode( "\n", $lines ) . "\n\n" );
		fclose( $pipes[0] );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		proc_close($process);

		return strpos( $output, 'StaticPages-scan-done' ) !== false;
	}

	public function readableFilesize($bytes, $decimals = 2) {
		//floor() answers a float, and a float string offset is deprecated: the @ that hid the
		//notice is gone, the factor is an int and capped at the last unit
		$sz = 'BKMGTP';
		$bytes = max( 0, (int) $bytes );
		$factor = min( (int) floor( ( strlen( (string) $bytes ) - 1 ) / 3 ), strlen($sz) - 1 );
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . $sz[$factor];
	}

}
