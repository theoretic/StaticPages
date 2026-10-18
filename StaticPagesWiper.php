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
*/

class StaticPagesWiper extends Wire {

	/** @var StaticPages */
	protected $sp;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function wipeIndexed($tags){
		//$tags null: every indexed file

		$files = $this->sp->index()->indexedFiles($tags);
		if( !count($files) ) return array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		//rows first, files second: a render recording the same path meanwhile leaves a row for a
		//file that is gone, which is harmless, and never a file that nothing knows about
		if( !$this->sp->index()->dropIndexRows( array_keys($files) ) )
			throw new WireException('the index rows could not be dropped, the queue is kept');

		return $this->deleteStaticFiles($files);
	}

	public function deleteStaticFiles(array $files){

		$result = array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		$root = $this->sp->rootPath();
		$nonStaticDirs = $this->sp->nonStaticDirsArray();
		$dirs = array();
		$failed = array();

		foreach( $files as $relative ){

			$relative = (string) $relative;

			//the index could have been tampered with, validating every entry before deletion
			if( strpos($relative, '..') !== false ) continue;
			$path = $root . $relative;
			if( basename($path) !== $this->sp->staticFile ) continue;
			if( !is_file($path) ) continue;
			$dirPath = dirname($path);
			if( $this->isProtectedPath($dirPath, $nonStaticDirs) ) continue;

			$size = (int) @filesize($path);
			if( !@unlink($path) ){
				$failed[] = $relative;
				continue;
			}

			$result['files']++;
			$result['bytes'] += $size;
			$dirs[$dirPath] = true;
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
		return "{$result['files']} static file(s) ($bytes) and {$result['dirs']} dir(s) deleted.";
	}

	public function wipeCachesManifest(){
		//no index tables only: deletes the files the manifest lists, no site root scanning.
		//The manifest is moved aside first and an empty one put in its place: renders appending
		//meanwhile land in the new one instead of being lost, and an empty manifest means
		//"nothing cached", where a missing one sent the next save into the full disk scan

		$manifest = $this->sp->manifestPath();
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
			if( @rmdir($dir) ) $deletedDirs++;
		}

		return $deletedDirs;
	}

	public function pruneEmptyDirs($dirPath, $rootNorm){
		//removes $dirPath if empty, then its empty ancestors up to the site root level

		$deletedDirs = 0;

		if( !is_dir($dirPath) ) return 0;
		$dirIsEmpty = !(new \FilesystemIterator($dirPath))->valid();
		if( !$dirIsEmpty ) return 0;
		rmdir($dirPath);
		$deletedDirs++;

		$parts = explode( DIRECTORY_SEPARATOR, $this->sp->normalizePath($dirPath) );
		foreach( $parts as $part ){
			array_pop($parts);
			$parentDirPath = implode( DIRECTORY_SEPARATOR, $parts );

			if( strlen($parentDirPath) <= strlen($rootNorm) ) continue;
			if( in_array( $parentDirPath, array('', '.', '..') ) ) continue;
			if( !is_dir($parentDirPath) ) continue;

			$dirIsEmpty = !(new \FilesystemIterator($parentDirPath))->valid();
			if( !$dirIsEmpty ) continue;
			rmdir($parentDirPath);
			$deletedDirs++;
		}

		return $deletedDirs;
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
			$home = $this->wire('pages')->get('/');
			if( !$home->id ) return $names;
			$languages = $this->wire('modules')->isInstalled('LanguageSupportPageNames') ? $this->wire('languages') : null;
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
		$dir = $this->sp->rootPath() . DIRECTORY_SEPARATOR . $name;
		if( is_link($dir) ) return false;
		try {
			$rii = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach( $rii as $entry ){
				if( $entry->isDir() ) continue; //an empty dir is a leaf
				$basename = $entry->getFilename();
				if( $basename === $this->sp->staticFile || fnmatch( $this->sp->publisher()->tmpFileGlob(), $basename ) ) continue;
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
		$dirPath = $this->sp->normalizePath($dirPath);
		$root = $this->sp->normalizePath( $this->sp->rootPath() );
		foreach( $nonStaticDirs as $nonStaticDir ){
			$sample = $root . DIRECTORY_SEPARATOR . $nonStaticDir;
			if( $dirPath === $sample ) return true;
			if( strpos( $dirPath, $sample . DIRECTORY_SEPARATOR ) === 0 ) return true;
		}
		return false;
	}

////  the full disk scan

	public function wipeScan(){
		//walks the site root for static files, finding the ones nothing recorded as well: forced
		//wipes, and installs with neither index nor manifest. Returns the counts, or null from a
		//shell, which counts nothing

		//rows first: a render recording while the scan runs keeps a row for a file the scan then
		//removes, which is harmless, and never the reverse
		if( $this->sp->index()->ensureTables() ) $this->sp->index()->clearRows();

		//empty rather than gone: a missing manifest is what sends the next wipe into a scan
		$manifest = $this->sp->manifestPath();
		if( is_file($manifest) ) @file_put_contents( $manifest, '', LOCK_EX );

		$targets = $this->scanTargets();

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
					$this->wipeScanUnix($targets);
					return null;
			}
		}

		return $this->wipeScanPHP($targets);
	}

	public function scanTargets(){
		//the root dirs a scan walks: all but the protected ones and the links. Protected dirs are
		//never entered, where every scan used to walk all of site/assets/files only to skip each
		//file in it, and the shells did it once per pass

		$root = $this->sp->rootPath();
		$windows = DIRECTORY_SEPARATOR === '\\';

		$protected = array();
		foreach( $this->sp->nonStaticDirsArray() as $nonStaticDir ){
			$name = trim( str_replace( '\\', '/', $nonStaticDir ), '/' );
			$protected[ $windows ? strtolower($name) : $name ] = true;
		}

		$rootReal = realpath($root);
		if( $rootReal === false ) return array();

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

				$targets[] = $path;
			}
		}
		catch( \Throwable $e ){
			return array();
		}

		sort($targets);
		return $targets;
	}

	public function wipeScanPHP(array $targets){
		//one walk per root dir: files on the way down, empty dirs afterwards, deepest first

		$result = array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		$root = $this->sp->rootPath();
		$nonStaticDirs = $this->sp->nonStaticDirsArray();
		$tmpGlob = $this->sp->publisher()->tmpFileGlob();
		$dirs = array();

		$delete = function($path) use (&$result, $tmpGlob){
			$name = basename($path);
			//a temporary file left behind by a killed publish keeps its directory non-empty,
			//and an orphan directory holding no index.html is the 403
			if( fnmatch( $tmpGlob, $name ) ){
				@unlink($path);
				return;
			}
			if( $name !== $this->sp->staticFile ) return;
			$size = (int) @filesize($path);
			if( !@unlink($path) ) return;
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

		$result['dirs'] = $this->pruneDirs( array_keys($dirs), $this->sp->normalizePath($root) );

		return $result;
	}

	public function wipeScanUnix(array $targets){
		//one find for the root level files, one for everything below: -depth reaches a dir after
		//its contents, so a dir emptied by the -delete of its files is empty by the time -empty
		//looks at it, where the empty dir removal used to take a pass per nesting level.
		//-delete can not be combined with -prune: the protected dirs are left out by never
		//being named as a starting point

		$match = '\\( -name ' . escapeshellarg( $this->sp->staticFile ) . ' -o -name ' . escapeshellarg( $this->sp->publisher()->tmpFileGlob() ) . ' \\)';

		$root = escapeshellarg( $this->sp->rootPath() );
		shell_exec( "find $root -mindepth 1 -maxdepth 1 -type f $match -delete 2>/dev/null" );

		if( !count($targets) ) return;

		$dirs = implode( ' ', array_map( 'escapeshellarg', $targets ) );
		shell_exec( "find $dirs -depth \\( -type f $match -delete \\) -o \\( -type d -empty -delete \\) 2>/dev/null" );
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
			'$name = ' . $text( $this->sp->staticFile ),
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
		$sz = 'BKMGTP';
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
	}

}
