<?php
/*
StaticPages: bringing an older install up to date
AT
18.10.26
*/

namespace ProcessWire;

/*
Sites switch to this version by having their module dir replaced, copies of 0.3.1 to 0.6.3
and the older "012" and "020" lineages among them. Core skips ___upgrade() when the module
file changes from .module to .module.php, and a site may never see a modules refresh at all,
so nothing here waits for one: run() is called after the response of the first admin request,
the first request that queued a save, or a CLI script, and does its work once. Every step is
safe to repeat; configVersion in the module config says it is done.

What the older versions left:
- config keys: rmDirPasses (until 0.6.4, gone), no priority (012, 020), the force wipe box
  stored checked, Non-static directories saved as a snapshot of the site root that took in
  the page dirs as well (every version before 0.6.3)
- static files on disk nothing recorded: 012 and 020 kept no manifest, and files cached
  before an upgrade to a manifest version were never listed
- table layouts: none before 0.7.0, see StaticPagesIndex::ensureTables()
*/

class StaticPagesMigration extends Wire {

	/** @var StaticPages */
	protected $sp;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function pending(){
		return (int) $this->sp->get('configVersion') < StaticPages::configVersion;
	}

	public function run(){
		//true when the migration ran now or had run before
		if( !$this->pending() ) return true;

		$index = $this->sp->index();
		$lock = $index->lockName('migrate');
		try {
			if( !$index->getLock($lock, 0) ) return false;
		}
		catch( \Throwable $e ){
			return false;
		}

		try {
			$modules = $this->wire()->modules;
			$data = $modules->getConfig( $this->sp->className() );
			if( !is_array($data) ) $data = array();

			//another request may have finished it while this one waited for nothing
			if( (int) ( isset($data['configVersion']) ? $data['configVersion'] : 0 ) >= StaticPages::configVersion ){
				$this->sp->set( 'configVersion', StaticPages::configVersion );
				return true;
			}

			$wiper = $this->sp->wiper();
			$pageDirs = $wiper->rootPageDirs();
			$root = $this->sp->rootPath();
			$notes = array();

			$isPageCopy = function($name) use ($wiper, $pageDirs, $root){
				//a root dir named like a first level page that holds nothing but static copies,
				//or is not there at all: listing it protects no file of anybody's
				if( !isset($pageDirs[$name]) ) return false;
				if( !file_exists( $root . DIRECTORY_SEPARATOR . $name ) ) return true;
				return $wiper->isStaticCopyDir($name, $pageDirs);
			};

			$new = $this->configChanges($data, $isPageCopy, $notes);

			//past the Modules::saveConfig hooks: a migration is no operator's config save, and a
			//hook reacting to the force wipe box must not see one
			$modules->configs->saveConfig( $this->sp->className(), $new );
			foreach( $data as $key => $value ) if( !array_key_exists($key, $new) ) $this->sp->remove($key);
			foreach( $new as $key => $value ) $this->sp->set($key, $value);

			foreach( $notes as $note ) $this->sp->logDrain( "upgrade: $note" );

			$adopted = $this->adoptStaticFiles();
			if( $adopted === null ) $this->sp->logDrain( 'upgrade: no index tables, static files on disk stay with the manifest and the full scan' );
			else if( $adopted ) $this->sp->logDrain( "upgrade: $adopted static file(s) on disk nothing had recorded are in the index now, the next save removes them" );

			$this->sp->logDrain( 'upgrade: config at version ' . StaticPages::configVersion );
			return true;
		}
		finally {
			try {
				$index->releaseLock($lock);
			}
			catch( \Throwable $e ){}
		}
	}

	public function configChanges(array $data, callable $isPageCopy, array &$notes = array()){
		//the stored config of any older version, brought to this one. Pure apart from asking
		//$isPageCopy about root dir names. Keys this module does not know are kept

		if( array_key_exists('rmDirPasses', $data) ){
			unset($data['rmDirPasses']);
			$notes[] = 'dropped rmDirPasses, every scan takes one pass since 0.7.0';
		}

		foreach( array('forceWipeOnModuleSave', 'wipeDryRun') as $key ){
			if( empty($data[$key]) ) continue;
			$data[$key] = 0;
			$notes[] = "cleared $key, it is a one shot action";
		}

		if( !isset($data['priority']) || trim( (string) $data['priority'] ) === '' ){
			$data['priority'] = (string) StaticPages::priorityDefault;
			$notes[] = 'stored priority ' . StaticPages::priorityDefault . ', none was stored (012 and 020 hooked at the default 100, 0.7 at 0)';
		}

		if( isset($data['nonStaticDirs']) && is_string($data['nonStaticDirs']) ){
			$kept = array();
			$dropped = array();
			foreach( preg_split( '/\R/', $data['nonStaticDirs'] ) as $line ){
				$name = trim( $line, "/\\ \t" );
				if( $name === '' ) continue;
				//only a plain root dir name can be a page dir; nested entries and lines holding
				//blanks are the operator's and stay as they are
				if( strpbrk($name, "/\\ \t") === false && $isPageCopy($name) ){
					$dropped[] = $name;
					continue;
				}
				$kept[] = trim($line);
			}
			if( count($dropped) ){
				$data['nonStaticDirs'] = implode( "\n", $kept );
				$notes[] = 'removed page dirs from Non-static directories, their static copies were never wiped: ' . implode( ', ', $dropped );
			}
		}

		$data['configVersion'] = StaticPages::configVersion;

		return $data;
	}

	public function adoptStaticFiles(){
		//records the static files a scan would find and nothing recorded, tagged '*': the next
		//drain of any kind removes them, as it removes the files a manifest listed. Walks only
		//what a full scan walks, see StaticPagesWiper::scanTargets(). null without index tables

		if( !$this->sp->index()->ensureTables() ) return null;

		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ) return 0;

		//the names a scan matches: index.xml and index.txt only while the switch is on
		$names = $this->sp->scanFileNames();
		$scan = $this->sp->wiper()->wipeScan(true);
		$known = array_flip( $this->sp->index()->indexedFiles(null) );

		$adopted = 0;
		foreach( $scan['paths'] as $relative ){
			if( substr($relative, -1) === '/' ) continue;
			if( !in_array( basename($relative), $names, true ) ) continue;
			if( isset($known[$relative]) ) continue;
			if( $this->sp->index()->recordStaticFile( $relative, 0, array('*') ) ) $adopted++;
		}
		return $adopted;
	}

}
