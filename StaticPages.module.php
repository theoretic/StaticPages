<?php
/*
Static pages module
https://github.com/ryancramerdesign/Helloworld
AT
26.08.26
*/

namespace ProcessWire;

/*
The module class holds the hooks, the public API and the config helpers. The work is done by
parts, each in its own file next to this one and created when first asked for:

  StaticPagesIndex.php      the dependency index: tables, what a render depends on, what a save invalidates
  StaticPagesQueue.php      the queue, the drain after the response, the invalidation generation
  StaticPagesPublisher.php  the atomic write of a static file, the markup preparation
  StaticPagesWiper.php      deleting static files: by the index, by the manifest, by a disk scan
  StaticPagesTracker.php    tracked path changes

Hooks stay registered on this class: site code looks them up by their toObject.
*/

class StaticPages extends WireData implements Module, ConfigurableModule {

	//real properties, not config keys: WireData's magic __get/__set has no business here

	//the parts, see index(), queue(), publisher(), wiper(), tracker()
	private $index = null;
	private $queue = null;
	private $publisher = null;
	private $wiper = null;
	private $tracker = null;

	private $adminRequest = false;

	//the dependency index, see StaticPagesIndex::ensureTables()
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

		$this->queue()->startGen();

		//every page a render loads is a dependency of the file that render produces: recording
		//them costs a hash write per load, working them out at save time would cost more than
		//the wipe. Collecting from init() rather than ready(), so pages other modules load in
		//their ready() are seen too; ready() stops it for requests that are never cached.
		//A CLI script would only grow the set
		$this->index()->collect( PHP_SAPI !== 'cli' );
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
		if( !$this->isCacheableRequest() ) $this->index()->collect(false);

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
		$this->index()->ensureTables();
	}

	public function ___uninstall() {
		$this->index()->dropTables();
	}

////  the parts

	/**
	 * The dependency index: tables, render dependencies, invalidation
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesIndex
	 *
	 */
	public function index() {
		if( $this->index === null ){
			require_once(__DIR__ . '/StaticPagesIndex.php');
			$this->index = $this->wire(new StaticPagesIndex($this));
		}
		return $this->index;
	}

	/**
	 * The queue, the drain and the invalidation generation
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesQueue
	 *
	 */
	public function queue() {
		if( $this->queue === null ){
			require_once(__DIR__ . '/StaticPagesQueue.php');
			$this->queue = $this->wire(new StaticPagesQueue($this));
		}
		return $this->queue;
	}

	/**
	 * Writing static files
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesPublisher
	 *
	 */
	public function publisher() {
		if( $this->publisher === null ){
			require_once(__DIR__ . '/StaticPagesPublisher.php');
			$this->publisher = $this->wire(new StaticPagesPublisher($this));
		}
		return $this->publisher;
	}

	/**
	 * Deleting static files
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesWiper
	 *
	 */
	public function wiper() {
		if( $this->wiper === null ){
			require_once(__DIR__ . '/StaticPagesWiper.php');
			$this->wiper = $this->wire(new StaticPagesWiper($this));
		}
		return $this->wiper;
	}

	/**
	 * Tracked path changes
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesTracker
	 *
	 */
	public function tracker() {
		if( $this->tracker === null ){
			require_once(__DIR__ . '/StaticPagesTracker.php');
			$this->tracker = $this->wire(new StaticPagesTracker($this));
		}
		return $this->tracker;
	}

////  hooks

	public function hookCache($event) {
		return $this->cachePage($event);
	}

	public function hookLoaded($event) {
		$this->index()->pageLoaded( $event->object );
	}

	public function hookFound($event) {
		$this->index()->pagesFound( $event->arguments(0) );
	}

	public function hookSaved($event) {
		$changes = (array) $event->arguments(1);
		//Pages::saved fires for a save that changed nothing as well
		if( !count($changes) ) return;
		return $this->index()->invalidatePage( $event->arguments(0), $changes );
	}

	public function hookSavedField($event) {
		$field = $event->arguments(1);
		return $this->index()->invalidatePage( $event->arguments(0), array( (string) ( $field instanceof Field ? $field->name : $field ) ) );
	}

	public function hookDeleted($event) {
		$page = $event->arguments(0);
		//emptying the trash: nobody saw these pages since they went in, see invalidatePage()
		return $this->index()->invalidatePage( $page, $page->isTrash() ? array() : array('status') );
	}

	public function hookSorted($event) {
		return $this->index()->invalidatePage( $event->arguments(0), array('sort') );
	}

	public function hookTrashed($event) {
		//the page itself was saved and handled by hookSaved(). Its descendants changed status in
		//SQL alone: no save, no hook, and a listing of them elsewhere never loaded the page
		if( (int) $event->arguments(0)->numChildren > 0 ) return $this->queue()->enqueue(self::modeAll);
	}

	public function hookAfterSaveConfig($event) {
		//should run in backend only!
		$class = $this->configSaveClassName( $event->arguments(0) );

		//settings of this module are being saved: an explicit, one shot operator action,
		//deliberately not gated on isEnabled so a disabled module can still be told to clean up
		if( $class === $this->className() ){
			$this->wiper()->warnPageDirsProtected( $event->arguments(1) );
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
		return $this->queue()->enqueue(self::modeAll);
	}

////  public API

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
		if( $this->publisher()->responseCode() !== 200 ) return;

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
		if( $this->queue()->genChanged() ){
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

		if( !$this->publisher()->publish($path, $content) ){
			//a directory this call created and then could not fill is precisely what the
			//web server answers with 403 under Options -Indexes, and it would stay there
			//for good: cachePage() used to return and leave it behind
			if( !$dirExisted )
				$this->wiper()->pruneEmptyDirs(
					$this->normalizePath( rtrim($dir, '/\\') ),
					$this->normalizePath( $this->rootPath() )
					);

			$this->logSkip( $page, "could not write $path" );
			return;
		}

		$relative = substr( $path, strlen($this->rootPath()) );

		if( $this->index()->ensureTables() ){
			//a file the index does not know about is never removed again, short of a full scan
			if( !$this->index()->recordStaticFile( $relative, $page->id, $this->index()->renderTags($page) ) ){
				$this->publisher()->unpublish($path, $dirExisted ? '' : $dir);
				$this->logSkip( $page, 'could not record dependencies' );
				return;
			}

			//a save landing between the check above and the record: its drain may have looked
			//for this file before the record existed
			if( $this->queue()->genChanged() ){
				$this->index()->dropIndexRows( array( md5($relative) ) );
				$this->publisher()->unpublish($path, $dirExisted ? '' : $dir);
				$this->logSkip( $page, 'content changed while rendering' );
			}
			return;
		}

		//no index tables (no CREATE privilege, say): the manifest, and every save wipes it all
		$this->publisher()->logStaticFile($path);

		//something new is on disk again, the next save has to wipe it
		$this->queue()->noteCached();
	}

	public function stripCsrf($html) {
		//see StaticPagesPublisher::stripCsrf(). Public API: site hooks writing their own
		//static files call it
		return $this->publisher()->stripCsrf($html);
	}

	public function wipeCaches( $forceScan = false ){
		//removes every static file, right now, in this request: the operator's and the deploy
		//script's tool. Saves go through the queue instead, see enqueue().
		//$forceScan walks the site root and also finds files nothing recorded.
		//Without it the index is used, or the manifest where there are no index tables, or the
		//scan after all where neither exists

		$start = microtime(true);
		ob_start();

		$this->queue()->bumpGen();

		if( $forceScan )
			$result = $this->wiper()->wipeScan();

		else if( $this->index()->ensureTables() )
			$result = $this->wiper()->wipeIndexed(null);

		else if( is_file( $this->manifestPath() ) )
			$result = $this->wiper()->wipeCachesManifest();

		else
			$result = $this->wiper()->wipeScan();

		//a render that read the database before the wipe and records after it
		$this->queue()->bumpGen();

		$duration = round( microtime(true)-$start, 2 );

echo $this->wiper()->describeWipe($result) . " Static cache cleaned in $duration s.";

		$buffer = ob_get_clean();
		$this->message($buffer);
	}

	public function drain(){
		//see StaticPagesQueue::drain(). Public API: a deploy script may call it
		return $this->queue()->drain();
	}

	public function shutdown(){
		//registered from init(). Registering once more from inside the shutdown sequence puts the
		//drain behind every shutdown function registered after this module's init(): a debug bar
		//rendering its output there would lose it once the response is finished
		register_shutdown_function(array($this, 'shutdownDrain'));
	}

	public function shutdownDrain(){
		return $this->queue()->shutdownDrain($this->adminRequest);
	}

	public function checkTrackedPaths( $force = false ){
		//see StaticPagesTracker::checkTrackedPaths(). Public so a deploy script can close the
		//gap admin only checking leaves: $modules->get('StaticPages')->checkTrackedPaths(true);
		return $this->tracker()->checkTrackedPaths($force);
	}

	public function defaultNonStaticDirs(){
		//see StaticPagesWiper::defaultNonStaticDirs(). StaticPages.config.php asks for it
		return $this->wiper()->defaultNonStaticDirs();
	}

////  helpers shared by the parts

	private function isCacheableRequest(){
		//the request side of cachePage()'s checks, as far as ready() can tell already
		if( $this->adminRequest ) return false;
		if( $this->wire('user')->isLoggedin() ) return false;
		if( $this->wire('config')->ajax ) return false;
		$input = $this->wire('input');
		if( count($input->get) > 0 || count($input->post) > 0 ) return false;
		return true;
	}

	/** #pw-internal */
	public function logDrain($message){
		$this->wire('log')->save( 'static-pages', $message );
	}

	/** #pw-internal */
	public function logSkip($page, $reason){
		//off by default: a busy front end would write one line per request
		if( !$this->configValue('logSkips', 0) ) return;
		$this->wire('log')->save( 'static-pages', "skip {$page->path}: $reason" );
	}

	/** #pw-internal */
	public function rootPath(){
		//filesystem path to the site root, no trailing slash
		return rtrim( $this->wire('config')->paths->root, '/\\' );
	}

	/** #pw-internal */
	public function manifestPath(){
		return $this->wire('config')->paths->cache . 'StaticPages.manifest';
	}

	/** #pw-internal */
	public function linesToArray($value){
		//textarea values arrive with \r\n line ends; a stray \r or an empty line
		//would silently disable the wipe guards
		$lines = preg_split( '/\R/', (string) $value );
		$lines = array_map( 'trim', $lines );
		return array_values( array_filter($lines, 'strlen') );
	}

	/** #pw-internal */
	public function nonStaticDirsArray(){
		//never saved: the same default the config screen offers, so the full scan never
		//runs unguarded through site/, wire/ and the like
		$value = $this->nonStaticDirs;
		if( $value === null ) return $this->wiper()->defaultNonStaticDirs();
		return $this->linesToArray($value);
	}

	/** #pw-internal */
	public function configValue($key, $default){
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

	/** #pw-internal */
	public function trackedHashDir(){
		//one hash file per tracked line, next to the manifest. Recreated on every call: the
		//shell wipes remove empty dirs and a deploy script may clear the whole PW cache dir.
		//Returns '' when it can not be created

		$dir = $this->wire('config')->paths->cache . 'StaticPages/';

		if( is_dir($dir) ) return $dir;
		if( !$this->wire('files')->mkdir($dir, true) ) return '';

		return $dir;
	}

	/** #pw-internal */
	public function normalizePath($path){
		return str_replace( array('\\', '/'), DIRECTORY_SEPARATOR, $path );
	}

}
