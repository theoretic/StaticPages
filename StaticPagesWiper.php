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
static copies of pages, worked out when it runs: see scanTargets().
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

	public function deleteStaticFiles(array $files, $dryRun = false){
		//$dryRun: nothing is deleted, the result lists what would go under 'paths', root
		//relative, dirs with a trailing slash

		$result = $this->emptyResult($dryRun);

		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ) return $result;

		$root = $this->sp->rootPath();
		$nonStaticDirs = $this->sp->nonStaticDirsArray();
		$dirs = array();
		$failed = array();
		$deleted = array();

		foreach( $files as $relative ){

			$relative = (string) $relative;

			//the index could have been tampered with, validating every entry before deletion
			if( $relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false ) continue;
			$path = $root . $relative;
			if( basename($path) !== $staticFile ) continue;
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
		//root relative, forward slashes, leading slash: the form the index and the log use
		$root = rtrim( str_replace( '\\', '/', $this->sp->rootPath() ), '/' );
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

		if( $dryRun ){
			$lines = @file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			return $this->deleteStaticFiles( array_unique( $lines === false ? array() : $lines ), true );
		}

		$taken = $manifest . '.' . getmypid() . '.wipe';
		if( !@rename($manifest, $taken) ) return array('files' => 0, 'bytes' => 0, 'dirs' => 0);
		if( !is_file($manifest) ) @touch($manifest);

		$lines = @file( $taken, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		@unlink($taken);
		if( $lines === false ) $lines = array();

		return $this->deleteStaticFiles( array_unique($lines) );
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
		//strictly below the site root to be deleted
		$rootReal = realpath( $this->sp->rootPath() );
		$real = realpath($path);
		if( $rootReal === false || $real === false ) return false;
		$prefix = rtrim( $rootReal, '/\\' ) . DIRECTORY_SEPARATOR;
		if( strlen($real) <= strlen($prefix) ) return false;
		if( DIRECTORY_SEPARATOR === '\\' ) return strncasecmp( $real, $prefix, strlen($prefix) ) === 0;
		return strncmp( $real, $prefix, strlen($prefix) ) === 0;
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
		//to the api/ endpoints) must leave that dir protected
		if( !isset($pageDirs[$name]) ) return false;
		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ) return false;
		$dir = $this->sp->rootPath() . DIRECTORY_SEPARATOR . $name;
		if( is_link($dir) || !is_dir($dir) ) return false;
		$tmpGlob = $this->sp->publisher()->tmpFileGlob();
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
				if( $basename === $staticFile || fnmatch( $tmpGlob, $basename ) ) continue;
				return false;
			}
		}
		catch( \Throwable $e ){
			return false;
		}
		return true;
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

		if( $dryRun ){
			$targets = $this->scanTargets();
			$result = $this->wipeScanPHP($targets, true);
			$result['leftAlone'] = $this->leftAlone;
			return $result;
		}

		//rows first: a render recording while the scan runs keeps a row for a file the scan then
		//removes, which is harmless, and never the reverse
		if( $this->sp->index()->ensureTables() ) $this->sp->index()->clearRows();

		//empty rather than gone: a missing manifest is what sends the next wipe into a scan
		$manifest = $this->sp->manifestPath();
		if( is_file($manifest) ) @file_put_contents( $manifest, '', LOCK_EX );

		$targets = $this->scanTargets();
		if( count($this->leftAlone) )
			$this->sp->logDrain( 'full scan left alone root dirs no list names, they are no static copies of pages: ' . implode(', ', $this->leftAlone) );

		//a protected entry naming a dir below the root level: only the php walk looks at every
		//dir on its way down, the shells are handed whole root dirs
		$nested = false;
		foreach( $this->sp->nonStaticDirsArray() as $nonStaticDir )
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

		return $this->wipeScanPHP($targets);
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

	public function leftAlone(){
		return $this->leftAlone;
	}

	public function wipeScanPHP(array $targets, $dryRun = false){
		//one walk per root dir: files on the way down, empty dirs afterwards, deepest first.
		//$dryRun: the same walk and the same checks, the result lists what would go

		$result = $this->emptyResult($dryRun);

		$root = $this->sp->rootPath();
		$nonStaticDirs = $this->sp->nonStaticDirsArray();
		$staticFile = $this->sp->staticFileName();
		$tmpGlob = $this->sp->publisher()->tmpFileGlob();
		$dirs = array();
		$deleted = array();

		$delete = function($path) use (&$result, &$deleted, $tmpGlob, $staticFile, $dryRun){
			$name = basename($path);
			//a temporary file left behind by a killed publish keeps its directory non-empty,
			//and an orphan directory holding no index.html is the 403
			$tmp = fnmatch( $tmpGlob, $name );
			if( !$tmp && $name !== $staticFile ) return;
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
				if( $entry->isFile() ) $delete( $entry->getPathname() );
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
					else $delete( $entry->getPathname() );
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

		$match = '\\( -name ' . escapeshellarg( $this->sp->staticFileName() ) . ' -o -name ' . escapeshellarg( $this->sp->publisher()->tmpFileGlob() ) . ' \\)';

		$root = escapeshellarg( $this->sp->rootPath() );
		$commands = array( "find $root -mindepth 1 -maxdepth 1 -type f $match -delete 2>/dev/null" );

		if( count($targets) ){
			$dirs = implode( ' ', array_map( 'escapeshellarg', $targets ) );
			$commands[] = "find $dirs -depth \\( -type f $match -delete \\) -o \\( -type d -empty -delete \\) 2>/dev/null";
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

		//one statement per line: powershell reading a script from stdin runs it line by line
		$lines = array(
			"\$ErrorActionPreference = 'SilentlyContinue'",
			'$name = ' . $text( $this->sp->staticFileName() ),
			'$tmp = ' . $text( $this->sp->publisher()->tmpFileGlob() ),
			'function Wipe-Files([string]$dir){ try { $files = [IO.Directory]::GetFiles($dir) } catch { return }; foreach($file in $files){ $n = [IO.Path]::GetFileName($file); if($n -eq $name -or $n -like $tmp){ try { [IO.File]::Delete($file) } catch {} } } }',
			'function Wipe-Tree([string]$dir){ try { $subs = [IO.Directory]::GetDirectories($dir) } catch { return }; foreach($sub in $subs){ if(-not ([IO.File]::GetAttributes($sub) -band [IO.FileAttributes]::ReparsePoint)){ Wipe-Tree $sub } }; Wipe-Files $dir; try { if([IO.Directory]::GetFileSystemEntries($dir).Length -eq 0){ [IO.Directory]::Delete($dir) } } catch {} }',
			'Wipe-Files ' . $text( $this->sp->rootPath() ),
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
