<?php
/*
Static pages module
https://github.com/ryancramerdesign/Helloworld
AT
26.08.26
*/

namespace ProcessWire;

class StaticPages extends WireData implements Module, ConfigurableModule {

	//real properties, not config keys: WireData's magic __get/__set has no business here

	//no index tables only: "nothing new has been cached since the last wipe", see wipeLegacy()
	private $didWipe = false;

	//dependency tags collected while the front end renders, tag => true. See collecting
	private $deps = array();
	private $collecting = false;

	//set while this module itself asks the API for pages, keeps hookFound() out of it
	private $resolving = false;

	//the invalidation generation this request started under, see cachePage()
	private $genAtStart = '';
	private $genBumped = false;

	//null until first needed, then true or false for the rest of the request
	private $tablesReady = null;

	//what this request queued already: a bulk save loop must not insert the same tags again
	private $queuedTags = array();
	private $queuedMode = 0;
	private $drainRequested = false;
	private $adminRequest = false;

	//the dependency index, see ensureTables()
	const tableFiles = 'static_pages_files';
	const tableTags = 'static_pages_tags';
	const tableQueue = 'static_pages_queue';

	//the layout of those tables. Raised with every change to it, each raise brings a step in
	//migrateSchema(). Stored in the table comments, see readSchemaVersion()
	const schemaVersion = 1;

	//queue modes, a higher one covers every lower one
	const modeTags = 1;
	const modeAll = 2;
	const modeScan = 3;

	//a file depending on more pages than this is tagged '*', removed by every drain:
	//thousands of rows per file would cost more than re-rendering it
	const maxTags = 5000;

	//a renamed or moved page with more descendants than this wipes everything, their urls all changed
	const maxDescendants = 5000;

	//seconds before a queue row nobody drained counts as left behind by a killed process
	const orphanAge = 30;

	//seconds a drain waits for another one to finish. It runs after the response, waiting is free
	const lockWait = 30;

	//placeholders per IN () list
	const chunkSize = 500;

	//the default set of tracked path patterns, kept here so the module and
	//StaticPages.config.php can not drift apart
	const trackedPathsDefault = "site/templates/**/*.php\nsite/assets/css/*.css\nsite/assets/js/**/*.js";

	//seconds between two walks of the tracked paths. The admin fires a lot of ajax
	//(page tree, autocomplete, inline edits) and each of those requests would otherwise
	//stat every tracked file again. 0 checks on every admin request
	const trackingInterval = 10;

	//RecursiveDirectoryIterator will not descend into a symlinked dir unless
	//FOLLOW_SYMLINKS is passed, this cap catches what a platform fails to report as a link
	const trackingMaxDepth = 20;

	public function init() {
		$this->addHookAfter('Modules::saveConfig', $this, 'hookAfterSaveConfig');

		if( !$this->isEnabled ) return;

		$this->genAtStart = $this->readGen();

		//every page a render loads is a dependency of the file that render produces: recording
		//them costs a hash write per load, working them out at save time would cost more than
		//the wipe. Collecting from init() rather than ready(), so pages other modules load in
		//their ready() are seen too; ready() stops it for requests that are never cached.
		//A CLI script would only grow the set
		$this->collecting = PHP_SAPI !== 'cli';
		$this->addHookAfter('Page::loaded', $this, 'hookLoaded');
		$this->pages->addHookAfter('found', $this, 'hookFound');

		$this->addHookAfter('Page::render', $this, 'hookCache', array('priority' => (int) $this->priority));

		//after the fact, with the changed field names: a save changing nothing removes nothing
		$this->pages->addHookAfter('saved', $this, 'hookSaved');
		$this->pages->addHookAfter('deleted', $this, 'hookDeleted');
		$this->pages->addHookAfter('sorted', $this, 'hookSorted');
		//descendants have their trash status set in SQL, no save and no hook reaches them
		$this->pages->addHookAfter('trashed', $this, 'hookTrashed');
		$this->pages->addHookAfter('restored', $this, 'hookTrashed');

		//a single field save never reaches Pages::saved, inline admin edits and setAndSave() would leave stale files
		if( $this->configValue('wipeOnSaveField', 1) ) $this->pages->addHookAfter('savedField', $this, 'hookSavedField');

		//saves only queue what went stale, the files are removed once the response is out
		register_shutdown_function(array($this, 'shutdown'));
	}

	public function ready() {
		//autoload modules are handed ready() at ProcessWire::statusReady, reached right after
		//the requested page is in the API and one line after $config->admin was resolved,
		//all of it before Page::render runs

		if( !$this->isEnabled ) return;

		$this->adminRequest = $this->isAdminRequest();

		//nothing this request renders will be cached, what was collected so far is dropped
		if( !$this->isCacheableRequest() ){
			$this->collecting = false;
			$this->deps = array();
		}

		if( !$this->adminRequest ) return;

		//ModulesLoader::readyModule() catches Exception but not Error: an uncaught TypeError
		//in here would take down the whole admin, config screen included, leaving no way left
		//to correct the setting that caused it
		try {
			$this->checkTrackedPaths();
		}
		catch( \Throwable $e ){
			$this->error( 'StaticPages path tracking: ' . $e->getMessage() );
		}
	}

	public function ___install() {
		//an upgrade has no install step, ensureTables() creates them when first needed as well
		$this->ensureTables();
	}

	public function ___uninstall() {
		$database = $this->wire('database');
		foreach( array(self::tableFiles, self::tableTags, self::tableQueue) as $table )
			$database->exec("DROP TABLE IF EXISTS `$table`");
	}

	public function hookCache($event) {
		return $this->cachePage($event);
	}

	public function hookLoaded($event) {
		if( !$this->collecting ) return;
		$id = (int) $event->object->id;
		if( $id ) $this->deps["p:$id"] = true;
	}

	public function hookFound($event) {
		//a find tells which pages a render showed, and through its selector which pages it would
		//show once they exist: a post added later belongs in the listing that searched for posts
		if( !$this->collecting || $this->resolving ) return;

		$items = $event->arguments(0);

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

	public function hookSaved($event) {
		$changes = (array) $event->arguments(1);
		//Pages::saved fires for a save that changed nothing as well
		if( !count($changes) ) return;
		return $this->invalidatePage( $event->arguments(0), $changes );
	}

	public function hookSavedField($event) {
		$field = $event->arguments(1);
		return $this->invalidatePage( $event->arguments(0), array( (string) ( $field instanceof Field ? $field->name : $field ) ) );
	}

	public function hookDeleted($event) {
		$page = $event->arguments(0);
		//emptying the trash: nobody saw these pages since they went in, see invalidatePage()
		return $this->invalidatePage( $page, $page->isTrash() ? array() : array('status') );
	}

	public function hookSorted($event) {
		return $this->invalidatePage( $event->arguments(0), array('sort') );
	}

	public function hookTrashed($event) {
		//the page itself was saved and handled by hookSaved(). Its descendants changed status in
		//SQL alone: no save, no hook, and a listing of them elsewhere never loaded the page
		if( (int) $event->arguments(0)->numChildren > 0 ) return $this->enqueue(self::modeAll);
	}

	public function hookAfterSaveConfig($event) {
		//should run in backend only!
		$class = $this->configSaveClassName( $event->arguments(0) );

		//settings of this module are being saved: an explicit, one shot operator action,
		//deliberately not gated on isEnabled so a disabled module can still be told to clean up
		if( $class === $this->className() ){
			$this->warnPageDirsProtected( $event->arguments(1) );
			if( !$this->wire('input')->post('forceWipeOnModuleSave') ) return;
			//full disk scan: also catches files the manifest does not know about
			return $this->wipeCaches(true);
		}

		//settings of another module are being saved: modules keeping site wide settings in
		//their config data (SettingsFactory and the like) save no Page, so Pages::save never
		//fires and the markup those settings feed into stays cached and stale
		if( !$this->isEnabled ) return;
		if( !in_array( strtolower($class), $this->wipeOnConfigSaveModulesArray(), true ) ) return;

		//nothing knows which pages show these settings: every indexed file goes. Queued like a
		//page save, so a config save and a page save in one request cost one drain
		return $this->enqueue(self::modeAll);
	}

////

	public function cachePage($event) {
		//should run in frontend only!

		$page = $event->object;

		//skipping partial renders: the hook fires for every $somePage->render() call,
		//only the page actually being viewed may be cached under the current URL
		if( $page !== $this->wire('page') ) return;

		//skipping 404 page caching
		if( $page->id == wire('config')->http404PageID ) return;

		//a static file is served with 200 for as long as it exists. A template that set
		//4xx or 5xx on this response would have that page frozen and handed out as OK
		//ever after: the http404PageID check above only catches ProcessWire's own 404
		if( $this->responseCode() !== 200 ) return;

		//skipping logged-in users, their markup may contain private data
		if( $this->wire('user')->isLoggedin() ){
			//the most confusing of all the skips: an operator browsing the site while
			//logged in sees an installed, enabled module producing nothing and saying nothing
			$this->logSkip( $page, 'user is logged in as ' . $this->wire('user')->name );
			return;
		}

		$html = $event->return;
		if( !strlen( (string) $html ) ) return;

		// skip if template is deprecated
		if( in_array($page->template->id, (array)$this->nonStaticTemplates) ) return;

		//the file is handed back under staticFile's own name, so the web server types it
		//from that extension: a template answering xml, json or plain text would be
		//served to everyone as text/html
		$contentType = (string) $page->template->contentType;
		if( $contentType !== '' && $contentType !== 'html' ){
			$this->logSkip( $page, "content type is '$contentType'" );
			return;
		}

		//an ajax response is a fragment rendered for one caller, never the page itself
		if( $this->wire('config')->ajax ) return;

		//skip if get or post request
		if( count(wire('input')->get) > 0 || count (wire('input')->post) > 0 ) return;

		//skipping paginated pages: pageNum is not part of urlSegmentStr, page 1 would be overwritten
		if( (int) wire('input')->pageNum > 1 ) return;

		//caching url segments independently
		$urlSegmentStr = wire('input')->urlSegmentStr;

		//the markup is prepared before anything at all is created on disk. stripCsrf()
		//runs preg_replace, which answers null when PCRE gives up (a backtrack limit on
		//a large minified page will do it), and file_put_contents(null) returns 0 rather
		//than false: that path wrote a 0 byte index.html and logged it as a success
		$content = $this->stripCsrf($html);
		if( !strlen( (string) $content ) ){
			$this->logSkip( $page, 'markup preparation failed' );
			return;
		}

		//a save queued an invalidation while this request was rendering: the markup may hold what
		//the database said before it, and the drain removing stale files may have run already
		if( $this->genChanged() ){
			$this->logSkip( $page, 'content changed while rendering' );
			return;
		}

		$dir = $this->rootPath() . $page->path;
		if( $urlSegmentStr !='' ) $dir.= "$urlSegmentStr/";
		$path = $dir.$this->staticFile;

		$mode = octdec($this->newDirPermissions);
		if( !$mode ) $mode = 0755;

		$dirExisted = is_dir($dir);
		if( !$dirExisted && !mkdir($dir, $mode, true) && !is_dir($dir) ) return;

		if( !$this->publish($path, $content) ){
			//a directory this call created and then could not fill is precisely what the
			//web server answers with 403 under Options -Indexes, and it would stay there
			//for good: cachePage() used to return and leave it behind
			if( !$dirExisted )
				$this->pruneEmptyDirs(
					$this->normalizePath( rtrim($dir, '/\\') ),
					$this->normalizePath( $this->rootPath() )
					);

			$this->logSkip( $page, "could not write $path" );
			return;
		}

		$relative = substr( $path, strlen($this->rootPath()) );

		if( $this->ensureTables() ){
			//a file the index does not know about is never removed again, short of a full scan
			if( !$this->recordStaticFile( $relative, $page->id, $this->renderTags($page) ) ){
				$this->unpublish($path, $dirExisted ? '' : $dir);
				$this->logSkip( $page, 'could not record dependencies' );
				return;
			}

			//a save landing between the check above and the record: its drain may have looked
			//for this file before the record existed
			if( $this->genChanged() ){
				$this->dropIndexRows( array( md5($relative) ) );
				$this->unpublish($path, $dirExisted ? '' : $dir);
				$this->logSkip( $page, 'content changed while rendering' );
			}
			return;
		}

		//no index tables (no CREATE privilege, say): the manifest, and every save wipes it all
		$this->logStaticFile($path);

		//something new is on disk again, the next save has to wipe it
		$this->didWipe = false;
	}

	private function unpublish($path, $createdDir){
		@unlink($path);
		if( $createdDir === '' ) return;
		$this->pruneEmptyDirs(
			$this->normalizePath( rtrim($createdDir, '/\\') ),
			$this->normalizePath( $this->rootPath() )
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

	public function wipeCaches( $forceScan = false ){
		//removes every static file, right now, in this request: the operator's and the deploy
		//script's tool. Saves go through the queue instead, see enqueue().
		//$forceScan walks the site root and also finds files nothing recorded.
		//Without it the index is used, or the manifest where there are no index tables, or the
		//scan after all where neither exists

		$start = microtime(true);
		ob_start();

		$this->bumpGen();

		if( $forceScan )
			$result = $this->wipeScan();

		else if( $this->ensureTables() )
			$result = $this->wipeIndexed(null);

		else if( is_file( $this->manifestPath() ) )
			$result = $this->wipeCachesManifest();

		else
			$result = $this->wipeScan();

		//a render that read the database before the wipe and records after it
		$this->bumpGen();

		$duration = round( microtime(true)-$start, 2 );

echo $this->describeWipe($result) . " Static cache cleaned in $duration s.";

		$buffer = ob_get_clean();
		$this->message($buffer);
	}

	public function drain(){
		//removes what the queue says went stale. Runs from the shutdown function, after the
		//response, and may be called by a deploy script. One drain at a time: the lock is per
		//database, so two sites sharing a server do not wait for each other

		if( !$this->ensureTables() ) return;

		$database = $this->wire('database');
		$lock = $this->lockName('drain');

		if( !$this->getLock($lock, self::lockWait) ){
			$this->logDrain('another drain held the lock, queue left for the next one');
			return;
		}

		$start = microtime(true);
		$total = array('files' => 0, 'bytes' => 0, 'dirs' => 0, 'scan' => false);
		$modes = array();
		$tagCount = 0;

		try {
			//rows queued while a round runs are picked up by the next one: whoever queued them
			//found the lock taken and is not going to drain them
			for( $round = 0; $round < 10; $round++ ){

				$query = $database->prepare('SELECT id, mode, tags FROM `' . self::tableQueue . '` ORDER BY id LIMIT 10000');
				$query->execute();
				$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
				$query->closeCursor();

				if( !count($rows) ) break;

				$mode = self::modeTags;
				$tags = array();
				$maxId = 0;
				foreach( $rows as $row ){
					$mode = max( $mode, (int) $row['mode'] );
					$maxId = max( $maxId, (int) $row['id'] );
					foreach( explode( "\n", (string) $row['tags'] ) as $tag )
						if( $tag !== '' ) $tags[$tag] = true;
				}

				if( $mode >= self::modeScan ) $result = $this->wipeScan();
				else if( $mode >= self::modeAll ) $result = $this->wipeIndexed(null);
				else $result = $this->wipeIndexed( array_keys($tags) );

				$query = $database->prepare('DELETE FROM `' . self::tableQueue . '` WHERE id <= :id');
				$query->bindValue(':id', $maxId, \PDO::PARAM_INT);
				$query->execute();

				$modes[$mode] = true;
				$tagCount += count($tags);
				if( $result === null ) $total['scan'] = true;
				else foreach( array('files', 'bytes', 'dirs') as $key ) $total[$key] += $result[$key];
			}
		}

		finally {
			$this->releaseLock($lock);
		}

		if( !count($modes) ) return;

		$this->bumpGen();

		$what = isset($modes[self::modeScan]) ? 'full scan' : ( isset($modes[self::modeAll]) ? 'all files' : "$tagCount tag(s)" );
		$duration = round( microtime(true)-$start, 2 );
		$this->logDrain( "$what: " . $this->describeWipe( $total['scan'] ? null : $total ) . " $duration s" );
	}

	public function shutdown(){
		//registered from init(). Registering once more from inside the shutdown sequence puts the
		//drain behind every shutdown function registered after this module's init(): a debug bar
		//rendering its output there would lose it once the response is finished
		register_shutdown_function(array($this, 'shutdownDrain'));
	}

	public function shutdownDrain(){
		try {
			if( !$this->drainRequested ){
				//a queue row left behind by a killed process: picked up by the next admin request
				if( !$this->adminRequest || !$this->hasOrphans() ) return;
			}
			$this->finishResponse();
			$this->drain();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'failed: ' . $e->getMessage() );
		}
	}

	public function checkTrackedPaths( $force = false ){
		//hashes name, modification time and size of every file matching the tracked patterns
		//and wipes the static files when a hash differs from the one stored last time.
		//No file content is ever read.
		//Public so a deploy script can close the gap admin only checking leaves:
		//$modules->get('StaticPages')->checkTrackedPaths(true);

		$patterns = $this->trackedPathsArray();
		if( !count($patterns) ) return;

		$hashDir = $this->trackedHashDir();
		if( $hashDir === '' ) return;

		if( !$force && !$this->trackingIntervalPassed($hashDir) ) return;

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

		$this->message( sprintf( $this->_('Tracked path changed: %s'), implode(', ', $changed) ) );

		//every new hash is on disk before the wipe starts: should the wipe itself touch a
		//tracked file, the next check compares against the new state and stops there instead
		//of wiping again forever. A template or an asset feeds any number of pages: all of them
		return $this->enqueue(self::modeAll);
	}

//privates

	private function wipeLegacy(){
		//no index tables: every save wipes everything, synchronously, as before the index.
		//The flag is cleared again by cachePage() on every file written, so it means
		//"nothing new has been cached since the last wipe", not "once per request"

		if( $this->didWipe ) return;
		$this->didWipe = true;

		return $this->wipeCaches();
	}

	private function isCacheableRequest(){
		//the request side of cachePage()'s checks, as far as ready() can tell already
		if( $this->adminRequest ) return false;
		if( $this->wire('user')->isLoggedin() ) return false;
		if( $this->wire('config')->ajax ) return false;
		$input = $this->wire('input');
		if( count($input->get) > 0 || count($input->post) > 0 ) return false;
		return true;
	}

////  what a render depends on, what a save invalidates

	/*
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

	private function selectorTags($selectors, $count){
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

	private function resolveTemplateId($value){
		$value = trim( (string) $value );
		$template = $this->wire('templates')->get( ctype_digit($value) ? (int) $value : $value );
		return $template instanceof Template ? (int) $template->id : 0;
	}

	private function resolvePageId($value){
		//ids and paths, the forms a selector string carries a page in
		$value = trim( (string) $value );
		if( ctype_digit($value) ) return (int) $value;
		if( $value === '' || $value[0] !== '/' ) return 0;
		return (int) $this->wire('pages')->getByPath( $value, array('getID' => true, 'useLanguages' => true, 'allowPartial' => false) );
	}

	private function renderTags(Page $page){
		//everything the request collected, for the file cachePage() just wrote

		$tags = $this->deps;

		//pages loaded before collecting started, by modules initialised before this one
		foreach( array_keys( (array) $this->wire('pages')->getCache() ) as $id ) $tags['p:' . (int) $id] = true;

		//the page and its ancestors: a breadcrumb needs no find, and a rename or a move of an
		//ancestor changes this url
		for( $p = $page, $i = 0; $p && $p->id && $i < 100; $p = $p->parent, $i++ ) $tags["p:{$p->id}"] = true;

		unset( $tags['p:0'] );

		if( count($tags) > self::maxTags ) return array('*');

		return array_keys($tags);
	}

	private function invalidatePage(Page $page, array $changes, $depth = 0){

		if( !$page->id ) return;

		//a repeater item is part of the content of the page owning it, and of nothing else
		if( $page instanceof RepeaterPage ){
			$this->enqueue( self::modeTags, array("p:{$page->id}") );
			$owner = $page->getForPage();
			if( $depth < 10 && $owner instanceof Page && $owner->id && $owner->id !== $page->id )
				return $this->invalidatePage( $owner, $changes, $depth + 1 );
			return;
		}

		if( $this->wipesAll($page, $changes) ) return $this->enqueue(self::modeAll);

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
			if( $descendants === null ) return $this->enqueue(self::modeAll);
			$tags = array_merge( $tags, $descendants );
		}

		return $this->enqueue( self::modeTags, $tags );
	}

	private function listingTags(Page $page){
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

	private function movesUrl(Page $page, array $changes){
		if( (int) $page->numChildren < 1 ) return false;
		foreach( $changes as $change ){
			$change = (string) $change;
			//name1012 and the like: the page name in another language
			if( $change === 'parent' || $change === 'parent_id' || preg_match( '/^name\d*$/', $change ) ) return true;
		}
		return false;
	}

	private function descendantTags(Page $page){
		//null when there are too many to list: everything goes then
		$this->resolving = true;
		try {
			$ids = $this->wire('pages')->findIDs( "has_parent={$page->id}, include=all, limit=" . ( self::maxDescendants + 1 ) );
		}
		finally {
			$this->resolving = false;
		}
		if( count($ids) > self::maxDescendants ) return null;
		return array_map( fn($id) => 'p:' . (int) $id, $ids );
	}

	private function wipesAll(Page $page, array $changes){
		//what no render-time record can follow: languages are loaded before this module's
		//init(), and the operator names templates and fields whose content ends up everywhere

		$template = $page->template;
		if( !$template instanceof Template ) return true;
		if( $template->name === 'language' ) return true;

		$templates = array_map( 'intval', (array) $this->wipeAllTemplates );
		if( in_array( (int) $template->id, $templates, true ) ) return true;

		$fields = array_map( 'strtolower', $this->linesToArray( $this->configValue('wipeAllFields', '') ) );
		if( !count($fields) ) return false;
		$changed = array_map( fn($change) => strtolower( (string) $change ), $changes );
		return count( array_intersect($fields, $changed) ) > 0;
	}

	private function enqueue($mode, array $tags = array()){
		//a save only says what went stale. The drain removes it once the response is out, so an
		//editor never waits for the unlinks. Queued at once rather than at shutdown: a process
		//killed in between leaves a row the next admin request drains, not a stale file.
		//Only what this request has not queued yet is inserted

		//first of all: a render that read the database before this save must not publish
		if( !$this->genBumped ){
			$this->bumpGen();
			$this->genBumped = true;
		}

		if( !$this->ensureTables() ) return $this->wipeLegacy();

		if( $mode <= $this->queuedMode ) return;

		if( $mode === self::modeTags ){
			$new = array();
			foreach( $tags as $tag ){
				$tag = (string) $tag;
				if( $tag === '' || isset( $this->queuedTags[$tag] ) ) continue;
				$this->queuedTags[$tag] = true;
				$new[] = $tag;
			}
			if( !count($new) ) return;
			$tags = $new;
		}
		else {
			$this->queuedMode = $mode;
			$tags = array();
		}

		try {
			$query = $this->wire('database')->prepare('INSERT INTO `' . self::tableQueue . '` (mode, tags, created) VALUES (:mode, :tags, :created)');
			$query->bindValue(':mode', (int) $mode, \PDO::PARAM_INT);
			$query->bindValue(':tags', implode("\n", $tags));
			$query->bindValue(':created', time(), \PDO::PARAM_INT);
			$query->execute();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'could not queue, wiping everything now: ' . $e->getMessage() );
			$this->tablesReady = false;
			return $this->wipeLegacy();
		}

		$this->drainRequested = true;
	}

////  the index

	private function ensureTables(){
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
		$failed = $this->wire('config')->paths->cache . 'StaticPages/tables.failed';
		if( is_file($failed) && time() - (int) @filemtime($failed) < 3600 ) return false;

		try {
			$this->createTables();
			$version = $this->readSchemaVersion();
			if( $version < self::schemaVersion ) $version = $this->upgradeSchema();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'index tables unavailable, every save wipes everything: ' . $e->getMessage() );
			if( $this->trackedHashDir() !== '' ) @touch($failed);
			return false;
		}

		//another request is migrating them right now: this one makes do without the index
		if( $version === null ) return false;

		//written by a newer module version, then the module went back: this code must not write
		//into a layout it does not know. The first save scans the disk, which finds the files the
		//index knew about as well
		if( $version > self::schemaVersion ){
			$this->logDrain( "index tables have schema $version, this module version knows " . self::schemaVersion . ': every save wipes everything until the module is upgraded again' );
			if( $this->trackedHashDir() !== '' ) @touch($failed);
			return false;
		}

		if( is_file($failed) ) @unlink($failed);
		$this->tablesReady = true;
		$this->importManifest();

		return true;
	}

	private function createTables(){
		//the current layout, for a site that has none yet. Tables already there are left alone,
		//upgradeSchema() takes care of those

		$database = $this->wire('database');
		$charset = $this->wire('config')->dbCharset === 'utf8mb4' ? 'utf8mb4' : 'utf8';
		$options = "ENGINE=InnoDB DEFAULT CHARSET=$charset COMMENT='" . $this->schemaComment() . "'";

		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::tableFiles . '` (' .
				'path_hash BINARY(16) NOT NULL PRIMARY KEY, ' .
				'path TEXT NOT NULL, ' .
				'page_id INT UNSIGNED NOT NULL DEFAULT 0, ' .
				'created INT UNSIGNED NOT NULL DEFAULT 0' .
			") $options"
		);
		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::tableTags . '` (' .
				'tag VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ' .
				'path_hash BINARY(16) NOT NULL, ' .
				'PRIMARY KEY (tag, path_hash), ' .
				'KEY path_hash (path_hash)' .
			") $options"
		);
		$database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::tableQueue . '` (' .
				'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
				'mode TINYINT UNSIGNED NOT NULL, ' .
				'tags MEDIUMTEXT, ' .
				'created INT UNSIGNED NOT NULL DEFAULT 0, ' .
				'KEY created (created)' .
			") $options"
		);
	}

	private function schemaComment(){
		return 'StaticPages schema ' . self::schemaVersion;
	}

	private function readSchemaVersion(){
		//kept in the table comments rather than in the module config: it goes and comes back with
		//the tables themselves, a dropped table or a restored dump can not leave it saying
		//something else, and a front end request never writes module config. A table newer than
		//this code counts first, nothing may be written then. Otherwise the lowest of the three:
		//a migration cut short may have left some behind. 0 for a table without one, 0.7.0
		//wrote none

		$query = $this->wire('database')->prepare(
			'SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES ' .
			'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:files, :tags, :queue)'
			);
		$query->bindValue(':files', self::tableFiles);
		$query->bindValue(':tags', self::tableTags);
		$query->bindValue(':queue', self::tableQueue);
		$query->execute();
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		$query->closeCursor();

		if( count($rows) < 3 ) return 0;

		$versions = array();
		foreach( $rows as $row )
			$versions[] = preg_match( '/^StaticPages schema (\d+)$/', (string) $row['TABLE_COMMENT'], $match ) ? (int) $match[1] : 0;

		if( max($versions) > self::schemaVersion ) return max($versions);

		return min($versions);
	}

	private function upgradeSchema(){
		//one request at a time, two side by side would run the same ALTERs together. Returns the
		//version the tables are at afterwards, or null when another request holds the lock

		$lock = $this->lockName('schema');
		if( !$this->getLock($lock, 10) ) return null;

		try {
			//the request that held the lock before may have done it all already
			$from = $this->readSchemaVersion();
			if( $from >= self::schemaVersion ) return $from;

			$this->migrateSchema($from);

			//last, once every step went through: a migration cut short is repeated in full
			foreach( array(self::tableFiles, self::tableTags, self::tableQueue) as $table )
				$this->wire('database')->exec( "ALTER TABLE `$table` COMMENT = '" . $this->schemaComment() . "'" );

			$this->logDrain( "index tables migrated from schema $from to " . self::schemaVersion );

			return self::schemaVersion;
		}
		finally {
			$this->releaseLock($lock);
		}
	}

	private function migrateSchema($from){
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

	private function importManifest(){
		//files cached before the index existed, dependencies unknown: tagged '*', the first
		//drain of any kind removes them
		$manifest = $this->manifestPath();
		if( !is_file($manifest) ) return;

		$lines = @file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if( $lines === false ) return;

		$imported = true;
		foreach( array_unique($lines) as $relative )
			if( !$this->recordStaticFile( $relative, 0, array('*') ) ) $imported = false;

		if( $imported ) @unlink($manifest);
	}

	private function recordStaticFile($relative, $pageId, array $tags){
		$database = $this->wire('database');
		$hash = md5($relative);

		try {
			$database->beginTransaction();

			$query = $database->prepare('DELETE FROM `' . self::tableTags . '` WHERE path_hash = UNHEX(:hash)');
			$query->bindValue(':hash', $hash);
			$query->execute();

			$query = $database->prepare('REPLACE INTO `' . self::tableFiles . '` (path_hash, path, page_id, created) VALUES (UNHEX(:hash), :path, :page, :created)');
			$query->bindValue(':hash', $hash);
			$query->bindValue(':path', (string) $relative);
			$query->bindValue(':page', (int) $pageId, \PDO::PARAM_INT);
			$query->bindValue(':created', time(), \PDO::PARAM_INT);
			$query->execute();

			foreach( array_chunk( array_values( array_unique($tags) ), self::chunkSize ) as $chunk ){
				$query = $database->prepare(
					'INSERT IGNORE INTO `' . self::tableTags . '` (tag, path_hash) VALUES ' .
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
			$this->logDrain( "could not record $relative: " . $e->getMessage() );
			return false;
		}
	}

	private function indexedFiles($tags){
		//hash => root relative path. $tags null: every indexed file. Otherwise the files
		//carrying one of $tags, and the '*' ones

		$database = $this->wire('database');
		$files = array();

		if( $tags === null ){
			$query = $database->prepare('SELECT HEX(path_hash) AS hash, path FROM `' . self::tableFiles . '`');
			$query->execute();
			foreach( $query->fetchAll(\PDO::FETCH_ASSOC) as $row ) $files[ strtolower($row['hash']) ] = $row['path'];
			return $files;
		}

		$tags[] = '*';

		foreach( array_chunk( array_values( array_unique($tags) ), self::chunkSize ) as $chunk ){
			$query = $database->prepare(
				'SELECT HEX(f.path_hash) AS hash, f.path FROM `' . self::tableFiles . '` f ' .
				'WHERE f.path_hash IN (SELECT t.path_hash FROM `' . self::tableTags . '` t WHERE t.tag IN (' .
				implode( ', ', array_fill( 0, count($chunk), '?' ) ) . '))'
				);
			foreach( $chunk as $n => $tag ) $query->bindValue( $n + 1, (string) $tag );
			$query->execute();
			foreach( $query->fetchAll(\PDO::FETCH_ASSOC) as $row ) $files[ strtolower($row['hash']) ] = $row['path'];
		}

		return $files;
	}

	private function dropIndexRows(array $hashes){
		try {
			foreach( array_chunk( array_values($hashes), self::chunkSize ) as $chunk ){
				$in = implode( ', ', array_fill( 0, count($chunk), 'UNHEX(?)' ) );
				foreach( array(self::tableTags, self::tableFiles) as $table ){
					$query = $this->wire('database')->prepare("DELETE FROM `$table` WHERE path_hash IN ($in)");
					foreach( $chunk as $n => $hash ) $query->bindValue( $n + 1, (string) $hash );
					$query->execute();
				}
			}
			return true;
		}
		catch( \Throwable $e ){
			$this->logDrain( 'could not drop index rows: ' . $e->getMessage() );
			return false;
		}
	}

	private function wipeIndexed($tags){
		//$tags null: every indexed file

		$files = $this->indexedFiles($tags);
		if( !count($files) ) return array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		//rows first, files second: a render recording the same path meanwhile leaves a row for a
		//file that is gone, which is harmless, and never a file that nothing knows about
		if( !$this->dropIndexRows( array_keys($files) ) )
			throw new WireException('the index rows could not be dropped, the queue is kept');

		return $this->deleteStaticFiles($files);
	}

	private function deleteStaticFiles(array $files){

		$result = array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		$root = $this->rootPath();
		$nonStaticDirs = $this->nonStaticDirsArray();
		$dirs = array();
		$failed = array();

		foreach( $files as $relative ){

			$relative = (string) $relative;

			//the index could have been tampered with, validating every entry before deletion
			if( strpos($relative, '..') !== false ) continue;
			$path = $root . $relative;
			if( basename($path) !== $this->staticFile ) continue;
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
		foreach( $failed as $relative ) $this->recordStaticFile( $relative, 0, array('*') );

		$result['dirs'] = $this->pruneDirs( array_keys($dirs), $this->normalizePath($root) );

		return $result;
	}

////  the drain's surroundings

	private function hasOrphans(){
		//no table yet means nothing queued, and this must not create one on every admin request
		try {
			$query = $this->wire('database')->prepare('SELECT 1 FROM `' . self::tableQueue . '` WHERE created < :time LIMIT 1');
			$query->bindValue(':time', time() - self::orphanAge, \PDO::PARAM_INT);
			$query->execute();
			$found = (bool) $query->fetchColumn();
			$query->closeCursor();
			return $found;
		}
		catch( \Throwable $e ){
			return false;
		}
	}

	private function finishResponse(){
		//the client gets its answer before the drain starts: php-fpm and LiteSpeed close the
		//connection, other SAPIs at least get the output flushed

		if( PHP_SAPI === 'cli' ) return;

		@ignore_user_abort(true);
		@set_time_limit(300);

		//a session left open would keep its lock through the drain, and the editor's next
		//request, the admin's own ajax included, would wait for it
		if( session_status() === PHP_SESSION_ACTIVE ) @session_write_close();

		if( function_exists('fastcgi_finish_request') ){
			@fastcgi_finish_request();
			return;
		}

		if( function_exists('litespeed_finish_request') ){
			@litespeed_finish_request();
			return;
		}

		while( ob_get_level() > 0 && @ob_end_flush() );
		@flush();
	}

	private function lockName($purpose){
		//GET_LOCK names are server wide and at most 64 characters: one per database, so two
		//sites sharing a server do not wait for each other
		$config = $this->wire('config');
		return "StaticPages.$purpose." . md5( $config->dbHost . '|' . $config->dbPort . '|' . $config->dbName );
	}

	private function getLock($name, $wait){
		$query = $this->wire('database')->prepare('SELECT GET_LOCK(:name, :wait)');
		$query->bindValue(':name', $name);
		$query->bindValue(':wait', (int) $wait, \PDO::PARAM_INT);
		$query->execute();
		$locked = (int) $query->fetchColumn() === 1;
		$query->closeCursor();
		return $locked;
	}

	private function releaseLock($name){
		$query = $this->wire('database')->prepare('SELECT RELEASE_LOCK(:name)');
		$query->bindValue(':name', $name);
		$query->execute();
		$query->closeCursor();
	}

	private function genPath(){
		return $this->wire('config')->paths->cache . 'StaticPages/gen';
	}

	private function readGen(){
		//a read caught halfway through a write reads as changed, the safe side
		$gen = @file_get_contents( $this->genPath() );
		return $gen === false ? '' : $gen;
	}

	private function bumpGen(){
		if( $this->trackedHashDir() === '' ) return;
		@file_put_contents( $this->genPath(), uniqid('', true), LOCK_EX );
	}

	private function genChanged(){
		return $this->readGen() !== $this->genAtStart;
	}

	private function logDrain($message){
		$this->wire('log')->save( 'static-pages', $message );
	}

	private function describeWipe($result){
		//a shell scan counts nothing
		if( !is_array($result) ) return 'Full disk scan done.';
		$bytes = $this->readableFilesize( $result['bytes'] );
		return "{$result['files']} static file(s) ($bytes) and {$result['dirs']} dir(s) deleted.";
	}

	private function publish($path, $content){
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

	private function tmpFileGlob(){
		//the shape publish() writes, as a glob: '.index.html.*.tmp'
		return '.' . $this->staticFile . '.*.tmp';
	}

	private function responseCode(){
		//CLI and any SAPI not tracking a status answer false, which is not an error here
		$code = function_exists('http_response_code') ? http_response_code() : 200;
		return $code === false ? 200 : (int) $code;
	}

	private function logSkip($page, $reason){
		//off by default: a busy front end would write one line per request
		if( !$this->configValue('logSkips', 0) ) return;
		$this->wire('log')->save( 'static-pages', "skip {$page->path}: $reason" );
	}

	private function rootPath(){
		//filesystem path to the site root, no trailing slash
		return rtrim( $this->wire('config')->paths->root, '/\\' );
	}

	private function manifestPath(){
		return $this->wire('config')->paths->cache . 'StaticPages.manifest';
	}

	private function logStaticFile($path){
		//root-relative path, one entry per line
		$relative = substr( $path, strlen($this->rootPath()) );
		file_put_contents( $this->manifestPath(), $relative . "\n", FILE_APPEND | LOCK_EX );
	}

	private function wipeCachesManifest(){
		//no index tables only: deletes the files the manifest lists, no site root scanning.
		//The manifest is moved aside first and an empty one put in its place: renders appending
		//meanwhile land in the new one instead of being lost, and an empty manifest means
		//"nothing cached", where a missing one sent the next save into the full disk scan

		$manifest = $this->manifestPath();
		$taken = $manifest . '.' . getmypid() . '.wipe';
		if( !@rename($manifest, $taken) ) return array('files' => 0, 'bytes' => 0, 'dirs' => 0);
		if( !is_file($manifest) ) @touch($manifest);

		$lines = @file( $taken, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		@unlink($taken);
		if( $lines === false ) $lines = array();

		return $this->deleteStaticFiles( array_unique($lines) );
	}

	private function pruneDirs(array $dirs, $rootNorm){
		//removes every dir in $dirs and every ancestor below the site root that is empty, each
		//looked at once, deepest first: a parent is only looked at once its children are gone.
		//Walking up from every deleted file instead opened the same ancestors over and over

		$rootNorm = rtrim( $rootNorm, DIRECTORY_SEPARATOR );
		$rootLength = strlen($rootNorm);
		$set = array();

		foreach( $dirs as $dir ){
			$dir = rtrim( $this->normalizePath($dir), DIRECTORY_SEPARATOR );
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

	private function pruneEmptyDirs($dirPath, $rootNorm){
		//removes $dirPath if empty, then its empty ancestors up to the site root level

		$deletedDirs = 0;

		if( !is_dir($dirPath) ) return 0;
		$dirIsEmpty = !(new \FilesystemIterator($dirPath))->valid();
		if( !$dirIsEmpty ) return 0;
		rmdir($dirPath);
		$deletedDirs++;

		$parts = explode( DIRECTORY_SEPARATOR, $this->normalizePath($dirPath) );
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

	private function linesToArray($value){
		//textarea values arrive with \r\n line ends; a stray \r or an empty line
		//would silently disable the wipe guards
		$lines = preg_split( '/\R/', (string) $value );
		$lines = array_map( 'trim', $lines );
		return array_values( array_filter($lines, 'strlen') );
	}

	private function nonStaticDirsArray(){
		//never saved: the same default the config screen offers, so the full scan never
		//runs unguarded through site/, wire/ and the like
		$value = $this->nonStaticDirs;
		if( $value === null ) return $this->defaultNonStaticDirs();
		return $this->linesToArray($value);
	}

	public function defaultNonStaticDirs(){
		//the site root's own dirs (site, wire, api...) minus the dirs that only hold static
		//copies of pages. Every root dir used to be listed, and once static copies existed
		//that took in the page dirs too (/about/, /en/): their copies were then never wiped
		//and went stale
		$root = $this->rootPath();
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

	private function rootPageDirs(){
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

	private function isStaticCopyDir($name, array $pageDirs){
		//a root dir named like a page is that page's static copy dir, unless it holds
		//anything besides static copies: a page named like a code dir (an /api/ page next
		//to the api/ endpoints) must leave that dir protected
		if( !isset($pageDirs[$name]) ) return false;
		$dir = $this->rootPath() . DIRECTORY_SEPARATOR . $name;
		if( is_link($dir) ) return false;
		try {
			$rii = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach( $rii as $entry ){
				if( $entry->isDir() ) continue; //an empty dir is a leaf
				$basename = $entry->getFilename();
				if( $basename === $this->staticFile || fnmatch( $this->tmpFileGlob(), $basename ) ) continue;
				return false;
			}
		}
		catch( \Throwable $e ){
			return false;
		}
		return true;
	}

	private function warnPageDirsProtected($data){
		//a list saved before the default was fixed (or typed by hand) can still name page dirs
		if( !is_array($data) || !isset($data['nonStaticDirs']) ) return;
		$rootPageDirs = $this->rootPageDirs();
		$listed = $this->linesToArray( $data['nonStaticDirs'] );
		$pageDirs = array_values( array_filter( $listed, fn($dir) => $this->isStaticCopyDir($dir, $rootPageDirs) ) );
		if( !count($pageDirs) ) return;
		$this->warning( sprintf(
			$this->_('Non-static directories lists page directories: %s. Static copies inside them are never wiped and go stale; remove them from the list.'),
			implode(', ', $pageDirs)
			) );
	}

	private function configValue($key, $default){
		//a key added by a module update stays absent from the stored config data until the
		//operator saves the module config once: the default declared in StaticPages.config.php
		//seeds the inputfield only. An operator clearing a field stores '' or 0, neither of
		//which is null, so switching a defaulted-on setting off still works
		$value = $this->get($key);
		return $value === null ? $default : $value;
	}

	private function configSaveClassName($class){
		//Modules::saveConfig is reached with a class name, a Module instance or a module id
		if( is_object($class) ) return $class->className();
		if( ctype_digit( (string) $class ) ) return (string) $this->wire('modules')->getModuleClass( (int) $class );
		return (string) $class;
	}

	private function wipeOnConfigSaveModulesArray(){
		//returns lowercased plain class names, ready for comparison

		$value = $this->configValue('wipeOnConfigSaveModules', 'SettingsFactory');

		$classes = array();
		foreach( $this->linesToArray($value) as $className ){
			//tolerating a namespace prefix, names are matched the way className() reports them
			$className = ltrim( $className, '\\' );
			$pos = strrpos( $className, '\\' );
			if( $pos !== false ) $className = substr( $className, $pos+1 );
			$classes[] = strtolower($className);
		}

		return $classes;
	}

	private function isAdminRequest(){
		//$config->admin is resolved by ProcessWire::setStatus() on the line before module
		//ready() is triggered, so it costs nothing here: no page lookup, no template lookup,
		//no query. It is true only for a logged in user on a $config->adminTemplates page,
		//which keeps the front end and the admin login screen out, and it honours a site
		//that renamed or added admin templates. It arrived in 3.0.142 and reads 0 rather
		//than a boolean before that, hence the fallback

		$config = $this->wire('config');
		if( is_bool($config->admin) ) return $config->admin;

		$page = $this->wire('page');
		if( !$page || !$page->id ) return false;
		if( !$this->wire('user')->isLoggedin() ) return false;

		$adminTemplates = $config->adminTemplates;
		if( !is_array($adminTemplates) || !count($adminTemplates) ) $adminTemplates = array('admin');

		return in_array( $page->template->name, $adminTemplates, true );
	}

	private function trackedHashDir(){
		//one hash file per tracked line, next to the manifest. Recreated on every call: the
		//shell wipes remove empty dirs and a deploy script may clear the whole PW cache dir.
		//Returns '' when it can not be created

		$dir = $this->wire('config')->paths->cache . 'StaticPages/';

		if( is_dir($dir) ) return $dir;
		if( !$this->wire('files')->mkdir($dir, true) ) return '';

		return $dir;
	}

	private function trackingIntervalPassed($hashDir){
		//one stat instead of a walk over every tracked file: the admin fires a lot of ajax
		//(page tree, autocomplete, inline edits) and every one of those requests would
		//otherwise stat every tracked file again.
		//Stamping before the walk and not after is deliberate, two requests arriving
		//together must not both pay for it, which makes this the concurrency guard as well

		if( self::trackingInterval < 1 ) return true;

		$stamp = $hashDir . 'check.stamp';

		if( is_file($stamp) && time() - (int) @filemtime($stamp) < self::trackingInterval ) return false;

		@touch($stamp);

		return true;
	}

	private function trackedPathsArray(){
		//validated, normalised, deduplicated spec lines

		$patterns = array();

		foreach( $this->linesToArray( $this->configValue('trackedPaths', self::trackedPathsDefault) ) as $line ){

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

			else if( strpbrk($line, '*?') === false && is_dir( $this->normalizePath( $this->rootPath() . '/' . $line ) ) )
				$line .= '/**/*';

			$patterns[] = $line;
		}

		return array_values( array_unique($patterns) );
	}

	private function globToRegex($pattern){
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

	private function patternBaseDir($pattern){
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

	private function trackedPathWalk($baseDir, $pattern, $base){
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
				$rii->setMaxDepth(self::trackingMaxDepth);

			foreach( $rii as $entry ){
				//an empty dir is a leaf and still turns up here
				if( $entry->isDir() ) continue;
				$paths[] = $entry->getPathname();
			}
		}

		catch( \Exception $e ){}

		return $paths;
	}

	private function isExcludedFromTracking($path){
		//ProcessWire and this module write into these dirs themselves. The manifest,
		//WireCache.maint, the FileCompiler output, the temp, session and log files all
		//change on ordinary requests, and a pattern covering them would detect a change,
		//wipe, change the state again by wiping, and never settle

		//a generated static file is not a source file either
		if( basename($path) === $this->staticFile ) return true;

		$path = $this->normalizePath($path);
		$paths = $this->wire('config')->paths;

		foreach( array('cache', 'logs', 'sessions', 'tmp') as $key ){
			$dir = (string) $paths->$key;
			if( !strlen($dir) ) continue;
			$dir = rtrim( $this->normalizePath($dir), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if( strpos($path, $dir) === 0 ) return true;
		}

		return false;
	}

	private function hashPattern($pattern){
		//md5 over "relative path|mtime|size" of every matching file, sorted so the order the
		//filesystem hands entries back in can not change the result. Paths are joined with
		//forward slashes so the same tree hashes identically on Windows and on Linux

		$regex = $this->globToRegex($pattern);
		$base = $this->patternBaseDir($pattern);

		$root = rtrim( $this->normalizePath( $this->rootPath() ), DIRECTORY_SEPARATOR );
		$baseDir = strlen($base) ? $root . DIRECTORY_SEPARATOR . $this->normalizePath($base) : $root;

		//a base dir that is not there yet still gets a hash: the empty set has one, and
		//creating the dir later is then a change like any other
		if( !is_dir($baseDir) ) return md5('');

		$entries = array();

		foreach( $this->trackedPathWalk($baseDir, $pattern, $base) as $path ){

			if( $this->isExcludedFromTracking($path) ) continue;

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

	private function readHashFile($hashFile){
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

	private function writeHashFile($hashFile, $pattern, $hash){
		//the spec line and the time go into the file next to the hash: the file name is an
		//md5 and says nothing, and these files have to be readable by whoever is debugging
		//why a wipe did or did not happen

		$content = $hash . "\n" . $pattern . "\n" . date('Y-m-d H:i:s') . "\n";

		return file_put_contents( $hashFile, $content, LOCK_EX ) !== false;
	}

	private function pruneHashFiles(array $patterns, $hashDir){
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

	private function normalizePath($path){
		return str_replace( array('\\', '/'), DIRECTORY_SEPARATOR, $path );
	}

	private function isProtectedPath($dirPath, array $nonStaticDirs){
		$dirPath = $this->normalizePath($dirPath);
		$root = $this->normalizePath( $this->rootPath() );
		foreach( $nonStaticDirs as $nonStaticDir ){
			$sample = $root . DIRECTORY_SEPARATOR . $nonStaticDir;
			if( $dirPath === $sample ) return true;
			if( strpos( $dirPath, $sample . DIRECTORY_SEPARATOR ) === 0 ) return true;
		}
		return false;
	}

	private function wipeScan(){
		//walks the site root for static files, finding the ones nothing recorded as well: forced
		//wipes, and installs with neither index nor manifest. Returns the counts, or null from a
		//shell, which counts nothing

		//rows first: a render recording while the scan runs keeps a row for a file the scan then
		//removes, which is harmless, and never the reverse
		if( $this->ensureTables() ){
			$database = $this->wire('database');
			$database->exec( 'DELETE FROM `' . self::tableTags . '`' );
			$database->exec( 'DELETE FROM `' . self::tableFiles . '`' );
		}

		//empty rather than gone: a missing manifest is what sends the next wipe into a scan
		$manifest = $this->manifestPath();
		if( is_file($manifest) ) @file_put_contents( $manifest, '', LOCK_EX );

		$targets = $this->scanTargets();

		//a protected entry naming a dir below the root level: only the php walk looks at every
		//dir on its way down, the shells are handed whole root dirs
		$nested = false;
		foreach( $this->nonStaticDirsArray() as $nonStaticDir )
			if( strpbrk( trim( $nonStaticDir, '/\\' ), '/\\' ) !== false ) $nested = true;

		if( $this->useShell && !$nested ){
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

	private function scanTargets(){
		//the root dirs a scan walks: all but the protected ones and the links. Protected dirs are
		//never entered, where every scan used to walk all of site/assets/files only to skip each
		//file in it, and the shells did it once per pass

		$root = $this->rootPath();
		$windows = DIRECTORY_SEPARATOR === '\\';

		$protected = array();
		foreach( $this->nonStaticDirsArray() as $nonStaticDir ){
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

	private function wipeScanPHP(array $targets){
		//one walk per root dir: files on the way down, empty dirs afterwards, deepest first

		$result = array('files' => 0, 'bytes' => 0, 'dirs' => 0);

		$root = $this->rootPath();
		$nonStaticDirs = $this->nonStaticDirsArray();
		$tmpGlob = $this->tmpFileGlob();
		$dirs = array();

		$delete = function($path) use (&$result, $tmpGlob){
			$name = basename($path);
			//a temporary file left behind by a killed publish keeps its directory non-empty,
			//and an orphan directory holding no index.html is the 403
			if( fnmatch( $tmpGlob, $name ) ){
				@unlink($path);
				return;
			}
			if( $name !== $this->staticFile ) return;
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

		$result['dirs'] = $this->pruneDirs( array_keys($dirs), $this->normalizePath($root) );

		return $result;
	}

	private function wipeScanUnix(array $targets){
		//one find for the root level files, one for everything below: -depth reaches a dir after
		//its contents, so a dir emptied by the -delete of its files is empty by the time -empty
		//looks at it, where the empty dir removal used to take a pass per nesting level.
		//-delete can not be combined with -prune: the protected dirs are left out by never
		//being named as a starting point

		$match = '\\( -name ' . escapeshellarg( $this->staticFile ) . ' -o -name ' . escapeshellarg( $this->tmpFileGlob() ) . ' \\)';

		$root = escapeshellarg( $this->rootPath() );
		shell_exec( "find $root -mindepth 1 -maxdepth 1 -type f $match -delete 2>/dev/null" );

		if( !count($targets) ) return;

		$dirs = implode( ' ', array_map( 'escapeshellarg', $targets ) );
		shell_exec( "find $dirs -depth \\( -type f $match -delete \\) -o \\( -type d -empty -delete \\) 2>/dev/null" );
	}

	private function wipeScanWindows(array $targets){
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
			'$name = ' . $text( $this->staticFile ),
			'$tmp = ' . $text( $this->tmpFileGlob() ),
			'function Wipe-Files([string]$dir){ try { $files = [IO.Directory]::GetFiles($dir) } catch { return }; foreach($file in $files){ $n = [IO.Path]::GetFileName($file); if($n -eq $name -or $n -like $tmp){ try { [IO.File]::Delete($file) } catch {} } } }',
			'function Wipe-Tree([string]$dir){ try { $subs = [IO.Directory]::GetDirectories($dir) } catch { return }; foreach($sub in $subs){ if(-not ([IO.File]::GetAttributes($sub) -band [IO.FileAttributes]::ReparsePoint)){ Wipe-Tree $sub } }; Wipe-Files $dir; try { if([IO.Directory]::GetFileSystemEntries($dir).Length -eq 0){ [IO.Directory]::Delete($dir) } } catch {} }',
			'Wipe-Files ' . $text( $this->rootPath() ),
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

	private function readableFilesize($bytes, $decimals = 2) {
		$sz = 'BKMGTP';
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
	}

}
