<?php
/*
StaticPages: the queue and the drain
AT
18.10.26
*/

namespace ProcessWire;

/*
A save only queues what went stale; the drain removes it once the response is out. The
invalidation generation, a stamp file changed by every queued save, keeps a render that read
the database before a save from publishing after it.
*/

class StaticPagesQueue extends Wire {

	/** @var StaticPages */
	protected $sp;

	//no index tables only: "nothing new has been cached since the last wipe", see wipeLegacy()
	protected $didWipe = false;

	//the invalidation generation this request started under, see StaticPages::cachePage()
	protected $genAtStart = '';
	protected $genBumped = false;

	//what this request queued already: a bulk save loop must not insert the same tags again
	protected $queuedTags = array();
	protected $queuedMode = 0;
	protected $drainRequested = false;

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

	public function startGen(){
		$this->genAtStart = $this->readGen();
	}

	public function noteCached(){
		//no index tables only: something new is on disk again, the next save has to wipe it
		$this->didWipe = false;
	}

	public function drain(){
		//removes what the queue says went stale. Runs from the shutdown function, after the
		//response, and may be called by a deploy script. One drain at a time: the lock is per
		//database, so two sites sharing a server do not wait for each other

		if( !$this->sp->index()->ensureTables() ) return;

		$database = $this->wire()->database;
		$lock = $this->sp->index()->lockName('drain');

		if( !$this->sp->index()->getLock($lock, StaticPages::lockWait) ){
			$this->sp->logDrain('another drain held the lock, queue left for the next one');
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

				$query = $database->prepare('SELECT id, mode, tags FROM `' . StaticPages::tableQueue . '` ORDER BY id LIMIT 10000');
				$query->execute();
				$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
				$query->closeCursor();

				if( !count($rows) ) break;

				$mode = StaticPages::modeTags;
				$tags = array();
				$maxId = 0;
				foreach( $rows as $row ){
					$mode = max( $mode, (int) $row['mode'] );
					$maxId = max( $maxId, (int) $row['id'] );
					foreach( explode( "\n", (string) $row['tags'] ) as $tag )
						if( $tag !== '' ) $tags[$tag] = true;
				}

				if( $mode >= StaticPages::modeScan ) $result = $this->sp->wiper()->wipeScan();
				else if( $mode >= StaticPages::modeAll ) $result = $this->sp->wiper()->wipeIndexed(null);
				else $result = $this->sp->wiper()->wipeIndexed( array_keys($tags) );

				$query = $database->prepare('DELETE FROM `' . StaticPages::tableQueue . '` WHERE id <= :id');
				$query->bindValue(':id', $maxId, \PDO::PARAM_INT);
				$query->execute();

				$modes[$mode] = true;
				$tagCount += count($tags);
				if( $result === null ) $total['scan'] = true;
				else foreach( array('files', 'bytes', 'dirs') as $key ) $total[$key] += $result[$key];
			}
		}

		finally {
			$this->sp->index()->releaseLock($lock);
		}

		if( !count($modes) ) return;

		$this->bumpGen();

		$what = isset($modes[StaticPages::modeScan]) ? 'full scan' : ( isset($modes[StaticPages::modeAll]) ? 'all files' : "$tagCount tag(s)" );
		$duration = round( microtime(true)-$start, 2 );
		$this->sp->logDrain( "$what: " . $this->sp->wiper()->describeWipe( $total['scan'] ? null : $total ) . " $duration s" );
	}

	public function shutdownDrain($adminRequest){
		try {
			if( !$this->drainRequested ){
				//a queue row left behind by a killed process: picked up by the next admin request
				if( !$adminRequest || !$this->hasOrphans() ) return;
			}
			$this->finishResponse();
			$this->drain();
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'failed: ' . $e->getMessage() );
		}
	}

	public function wipeLegacy(){
		//no index tables: every save wipes everything, synchronously, as before the index.
		//The flag is cleared again by cachePage() on every file written, so it means
		//"nothing new has been cached since the last wipe", not "once per request"

		if( $this->didWipe ) return;
		$this->didWipe = true;

		return $this->sp->wipeCaches();
	}

	public function enqueue($mode, array $tags = array()){
		//a save only says what went stale. The drain removes it once the response is out, so an
		//editor never waits for the unlinks. Queued at once rather than at shutdown: a process
		//killed in between leaves a row the next admin request drains, not a stale file.
		//Only what this request has not queued yet is inserted

		//first of all: a render that read the database before this save must not publish
		if( !$this->genBumped ){
			$this->bumpGen();
			$this->genBumped = true;
		}

		if( !$this->sp->index()->ensureTables() ) return $this->wipeLegacy();

		if( $mode <= $this->queuedMode ) return;

		if( $mode === StaticPages::modeTags ){
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
			$query = $this->wire()->database->prepare('INSERT INTO `' . StaticPages::tableQueue . '` (mode, tags, created) VALUES (:mode, :tags, :created)');
			$query->bindValue(':mode', (int) $mode, \PDO::PARAM_INT);
			$query->bindValue(':tags', implode("\n", $tags));
			$query->bindValue(':created', time(), \PDO::PARAM_INT);
			$query->execute();
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'could not queue, wiping everything now: ' . $e->getMessage() );
			$this->sp->index()->tablesFailed();
			return $this->wipeLegacy();
		}

		$this->drainRequested = true;
	}

////  the drain's surroundings

	public function hasOrphans(){
		//no table yet means nothing queued, and this must not create one on every admin request
		try {
			$query = $this->wire()->database->prepare('SELECT 1 FROM `' . StaticPages::tableQueue . '` WHERE created < :time LIMIT 1');
			$query->bindValue(':time', time() - StaticPages::orphanAge, \PDO::PARAM_INT);
			$query->execute();
			$found = (bool) $query->fetchColumn();
			$query->closeCursor();
			return $found;
		}
		catch( \Throwable $e ){
			return false;
		}
	}

	public function finishResponse(){
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

	public function genPath(){
		return $this->wire()->config->paths->cache . 'StaticPages/gen';
	}

	public function readGen(){
		//a read caught halfway through a write reads as changed, the safe side
		$gen = @file_get_contents( $this->genPath() );
		return $gen === false ? '' : $gen;
	}

	public function bumpGen(){
		if( $this->sp->trackedHashDir() === '' ) return;
		@file_put_contents( $this->genPath(), uniqid('', true), LOCK_EX );
	}

	public function genChanged(){
		return $this->readGen() !== $this->genAtStart;
	}

}
