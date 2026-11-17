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
  StaticPagesMigration.php  bringing an older install up to date
  StaticPagesHtaccess.php   the root .htaccess: the rules, reading the file, writing the block

Hooks stay registered on this class: site code looks them up by their toObject.
*/

class StaticPages extends WireData implements Module, ConfigurableModule {

	//real properties, not config keys: WireData's magic __get/__set has no business here

	//the parts, see index(), queue(), publisher(), wiper(), tracker(), migration(), htaccess()
	private $index = null;
	private $queue = null;
	private $publisher = null;
	private $wiper = null;
	private $tracker = null;
	private $migration = null;
	private $htaccess = null;

	private $adminRequest = false;

	//a CLI run: the .htaccess check runs after it as after a superuser's admin request. A
	//property so that the smoke test can stand for a web request under CLI
	private $cli = PHP_SAPI === 'cli';

	//what a settings save asked of the .htaccess, taken by hookBeforeSaveConfig() before the
	//one shot values are cleared, acted on by hookAfterSaveConfig()
	private $htaccessSave = null;

	//set by noCache(): false, true, or the reason given as a string. Lives as long as the request
	private $noCache = false;

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

	//Page::render hook priority when none is stored: after the output transformers
	const priorityDefault = 1000;

	//the layout of the stored module config. Raised with every change older installs have to
	//be brought through, see StaticPagesMigration
	const configVersion = 1;

	//the tags holding a CSRF token in the @atispro/core markup, one regex per line: the
	//<meta name=csrf-token> pair and <input rel=csrf>. See StaticPagesPublisher::stripCsrf()
	const csrfPatternsDefault = '/<meta\b[^>]*\bname=([\x22\x27]?)csrf-token(?:-name)?\1(?=[\s\/>])[^>]*>/i' . "\n" . '/<input\b[^>]*\brel=([\x22\x27]?)csrf\1(?=[\s\/>])[^>]*>/i';

	public function init() {
		$this->addHookBefore('Modules::saveConfig', $this, 'hookBeforeSaveConfig');
		$this->addHookAfter('Modules::saveConfig', $this, 'hookAfterSaveConfig');
		//the settings form's default for nonStaticDirs, filled in only when the form is built
		$this->addHookAfter('Modules::getModuleConfigInputfields', $this, 'hookConfigInputfields');

		//012 and 020 installs never stored a priority, and (int) null hooked the cache at 0,
		//ahead of every output transformer. The default the config screen offers, also for
		//site code reading $modules->get('StaticPages')->priority
		if( $this->get('priority') === null ) $this->set('priority', self::priorityDefault);

		if( !$this->isEnabled ) return;

		$this->queue()->startGen();

		//every page a render loads is a dependency of the file that render produces: recording
		//them costs a hash write per load, working them out at save time would cost more than
		//the wipe. Collecting from init() rather than ready(), so pages other modules load in
		//their ready() are seen too; ready() stops it for requests that are never cached.
		//A CLI script would only grow the set
		$this->index()->collect( PHP_SAPI !== 'cli' );
		$this->addHookAfter('Page::loaded', $this, 'hookLoaded');
		$this->wire()->pages->addHookAfter('found', $this, 'hookFound');

		$this->addHookAfter('Page::render', $this, 'hookCache', array('priority' => (int) $this->priority));

		//after the fact, with the changed field names: a save changing nothing removes nothing
		$this->wire()->pages->addHookAfter('saved', $this, 'hookSaved');
		$this->wire()->pages->addHookAfter('deleted', $this, 'hookDeleted');
		$this->wire()->pages->addHookAfter('sorted', $this, 'hookSorted');
		//descendants have their trash status set in SQL, no save and no hook reaches them
		$this->wire()->pages->addHookAfter('trashed', $this, 'hookTrashed');
		$this->wire()->pages->addHookAfter('restored', $this, 'hookTrashed');

		//a single field save never reaches Pages::saved, inline admin edits and setAndSave() would leave stale files
		if( $this->configValue('wipeOnSaveField', 1) ) $this->wire()->pages->addHookAfter('savedField', $this, 'hookSavedField');

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

		try {
			$this->showPendingNotices();
		}
		catch( \Throwable $e ){
			$this->error( 'StaticPages notices: ' . $e->getMessage() );
		}
	}

	public function ___install() {
		//an upgrade has no install step, ensureTables() creates them when first needed as well
		$this->index()->ensureTables(true);
		//the root .htaccess: the block goes in when there is none and no hand-written rules
		//either. Never in the way of the install
		try {
			$this->htaccess()->onInstall();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'htaccess on install: ' . $e->getMessage() );
		}
	}

	public function ___uninstall() {
		//the static copies by the index and the block out of the root .htaccess, before the
		//tables go [decided by user, Q13 (a)]. onUninstall() catches its own failures
		try {
			$this->htaccess()->onUninstall();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'uninstall: ' . $e->getMessage() );
		}
		$this->index()->dropTables();
	}

	public function ___upgrade($fromVersion, $toVersion) {
		//core skips this when the module file was renamed (.module to .module.php, as in 0.8.0)
		//and without a modules refresh: nothing may depend on it. The same steps run lazily, see
		//shutdownDrain()
		$this->index()->ensureTables(true);
		$this->migration()->run();
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

	/**
	 * Bringing an older install up to date
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesMigration
	 *
	 */
	public function migration() {
		if( $this->migration === null ){
			require_once(__DIR__ . '/StaticPagesMigration.php');
			$this->migration = $this->wire(new StaticPagesMigration($this));
		}
		return $this->migration;
	}

	/**
	 * The rules for the root .htaccess
	 *
	 * #pw-internal
	 *
	 * @return StaticPagesHtaccess
	 *
	 */
	public function htaccess() {
		if( $this->htaccess === null ){
			require_once(__DIR__ . '/StaticPagesHtaccess.php');
			$this->htaccess = $this->wire(new StaticPagesHtaccess($this));
		}
		return $this->htaccess;
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
		//SQL alone: no save, no hook, and a listing of them elsewhere never loaded the page. A
		//template listed in Never invalidate on save queues nothing here either
		$page = $event->arguments(0);
		if( (int) $page->numChildren < 1 ) return;
		if( $this->index()->ignoresSave($page) ) return;
		return $this->queue()->enqueue(self::modeAll);
	}

	public function hookConfigInputfields($event) {
		//the config file cannot compute the default list (see there): offer it on the form, so a
		//never saved install shows, and saves, what nonStaticDirsArray() uses
		if( wireClassName( $event->arguments(0), false ) !== $this->className() ) return;
		$form = $event->return;
		if( !$form instanceof InputfieldWrapper ) return;
		$field = $form->getChildByName('nonStaticDirs');
		if( $field && trim( (string) $field->attr('value') ) === '' ) $field->attr( 'value', implode( "\n", $this->defaultNonStaticDirs() ) );
		$this->addHtaccessStatus($form);
	}

	private function addHtaccessStatus(InputfieldWrapper $form){
		//what the root .htaccess holds: read here, never from StaticPages.config.php. Names
		//starting with "_" are neither stored by ProcessModule nor config keys. Whatever the file
		//holds, the form still builds
		$modules = $this->wire()->modules;
		$sanitizer = $this->wire()->sanitizer;
		$e = function($text) use ($sanitizer) { return $sanitizer->entities( (string) $text ); };

		$status = null;
		try {
			$status = $this->htaccessStatus();
		}
		catch( \Throwable $t ){
			$html = '<p>' . $e( sprintf( $this->_('The .htaccess could not be checked: %s'), $t->getMessage() ) ) . '</p>';
		}

		if( $status ){
			$says = array(
				'none' => $this->_('There is no .htaccess in the site root: the web server is not Apache, or its rules are kept elsewhere. StaticPages never creates one. The block below shows what the rules do.'),
				'locked' => $this->_('StaticPages cannot change the .htaccess.'),
				'damaged' => $this->_('The StaticPages block in the .htaccess is damaged. Nothing is written until it is mended by hand.'),
				'unrecognised' => $this->_('The .htaccess is not recognised as ProcessWire\'s. Nothing is written.'),
				'candidate' => $this->_('The .htaccess holds hand-written rules that look like StaticPages rules. Nothing is written without your choice.'),
				'absent' => $this->_('The .htaccess has no StaticPages block yet.'),
				'current' => $this->_('The StaticPages block in the .htaccess is up to date.'),
				'outdated' => $this->_('The StaticPages block in the .htaccess differs from the one these settings generate.'),
				);
			$state = $status['state'];
			$html = '<p><strong>' . $e( sprintf( $this->_('State: %s'), $state ) ) . '</strong> ' . $e( isset($says[$state]) ? $says[$state] : '' ) . '</p>';
			$html .= '<p>' . $e( $status['reason'] ) . ' <small>' . $e( $status['file'] ) . '</small></p>';

			$numbered = function(array $lines) use ($e) {
				$out = array();
				foreach( $lines as $n => $text ) $out[] = str_pad( (string) $n, 5, ' ', STR_PAD_LEFT ) . '  ' . $text;
				return '<pre>' . $e( implode( "\n", $out ) ) . '</pre>';
			};
			if( count($status['candidates']) ){
				$html .= '<p>' . $e( $this->_('Hand-written rules found (S1 names the static file, S2 hands requests to index.php beside the stock rule, S3 serves the static file through DirectoryIndex, S4 depends on the request method):') ) . '</p>';
				foreach( $status['candidates'] as $group ){
					$html .= '<p>' . $e( sprintf( $this->_('Lines %1$d to %2$d, %3$s'), $group['start'], $group['end'], implode( ', ', $group['signatures'] ) ) ) . '</p>';
					$html .= $numbered( $group['comments'] + $group['lines'] );
				}
				if( count($status['related']) ){
					$html .= '<p>' . $e( $this->_('Also commented out, never a reason by itself:') ) . '</p>' . $numbered( $status['related'] );
				}
			}
			foreach( $status['routes'] as $route ){
				$html .= '<p>' . $e( sprintf( $this->_('Line %1$d routes /%2$s to ProcessWire: a root file route, left as it is.'), $route['line'], $route['name'] ) ) . '</p>';
			}
			foreach( $status['notes'] as $note ) $html .= '<p>' . $e($note) . '</p>';
		}

		$f = $modules->get('InputfieldMarkup');
		$f->attr( 'name', '_htaccess' );
		$f->label = $this->_('Root .htaccess');
		$f->value = $html;
		$static = $form->getChildByName('_static');
		$static ? $form->insertAfter( $f, $static ) : $form->add($f);

		if( !$status ) return;
		$block = $modules->get('InputfieldMarkup');
		$block->attr( 'name', '_htaccessBlock' );
		$block->label = $this->_('The StaticPages block');
		$block->description = $this->_('What StaticPages keeps in the root .htaccess, immediately before the "# PW-PAGENAME" line, with these settings.');
		$block->collapsed = Inputfield::collapsedYes;
		$block->value = '<pre>' . $e( $status['block'] ) . '</pre>';
		$form->insertAfter( $block, $f );
	}

	public function hookBeforeSaveConfig($event) {
		//the force wipe and its dry run are one shot actions: stored checked, the boxes came
		//back checked on the next visit and any later save of the form scanned the disk again
		if( $this->configSaveClassName( $event->arguments(0) ) !== $this->className() ) return;
		$data = $event->arguments(1);
		if( !is_array($data) ) return;

		//the choice for hand-written .htaccess rules is one shot as well, and never stored. Taken
		//here with the dry run box, before that is cleared; acted on after the save. Keep stores
		//the hash of the candidates with this very save
		$changed = array_key_exists( 'htaccessAction', $data );
		$action = isset($data['htaccessAction']) ? (string) $data['htaccessAction'] : '';
		if( !in_array( $action, array('replace', 'keep'), true ) ) $action = '';
		unset( $data['htaccessAction'] );
		if( $action === 'keep' && $this->htaccessAllowed() ){
			try {
				$status = $this->htaccess()->using($data)->status();
				if( $status['state'] === 'candidate' ){
					$data['htaccessKeep'] = $this->htaccess()->keepHash($status);
					$changed = true;
				}
			}
			catch( \Throwable $e ){
				$this->logDrain( 'htaccess: Keep could not read the file: ' . $e->getMessage() );
			}
		}
		$this->htaccessSave = array( 'action' => $action, 'dryRun' => !empty($data['wipeDryRun']) );

		//the force wipe and its dry run, as before
		if( !empty($data['forceWipeOnModuleSave']) || !empty($data['wipeDryRun']) ){
			$data['forceWipeOnModuleSave'] = 0;
			$data['wipeDryRun'] = 0;
			$changed = true;
		}
		$this->htaccessSave['data'] = $data;
		if( $changed ) $event->arguments(1, $data);
	}

	private function htaccessAllowed(){
		//the root .htaccess is read and written by an admin request or a CLI run only, never by
		//a visitor's request. isAdminRequest() rather than adminRequest: that one is set only
		//while the module is enabled, and a disabled module's settings save acts as well
		return $this->cli || $this->isAdminRequest();
	}

	private function htaccessOnSave(array $data){
		$save = $this->htaccessSave;
		$this->htaccessSave = null;
		if( !$this->htaccessAllowed() ) return;
		if( $save && isset($save['data']) ) $data = $save['data'];
		try {
			$this->htaccess()->onSave( $data, $save ? $save['action'] : '', $save ? $save['dryRun'] : false );
		}
		catch( \Throwable $e ){
			$this->logDrain( 'htaccess on settings save: ' . $e->getMessage() );
			$this->error( 'StaticPages, root .htaccess: ' . $e->getMessage() );
		}
	}

	public function hookAfterSaveConfig($event) {
		//should run in backend only!
		$class = $this->configSaveClassName( $event->arguments(0) );

		//settings of this module are being saved: an explicit, one shot operator action,
		//deliberately not gated on isEnabled so a disabled module can still be told to clean up.
		//Only a save of the whole config is one: this module stores single keys of its own
		//(saveConfig with a key and a value) from any request, a front end POST among them
		if( $class === $this->className() ){
			if( !is_array( $event->arguments(1) ) ) return;
			$this->wiper()->warnPageDirsProtected( $event->arguments(1) );
			$this->publisher()->warnInvalidCsrfPatterns( $event->arguments(1) );
			$this->warnIgnoredWipeAll( $event->arguments(1) );
			$this->htaccessOnSave( $event->arguments(1) );
			if( !$this->wire()->input->post('forceWipeOnModuleSave') ) return;
			//full disk scan: also catches files the manifest does not know about. With Dry run
			//checked it only lists them
			return $this->wipeCaches( true, (bool) $this->wire()->input->post('wipeDryRun') );
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
		if( $page !== $this->wire()->page ) return;

		//skipping 404 page caching
		if( $page->id == $this->wire()->config->http404PageID ) return;

		//a static file is served with 200 for as long as it exists. A template that set
		//4xx or 5xx on this response would have that page frozen and handed out as OK
		//ever after: the http404PageID check above only catches ProcessWire's own 404
		if( $this->publisher()->responseCode() !== 200 ) return;

		//skipping logged-in users, their markup may contain private data
		if( $this->wire()->user->isLoggedin() ){
			//the most confusing of all the skips: an operator browsing the site while
			//logged in sees an installed, enabled module producing nothing and saying nothing
			$this->logSkip( $page, 'user is logged in as ' . $this->wire()->user->name );
			return;
		}

		//guest output may still be made for this one guest: a cookie the operator listed, a
		//cookie the response sets, a Vary on a request header
		$cookie = $this->publisher()->cookieSkip();
		if( $cookie !== '' ){
			$this->logSkip( $page, "the request carries the cookie $cookie, listed in Never cache with these cookies" );
			return;
		}
		$reason = $this->publisher()->headerSkip();
		if( $reason !== '' ){
			$this->logSkip( $page, $reason );
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
		if( $this->wire()->config->ajax ) return;

		//skip if get or post request
		if( count($this->wire()->input->get) > 0 || count ($this->wire()->input->post) > 0 ) return;

		//skipping paginated pages: pageNum is not part of urlSegmentStr, page 1 would be overwritten
		if( (int) $this->wire()->input->pageNum > 1 ) return;

		//site code may only veto: neither noCache() nor an allowCache hook is asked about a
		//render one of the checks above refused already
		if( $this->noCache !== false ){
			$this->logSkip( $page, 'noCache() was called' . ( is_string($this->noCache) && $this->noCache !== '' ? ": {$this->noCache}" : '' ) );
			return;
		}
		if( !$this->allowCache($page) ){
			$this->logSkip( $page, 'refused by an allowCache hook' );
			return;
		}

		//caching url segments independently
		$urlSegmentStr = $this->wire()->input->urlSegmentStr;

		//the markup is prepared before anything at all is created on disk. stripCsrf()
		//runs preg_replace, which answers null when PCRE gives up (a backtrack limit on
		//a large minified page will do it), and file_put_contents(null) returns 0 rather
		//than false: that path wrote a 0 byte index.html and logged it as a success
		$content = $this->stripCsrf($html);
		if( !strlen( (string) $content ) ){
			$this->logSkip( $page, 'markup preparation failed' );
			return;
		}

		//what is written may differ from what the visitor got: the hook sees the prepared
		//markup and its return is the file. $event->return is never touched. Still nothing on
		//disk: a hook refusing the render leaves no file and no directory behind
		$content = $this->fileContent($page, $content);
		if( !is_string($content) || $content === '' ){
			$this->logSkip( $page, 'a fileContent hook returned nothing' );
			return;
		}

		//a save queued an invalidation while this request was rendering: the markup may hold what
		//the database said before it, and the drain removing stale files may have run already
		if( $this->queue()->genChanged() ){
			$this->logSkip( $page, 'content changed while rendering' );
			return;
		}

		//a name the wipes could not match safely is a name nothing is written under either
		$staticFile = $this->staticFileName();
		if( $staticFile === '' ){
			$this->logSkip( $page, 'Static file name is not a plain file name' );
			return;
		}

		//a page path or url segment never climbs out of the site root
		$segments = explode( '/', $page->path . $urlSegmentStr );
		if( in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) return;

		$dir = $this->rootPath() . $page->path;
		if( $urlSegmentStr !='' ) $dir.= "$urlSegmentStr/";
		$path = $dir.$staticFile;

		//a file in a dir listed in Non-static directories is never deleted by any wipe: it
		//would be served stale for good. Only a saved list is asked, working out the default
		//costs a walk of the site root
		if( $this->nonStaticDirs !== null && $this->wiper()->isProtectedPath( rtrim($dir, '/\\'), $this->nonStaticDirsArray() ) ){
			$this->logSkip( $page, 'its dir is listed in Non-static directories, no wipe would ever delete the file' );
			return;
		}

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

	/**
	 * Whether the render of $page may be written to a static file
	 *
	 * Hook it to keep renders out of the cache that depend on something no URL shows: session
	 * state, a URL segment, a request flag. Asked only for a render every built-in check
	 * already accepted, so a hook can veto, never force a file. Returns true.
	 *
	 * @param Page $page The page being rendered, the one requested
	 * @return bool False keeps this render out of the cache
	 *
	 */
	public function ___allowCache(Page $page) {
		return true;
	}

	/**
	 * The markup written to the static file of $page
	 *
	 * Hook it to change the stored copy only: a CSP nonce placeholder an SSI fills in, content
	 * neutralised for everyone, a render refused as incomplete. Gets the markup after
	 * stripCsrf(); what the visitor of this request is sent is never changed. Returns $html.
	 *
	 * @param Page $page The page being rendered, the one requested
	 * @param string $html The markup about to be written
	 * @return string What is written. Anything but a non-empty string keeps this render out of the cache
	 *
	 */
	public function ___fileContent(Page $page, $html) {
		return $html;
	}

	/**
	 * Keep the current request's render out of the cache
	 *
	 * For template code: `$modules->get('StaticPages')->noCache('cart in session');`. The
	 * reason, when a string, is written to the skip log. noCache(false) clears it again.
	 *
	 * @param bool|string $reason
	 * @return $this
	 *
	 */
	public function noCache($reason = true) {
		if( $reason === false ){
			$this->noCache = false;
			return $this;
		}
		$this->noCache = is_string($reason) ? $reason : true;
		return $this;
	}

	public function stripCsrf($html) {
		//see StaticPagesPublisher::stripCsrf(). Public API: site hooks writing their own
		//static files call it
		return $this->publisher()->stripCsrf($html);
	}

	public function wipeCaches( $forceScan = false, $dryRun = false ){
		//removes every static file, right now, in this request: the operator's and the deploy
		//script's tool. Saves go through the queue instead, see enqueue().
		//$forceScan walks the site root and also finds files nothing recorded.
		//Without it the index is used, or the manifest where there are no index tables, or the
		//scan after all where neither exists.
		//$dryRun deletes nothing and writes what the same call would delete to the static-pages
		//log, one line per file and dir; the scan always takes the php walk then.
		//Returns the counts (null after a shell scan), with 'paths' and 'leftAlone' on a dry run

		$start = microtime(true);

		if( !$dryRun ) $this->queue()->bumpGen();

		if( $forceScan )
			$result = $this->wiper()->wipeScan($dryRun);

		else if( $this->index()->ensureTables() )
			$result = $this->wiper()->wipeIndexed(null, $dryRun);

		else if( is_file( $this->manifestPath() ) )
			$result = $this->wiper()->wipeCachesManifest($dryRun);

		else
			$result = $this->wiper()->wipeScan($dryRun);

		//a render that read the database before the wipe and records after it
		if( !$dryRun ) $this->queue()->bumpGen();

		$duration = round( microtime(true)-$start, 2 );

		if( $dryRun ){
			$this->logDryRun($result);
			$this->message( $this->wiper()->describeWipe($result) . " The list is in the static-pages log. $duration s." );
			return $result;
		}

		//the notice is built directly: an output buffer around the wipe took in whatever else
		//was echoed meanwhile and handed it out as part of the notice
		$this->message( $this->wiper()->describeWipe($result) . " Static cache cleaned in $duration s." );

		return $result;
	}

	private function logDryRun(array $result){
		$paths = isset($result['paths']) ? $result['paths'] : array();
		$this->logDrain( 'dry run: ' . $this->wiper()->describeWipe($result) );
		foreach( array_slice($paths, 0, 10000) as $path ) $this->logDrain( "dry run: would delete $path" );
		if( count($paths) > 10000 ) $this->logDrain( 'dry run: and ' . ( count($paths) - 10000 ) . ' more' );
		if( !empty($result['leftAlone']) )
			$this->logDrain( 'dry run: root dirs left alone, no list names them and they are no static copies of pages: ' . implode(', ', $result['leftAlone']) );
	}

	public function drain(){
		//see StaticPagesQueue::drain(). Public API: a deploy script may call it
		if( $this->migration()->pending() ) $this->migrate();
		return $this->queue()->drain();
	}

	public function shutdown(){
		//registered from init(). Registering once more from inside the shutdown sequence puts the
		//drain behind every shutdown function registered after this module's init(): a debug bar
		//rendering its output there would lose it once the response is finished
		register_shutdown_function(array($this, 'shutdownDrain'));
	}

	public function shutdownDrain(){
		//an install switched from an older version is brought up to date after the response of
		//its first admin request, CLI script, or request that queued a save, before that drain
		if( $this->migration()->pending() && ( $this->adminRequest || PHP_SAPI === 'cli' || $this->queue()->drainRequested() ) ){
			$this->queue()->finishResponse();
			$this->migrate();
		}
		$this->queue()->shutdownDrain($this->adminRequest);

		//the root .htaccess, after a superuser's admin request or a CLI run: a visitor's request
		//never reads it and pays nothing, not even a stat(). Three stat() calls while nothing
		//changed, see StaticPagesHtaccess::check(); the response is finished before anything more
		if( !$this->cli && !( $this->adminRequest && $this->wire()->user->isSuperuser() ) ) return;
		try {
			$this->htaccess()->check();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'htaccess check failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Show what the .htaccess check after an earlier request kept for a superuser
	 *
	 * Called from ready() on admin requests. A notice from the check after the response would
	 * land in a page already sent, so it waits in the module config [decided by user, step 5
	 * doubt (a)]: shown on the next admin page of a superuser that is no ajax request, then
	 * cleared. Costs a property read when there is none.
	 *
	 * #pw-internal
	 *
	 * @return int The number of notices shown
	 *
	 */
	public function showPendingNotices(){
		$pending = $this->get('htaccessNotice');
		if( !is_array($pending) || !count($pending) ) return 0;
		if( !$this->adminRequest || $this->wire()->config->ajax || !$this->wire()->user->isSuperuser() ) return 0;
		$shown = 0;
		//shown even when this request said the same already: it was kept to be shown. The flag
		//only where the core has it
		$flags = defined( Notice::class . '::allowDuplicate' ) ? Notice::allowDuplicate : 0;
		foreach( $pending as $notice ){
			if( !is_array($notice) || !isset($notice['text']) ) continue;
			$type = isset($notice['type']) ? $notice['type'] : 'message';
			$type === 'warning' ? $this->warning( (string) $notice['text'], $flags ) : $this->message( (string) $notice['text'], $flags );
			$shown++;
		}
		//past the config hooks: bookkeeping, no operator's save
		$this->wire()->modules->configs->saveConfig( $this, 'htaccessNotice', null );
		$this->remove('htaccessNotice');
		return $shown;
	}

	private function migrate(){
		try {
			$this->migration()->run();
		}
		catch( \Throwable $e ){
			$this->logDrain( 'upgrade failed, tried again on the next admin request: ' . $e->getMessage() );
		}
	}

	public function checkTrackedPaths( $force = false ){
		//see StaticPagesTracker::checkTrackedPaths(). Public so a deploy script can close the
		//gap admin only checking leaves: $modules->get('StaticPages')->checkTrackedPaths(true);
		return $this->tracker()->checkTrackedPaths($force);
	}

	/**
	 * What the root .htaccess holds: its state, the hand-written rules found, the block
	 *
	 * Reads the file, writes nothing. For deploy scripts and the settings form. See
	 * StaticPagesHtaccess::status().
	 *
	 * @return array
	 *
	 */
	public function htaccessStatus(){
		return $this->htaccess()->status();
	}

	/**
	 * Write the StaticPages block into the root .htaccess, after a backup
	 *
	 * For deploy scripts. Writes from the states absent and outdated; with $replace also from
	 * candidate: the hand-written rules found are commented out with a prefix, never deleted.
	 * See StaticPagesHtaccess::write().
	 *
	 * @param bool $dryRun Only say what would be written
	 * @param bool $replace Replace the hand-written rules found
	 * @return array
	 *
	 */
	public function writeHtaccess( $dryRun = false, $replace = false ){
		return $this->htaccess()->write($dryRun, $replace);
	}

	/**
	 * Take the StaticPages block and the prefixes of a Replace out of the root .htaccess again
	 *
	 * See StaticPagesHtaccess::remove().
	 *
	 * @param bool $dryRun
	 * @return array
	 *
	 */
	public function removeHtaccess( $dryRun = false ){
		return $this->htaccess()->remove($dryRun);
	}

	public function defaultNonStaticDirs(){
		//see StaticPagesWiper::defaultNonStaticDirs(). StaticPages.config.php asks for it
		return $this->wiper()->defaultNonStaticDirs();
	}

////  helpers shared by the parts

	private function isCacheableRequest(){
		//the request side of cachePage()'s checks, as far as ready() can tell already
		if( $this->adminRequest ) return false;
		if( $this->wire()->user->isLoggedin() ) return false;
		if( $this->wire()->config->ajax ) return false;
		$input = $this->wire()->input;
		if( count($input->get) > 0 || count($input->post) > 0 ) return false;
		if( $this->publisher()->cookieSkip() !== '' ) return false;
		return true;
	}

	/** #pw-internal */
	public function logDrain($message){
		$this->wire()->log->save( 'static-pages', $message );
	}

	/** #pw-internal */
	public function logSkip($page, $reason){
		//off by default: a busy front end would write one line per request
		if( !$this->configValue('logSkips', 0) ) return;
		$this->wire()->log->save( 'static-pages', "skip {$page->path}: $reason" );
	}

	/** #pw-internal */
	public function rootPath(){
		//filesystem path to the site root, no trailing slash
		return rtrim( $this->wire()->config->paths->root, '/\\' );
	}

	/** #pw-internal */
	public function manifestPath(){
		return $this->wire()->config->paths->cache . 'StaticPages.manifest';
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
		//runs unguarded through site/, wire/ and the like. Empty counts as never saved (the
		//field is required): the config file's default is '' and reaches the module whenever
		//no value is stored, and 0.8.0 read it as "protect nothing"
		$value = $this->nonStaticDirs;
		if( $value === null || trim( (string) $value ) === '' ) return $this->wiper()->defaultNonStaticDirs();

		$dirs = array();
		foreach( $this->linesToArray($value) as $line ){
			//a leading or trailing slash names the same dir, and used to protect nothing
			$line = trim( $line, "/\\ \t" );
			if( $line === '' ) continue;
			$dirs[] = $line;
			//"site vendor wire" on one line (an odnaco install) meant three dirs: a dir name
			//holding blanks protected nothing at all. Both readings are protected now
			if( preg_match('/\s/', $line) )
				foreach( preg_split('/\s+/', $line) as $word ) if( $word !== '' ) $dirs[] = $word;
		}
		return array_values( array_unique($dirs) );
	}

	/** #pw-internal */
	public function staticFileName(){
		//the configured name, or '' when it is not a plain file name. The name reaches find
		//-name and PowerShell -like as a pattern and the disk as a path: '*', '*.html' or
		//'../x' would turn a wipe into something else entirely. '' stops caching and every
		//wipe matching by name. Never saved: index.html, as the config screen offers
		return $this->plainFileName( $this->configValue('staticFile', 'index.html') );
	}

	/** #pw-internal */
	public function plainFileName($value){
		//$value trimmed when it is a plain file name, else ''. See staticFileName(); also asked
		//for the name a settings save is storing, see StaticPagesHtaccess::using()
		$name = trim( (string) $value );
		if( !preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$/', $name) ) return '';
		return $name;
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
		if( ctype_digit( (string) $class ) ) return (string) $this->wire()->modules->getModuleClass( (int) $class );
		return (string) $class;
	}

	private function warnIgnoredWipeAll($data){
		//a template in both lists wipes everything on save, see StaticPagesIndex::ignoresSave():
		//the operator learns on the save that Never invalidate does nothing for it
		if( !isset($data['ignoreTemplates'], $data['wipeAllTemplates']) ) return;
		$both = array_intersect(
			array_filter( array_map( 'intval', (array) $data['ignoreTemplates'] ) ),
			array_filter( array_map( 'intval', (array) $data['wipeAllTemplates'] ) )
			);
		if( !count($both) ) return;
		$names = array();
		foreach( array_unique($both) as $id ){
			$template = $this->wire()->templates->get($id);
			$names[] = $template instanceof Template ? $template->name : "#$id";
		}
		$this->warning( sprintf(
			$this->_('Templates listed in both "Wipe everything on save of these templates" and "Never invalidate on save of these templates": %s. A save of their pages wipes everything, the broader rule wins.'),
			implode(', ', $names)
			) );
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

		$config = $this->wire()->config;
		if( is_bool($config->admin) ) return $config->admin;

		$page = $this->wire()->page;
		if( !$page || !$page->id ) return false;
		if( !$this->wire()->user->isLoggedin() ) return false;

		//compared by name on purpose: $config->adminTemplates is a list of template names
		$adminTemplates = $config->adminTemplates;
		if( !is_array($adminTemplates) || !count($adminTemplates) ) $adminTemplates = array('admin');

		return in_array( $page->template->name, $adminTemplates, true );
	}

	/** #pw-internal */
	public function trackedHashDir(){
		//one hash file per tracked line, next to the manifest. Recreated on every call: the
		//shell wipes remove empty dirs and a deploy script may clear the whole PW cache dir.
		//Returns '' when it can not be created

		$dir = $this->wire()->config->paths->cache . 'StaticPages/';

		if( is_dir($dir) ) return $dir;
		if( !$this->wire()->files->mkdir($dir, true) ) return '';

		return $dir;
	}

	/** #pw-internal */
	public function normalizePath($path){
		return str_replace( array('\\', '/'), DIRECTORY_SEPARATOR, $path );
	}

}
