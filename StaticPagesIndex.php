<?php
/*
StaticPages: the dependency index
AT
18.10.26
*/

namespace ProcessWire;

/*
What a render depends on, what a save invalidates, and the three tables recording it.

Tags, one string each:
  p:<id>     the render loaded page <id>, or the page is the rendered one or its ancestor
  t:<id>     the render ran a find limited to template <id>
  par:<id>   ... limited to children of page <id>
  tree:<id>  ... limited to descendants of page <id>
  any        ... with none of those limits
  *          dependencies unknown: removed by every drain

A save of a page P invalidates p:P, which covers every file showing P: whatever showed it
loaded it. A find may also gain or lose P without having loaded it: a post added, published,
or edited into "featured=1" or into the latest five. So a save of a visible page also
invalidates the tags a find able to return P was given: t:<P's template>, par:<P's parent>,
tree:<each ancestor of P>, any. Before and after a move or a template change.
*/

class StaticPagesIndex extends Wire {

	/** @var StaticPages */
	protected $sp;

	//dependency tags collected while the front end renders, tag => true. See collecting
	protected $deps = array();
	protected $collecting = false;

	//set while this module itself asks the API for pages, keeps pagesFound() out of it
	protected $resolving = false;

	//null until first needed, then true or false for the rest of the request
	protected $tablesReady = null;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

////  what a render depends on, what a save invalidates

	public function collect($on){
		//switched on from init() for every request that may be cached, off from ready() for
		//those that are not: what was collected so far is dropped
		$this->collecting = (bool) $on;
		if( !$this->collecting ) $this->deps = array();
	}

	public function pageLoaded(Page $page){
		if( !$this->collecting ) return;
		$id = (int) $page->id;
		if( $id ) $this->deps["p:$id"] = true;
	}

	public function pagesFound($items){
		//a find tells which pages a render showed, and through its selector which pages it would
		//show once they exist: a post added later belongs in the listing that searched for posts
		if( !$this->collecting || $this->resolving ) return;

		//pages already in memory are handed back without being loaded again
		foreach( $items as $item ) $this->deps["p:{$item->id}"] = true;

		$this->resolving = true;
		try {
			foreach( $this->selectorTags( $items->getSelectors(), $items->count() ) as $tag )
				$this->deps[$tag] = true;
		}
		catch( \Throwable $e ){
			$this->deps['any'] = true;
		}
		finally {
			$this->resolving = false;
		}
	}

	public function selectorTags($selectors, $count){
		//the tags of one find: one constraint is enough, a page has to meet every one of them to
		//enter the results, and the most selective one is used. Whatever is not understood
		//counts as no constraint, which only ever invalidates more

		if( !$selectors instanceof Selectors ) return array('any');

		$found = array('parent' => array(), 'template' => array(), 'tree' => array());
		$specific = false;

		foreach( $selectors as $selector ){

			if( !$selector instanceof Selector ) continue;
			if( $selector->not || $selector->operator() !== '=' ) continue;

			$fields = $selector->fields();
			if( count($fields) !== 1 ) continue;
			$field = (string) reset($fields);

			$values = $selector->values(true);
			if( !count($values) ) continue;

			switch( $field ){
				case 'id':
					//a page asked for by id is loaded and tagged p: if it exists, and a page
					//created later never gets that id
					$specific = true;
					continue 2;

				case 'path':
				case 'name':
					//the same, unless there was no such page yet
					if( $count ) $specific = true;
					continue 2;

				case 'parent':
				case 'parent_id':
					$key = 'parent';
					break;

				case 'has_parent':
					$key = 'tree';
					break;

				case 'template':
				case 'templates_id':
					$key = 'template';
					break;

				default:
					continue 2;
			}

			$ids = array();
			foreach( $values as $value ){
				$id = $key === 'template' ? $this->resolveTemplateId($value) : $this->resolvePageId($value);
				//one value not understood would make the constraint look narrower than it is
				if( !$id ) continue 2;
				$ids[] = $id;
			}
			$found[$key] = array_merge( $found[$key], $ids );
		}

		foreach( array('parent' => 'par:', 'template' => 't:', 'tree' => 'tree:') as $key => $prefix ){
			if( !count($found[$key]) ) continue;
			return array_map( fn($id) => $prefix . $id, array_values( array_unique($found[$key]) ) );
		}

		return $specific ? array() : array('any');
	}

	public function resolveTemplateId($value){
		$value = trim( (string) $value );
		$template = $this->wire()->templates->get( ctype_digit($value) ? (int) $value : $value );
		return $template instanceof Template ? (int) $template->id : 0;
	}

	public function resolvePageId($value){
		//ids and paths, the forms a selector string carries a page in
		$value = trim( (string) $value );
		if( ctype_digit($value) ) return (int) $value;
		if( $value === '' || $value[0] !== '/' ) return 0;
		return (int) $this->wire()->pages->getByPath( $value, array('getID' => true, 'useLanguages' => true, 'allowPartial' => false) );
	}

	public function renderTags(Page $page){
		//everything the request collected, for the file cachePage() just wrote

		$tags = $this->deps;

		//pages loaded before collecting started, by modules initialised before this one
		foreach( array_keys( (array) $this->wire()->pages->getCache() ) as $id ) $tags['p:' . (int) $id] = true;

		//the page and its ancestors: a breadcrumb needs no find, and a rename or a move of an
		//ancestor changes this url
		for( $p = $page, $i = 0; $p && $p->id && $i < 100; $p = $p->parent, $i++ ) $tags["p:{$p->id}"] = true;

		unset( $tags['p:0'] );

		if( count($tags) > StaticPages::maxTags ) return array('*');

		return array_keys($tags);
	}

	public function invalidatePage(Page $page, array $changes, $depth = 0){

		if( !$page->id ) return;

		//a repeater item is part of the content of the page owning it, and of nothing else
		if( $page instanceof RepeaterPage ){
			$this->sp->queue()->enqueue( StaticPages::modeTags, array("p:{$page->id}") );
			$owner = $page->getForPage();
			if( $depth < 10 && $owner instanceof Page && $owner->id && $owner->id !== $page->id )
				return $this->invalidatePage( $owner, $changes, $depth + 1 );
			return;
		}

		if( $this->wipesAll($page, $changes) ) return $this->sp->queue()->enqueue(StaticPages::modeAll);

		$tags = array("p:{$page->id}");

		//a page nobody could see before the save and nobody sees after it is in no find result
		//a guest render ran: an unpublished draft is edited for free. A system template (users,
		//roles, the admin) is listed by nothing a guest sees
		$structural = array_intersect( $changes, array('status', 'parent', 'parent_id', 'template', 'templates_id', 'sort') );
		$hidden = $page->isUnpublished() || $page->isTrash();
		$template = $page->template;
		$system = $template instanceof Template && ( $template->flags & Template::flagSystem );

		if( !$system && ( !$hidden || count($structural) ) )
			$tags = array_merge( $tags, $this->listingTags($page) );

		//a new name or parent changes the url of every descendant, and whatever links to one of
		//them loaded it
		if( $this->movesUrl($page, $changes) ){
			$descendants = $this->descendantTags($page);
			if( $descendants === null ) return $this->sp->queue()->enqueue(StaticPages::modeAll);
			$tags = array_merge( $tags, $descendants );
		}

		return $this->sp->queue()->enqueue( StaticPages::modeTags, $tags );
	}

	public function listingTags(Page $page){
		//the tags of every find $page may have entered or left, see the note above selectorTags()

		$tags = array('any');

		foreach( array( $page->template, $page->templatePrevious ) as $template )
			if( $template instanceof Template ) $tags[] = "t:{$template->id}";

		foreach( array( $page->parent, $page->parentPrevious ) as $parent ){
			if( !$parent instanceof Page || !$parent->id ) continue;
			$tags[] = "par:{$parent->id}";
			for( $ancestor = $parent, $i = 0; $ancestor && $ancestor->id && $i < 100; $ancestor = $ancestor->parent, $i++ )
				$tags[] = "tree:{$ancestor->id}";
		}

		return $tags;
	}

	public function movesUrl(Page $page, array $changes){
		if( (int) $page->numChildren < 1 ) return false;
		foreach( $changes as $change ){
			$change = (string) $change;
			//name1012 and the like: the page name in another language
			if( $change === 'parent' || $change === 'parent_id' || preg_match( '/^name\d*$/', $change ) ) return true;
		}
		return false;
	}

	public function descendantTags(Page $page){
		//null when there are too many to list: everything goes then
		$this->resolving = true;
		try {
			$ids = $this->wire()->pages->findIDs( "has_parent={$page->id}, include=all, limit=" . ( StaticPages::maxDescendants + 1 ) );
		}
		finally {
			$this->resolving = false;
		}
		if( count($ids) > StaticPages::maxDescendants ) return null;
		return array_map( fn($id) => 'p:' . (int) $id, $ids );
	}

	public function wipesAll(Page $page, array $changes){
		//what no render-time record can follow: languages are loaded before this module's
		//init(), and the operator names templates and fields whose content ends up everywhere

		$template = $page->template;
		if( !$template instanceof Template ) return true;
		if( $template->name === 'language' ) return true;

		$templates = array_map( 'intval', (array) $this->sp->wipeAllTemplates );
		if( in_array( (int) $template->id, $templates, true ) ) return true;

		$fields = array_map( 'strtolower', $this->sp->linesToArray( $this->sp->configValue('wipeAllFields', '') ) );
		if( !count($fields) ) return false;
		$changed = array_map( fn($change) => strtolower( (string) $change ), $changes );
		return count( array_intersect($fields, $changed) ) > 0;
	}

////  the index

	public function ensureTables(){
		//created when first needed: an install upgraded from the manifest has no install step to
		//create them in, and neither has a site sharing this module dir through a link. Brought
		//up to schemaVersion when older, see upgradeSchema(). InnoDB whatever $config->dbEngine
		//says, a record is a transaction.
		//False when they can not be created or migrated (no CREATE or ALTER privilege), or when a
		//newer module version left them in a layout this one does not know: the module falls
		//back to the manifest and to wiping everything on every save

		if( $this->tablesReady !== null ) return $this->tablesReady;
		$this->tablesReady = false;

		//a failure is tried again an hour later, not on every render in between, each of them
		//adding a log line and failing queries
		$failed = $this->wire()->config->paths->cache . 'StaticPages/tables.failed';
		if( is_file($failed) && time() - (int) @filemtime($failed) < 3600 ) return false;

		try {
			$this->createTables();
			$version = $this->readSchemaVersion();
			if( $version < StaticPages::schemaVersion ) $version = $this->upgradeSchema();
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'index tables unavailable, every save wipes everything: ' . $e->getMessage() );
			if( $this->sp->trackedHashDir() !== '' ) @touch($failed);
			return false;
		}

		//another request is migrating them right now: this one makes do without the index
		if( $version === null ) return false;

		//written by a newer module version, then the module went back: this code must not write
		//into a layout it does not know. The first save scans the disk, which finds the files the
		//index knew about as well
		if( $version > StaticPages::schemaVersion ){
			$this->sp->logDrain( "index tables have schema $version, this module version knows " . StaticPages::schemaVersion . ': every save wipes everything until the module is upgraded again' );
			if( $this->sp->trackedHashDir() !== '' ) @touch($failed);
			return false;
		}

		if( is_file($failed) ) @unlink($failed);
		$this->tablesReady = true;
		$this->importManifest();

		return true;
	}

	public function createTables(){
		//the current layout, for a site that has none yet. Tables already there are left alone,
		//upgradeSchema() takes care of those

		$database = $this->wire()->database;
		$charset = $this->wire()->config->dbCharset === 'utf8mb4' ? 'utf8mb4' : 'utf8';
		$options = "ENGINE=InnoDB DEFAULT CHARSET=$charset COMMENT='" . $this->schemaComment() . "'";

		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . StaticPages::tableFiles . '` (' .
				'path_hash BINARY(16) NOT NULL PRIMARY KEY, ' .
				'path TEXT NOT NULL, ' .
				'page_id INT UNSIGNED NOT NULL DEFAULT 0, ' .
				'created INT UNSIGNED NOT NULL DEFAULT 0' .
			") $options"
		);
		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . StaticPages::tableTags . '` (' .
				'tag VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ' .
				'path_hash BINARY(16) NOT NULL, ' .
				'PRIMARY KEY (tag, path_hash), ' .
				'KEY path_hash (path_hash)' .
			") $options"
		);
		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . StaticPages::tableQueue . '` (' .
				'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
				'mode TINYINT UNSIGNED NOT NULL, ' .
				'tags MEDIUMTEXT, ' .
				'created INT UNSIGNED NOT NULL DEFAULT 0, ' .
				'KEY created (created)' .
			") $options"
		);
	}

	public function schemaComment(){
		return 'StaticPages schema ' . StaticPages::schemaVersion;
	}

	public function readSchemaVersion(){
		//kept in the table comments rather than in the module config: it goes and comes back with
		//the tables themselves, a dropped table or a restored dump can not leave it saying
		//something else, and a front end request never writes module config. A table newer than
		//this code counts first, nothing may be written then. Otherwise the lowest of the three:
		//a migration cut short may have left some behind. 0 for a table without one, 0.7.0
		//wrote none

		$query = $this->wire()->database->prepare(
			'SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES ' .
			'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:files, :tags, :queue)'
			);
		$query->bindValue(':files', StaticPages::tableFiles);
		$query->bindValue(':tags', StaticPages::tableTags);
		$query->bindValue(':queue', StaticPages::tableQueue);
		$query->execute();
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		$query->closeCursor();

		if( count($rows) < 3 ) return 0;

		$versions = array();
		foreach( $rows as $row )
			$versions[] = preg_match( '/^StaticPages schema (\d+)$/', (string) $row['TABLE_COMMENT'], $match ) ? (int) $match[1] : 0;

		if( max($versions) > StaticPages::schemaVersion ) return max($versions);

		return min($versions);
	}

	public function upgradeSchema(){
		//one request at a time, two side by side would run the same ALTERs together. Returns the
		//version the tables are at afterwards, or null when another request holds the lock

		$lock = $this->lockName('schema');
		if( !$this->getLock($lock, 10) ) return null;

		try {
			//the request that held the lock before may have done it all already
			$from = $this->readSchemaVersion();
			if( $from >= StaticPages::schemaVersion ) return $from;

			$this->migrateSchema($from);

			//last, once every step went through: a migration cut short is repeated in full
			foreach( array(StaticPages::tableFiles, StaticPages::tableTags, StaticPages::tableQueue) as $table )
				$this->wire()->database->exec( "ALTER TABLE `$table` COMMENT = '" . $this->schemaComment() . "'" );

			$this->sp->logDrain( "index tables migrated from schema $from to " . StaticPages::schemaVersion );

			return StaticPages::schemaVersion;
		}
		finally {
			$this->releaseLock($lock);
		}
	}

	public function migrateSchema($from){
		//one case per schema version, falling through from the one the tables are at: each step
		//brings the tables from the version before it to its own. Every step must be safe to run
		//twice: a migration cut short (a killed process, a lost connection) is repeated from the
		//version stored, which is only written once all of them went through. MySQL 8 knows no
		//ADD COLUMN IF NOT EXISTS, ask information_schema.COLUMNS first. A change not worth
		//converting rows for may empty the tables instead, after wipeIndexed(null) removed every
		//file they list: the next render of each page records it again

		switch( (int) $from ){

			case 0:
				//0.7.0 created the tables without a version, in the layout of version 1

			//case 1: the step to version 2 goes here, and so on, without a break between them
		}
	}

	public function importManifest(){
		//files cached before the index existed, dependencies unknown: tagged '*', the first
		//drain of any kind removes them
		$manifest = $this->sp->manifestPath();
		if( !is_file($manifest) ) return;

		$lines = @file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if( $lines === false ) return;

		$imported = true;
		foreach( array_unique($lines) as $relative )
			if( !$this->recordStaticFile( $relative, 0, array('*') ) ) $imported = false;

		if( $imported ) @unlink($manifest);
	}

	public function recordStaticFile($relative, $pageId, array $tags){
		$database = $this->wire()->database;
		$hash = md5($relative);

		try {
			$database->beginTransaction();

			$query = $database->prepare('DELETE FROM `' . StaticPages::tableTags . '` WHERE path_hash = UNHEX(:hash)');
			$query->bindValue(':hash', $hash);
			$query->execute();

			$query = $database->prepare('REPLACE INTO `' . StaticPages::tableFiles . '` (path_hash, path, page_id, created) VALUES (UNHEX(:hash), :path, :page, :created)');
			$query->bindValue(':hash', $hash);
			$query->bindValue(':path', (string) $relative);
			$query->bindValue(':page', (int) $pageId, \PDO::PARAM_INT);
			$query->bindValue(':created', time(), \PDO::PARAM_INT);
			$query->execute();

			foreach( array_chunk( array_values( array_unique($tags) ), StaticPages::chunkSize ) as $chunk ){
				$query = $database->prepare(
					'INSERT IGNORE INTO `' . StaticPages::tableTags . '` (tag, path_hash) VALUES ' .
					implode( ', ', array_fill( 0, count($chunk), '(?, UNHEX(?))' ) )
					);
				$n = 1;
				foreach( $chunk as $tag ){
					$query->bindValue( $n++, (string) $tag );
					$query->bindValue( $n++, $hash );
				}
				$query->execute();
			}

			$database->commit();
			return true;
		}
		catch( \Throwable $e ){
			if( $database->inTransaction() ) $database->rollBack();
			$this->sp->logDrain( "could not record $relative: " . $e->getMessage() );
			return false;
		}
	}

	public function indexedFiles($tags){
		//hash => root relative path. $tags null: every indexed file. Otherwise the files
		//carrying one of $tags, and the '*' ones

		$database = $this->wire()->database;
		$files = array();

		if( $tags === null ){
			$query = $database->prepare('SELECT HEX(path_hash) AS hash, path FROM `' . StaticPages::tableFiles . '`');
			$query->execute();
			foreach( $query->fetchAll(\PDO::FETCH_ASSOC) as $row ) $files[ strtolower($row['hash']) ] = $row['path'];
			return $files;
		}

		$tags[] = '*';

		foreach( array_chunk( array_values( array_unique($tags) ), StaticPages::chunkSize ) as $chunk ){
			$query = $database->prepare(
				'SELECT HEX(f.path_hash) AS hash, f.path FROM `' . StaticPages::tableFiles . '` f ' .
				'WHERE f.path_hash IN (SELECT t.path_hash FROM `' . StaticPages::tableTags . '` t WHERE t.tag IN (' .
				implode( ', ', array_fill( 0, count($chunk), '?' ) ) . '))'
				);
			foreach( $chunk as $n => $tag ) $query->bindValue( $n + 1, (string) $tag );
			$query->execute();
			foreach( $query->fetchAll(\PDO::FETCH_ASSOC) as $row ) $files[ strtolower($row['hash']) ] = $row['path'];
		}

		return $files;
	}

	public function dropIndexRows(array $hashes){
		try {
			foreach( array_chunk( array_values($hashes), StaticPages::chunkSize ) as $chunk ){
				$in = implode( ', ', array_fill( 0, count($chunk), 'UNHEX(?)' ) );
				foreach( array(StaticPages::tableTags, StaticPages::tableFiles) as $table ){
					$query = $this->wire()->database->prepare("DELETE FROM `$table` WHERE path_hash IN ($in)");
					foreach( $chunk as $n => $hash ) $query->bindValue( $n + 1, (string) $hash );
					$query->execute();
				}
			}
			return true;
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'could not drop index rows: ' . $e->getMessage() );
			return false;
		}
	}

////  locks, shared with the drain

	public function lockName($purpose){
		//GET_LOCK names are server wide and at most 64 characters: one per database, so two
		//sites sharing a server do not wait for each other
		$config = $this->wire()->config;
		return "StaticPages.$purpose." . md5( $config->dbHost . '|' . $config->dbPort . '|' . $config->dbName );
	}

	public function getLock($name, $wait){
		$query = $this->wire()->database->prepare('SELECT GET_LOCK(:name, :wait)');
		$query->bindValue(':name', $name);
		$query->bindValue(':wait', (int) $wait, \PDO::PARAM_INT);
		$query->execute();
		$locked = (int) $query->fetchColumn() === 1;
		$query->closeCursor();
		return $locked;
	}

	public function releaseLock($name){
		$query = $this->wire()->database->prepare('SELECT RELEASE_LOCK(:name)');
		$query->bindValue(':name', $name);
		$query->execute();
		$query->closeCursor();
	}

	public function tablesFailed(){
		//a query on the tables failed after all: the rest of the request makes do without them
		$this->tablesReady = false;
	}

	public function clearRows(){
		//every file row and every tag row, the queue stays: a full scan is about to remove the files
		$database = $this->wire()->database;
		$database->exec( 'DELETE FROM `' . StaticPages::tableTags . '`' );
		$database->exec( 'DELETE FROM `' . StaticPages::tableFiles . '`' );
	}

	public function dropTables(){
		$database = $this->wire()->database;
		foreach( array(StaticPages::tableFiles, StaticPages::tableTags, StaticPages::tableQueue) as $table )
			$database->exec("DROP TABLE IF EXISTS `$table`");
	}

}
