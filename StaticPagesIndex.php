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

Composite tags, emitted by a save next to those, for finds recorded by what they filter on:
  <scope>/<what>  scope t:<id>, par:<id>, tree:<id> or *; what f:<field id>, n:<native name>,
                  s (created, deleted, trashed, restored, status, parent or template changed),
                  m (every save)
  x:<what>        something about a page a find reaches through a subfield (never x:m)
whatTag() names a change or a selector field the same way on both sides; every composite tag
fits the 40 characters of the tag column, whatever the page or field id.

A find gets composite tags only with Narrow finds on (narrowTags()): its scope, or *, times s
and what it filters and sorts on, plus x:<what> for what it reaches through a page reference,
parent. or children.; a find not understood gets 'any'. Off, it gets the scope alone. With no
parent, template or tree limit, a find asking a custom field for a value is limited to the
templates carrying that field (derivedScopes()); a fieldgroup save, a new template and a
template given another fieldgroup wipe every file, since such a scope may then be too narrow.
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

	//the finds of this request, array(Selectors, count) each, turned into tags only when a
	//file is recorded: see pagesFound() and findTags()
	protected $finds = array();

	//page paths a selector carried, resolved once per request: path => id
	protected $pathIds = array();

	//more finds than this in one render: the file depends on 'any' instead
	const maxFinds = 2000;

	//more tags than this for one find with Narrow finds on: its scope is *, then 'any'
	const maxFindTags = 64;

	//a field carried by more templates than this gives no derived scope, see derivedScopes()
	const maxDerivedTemplates = 20;

	//the fieldtypes whose match query in core 3.0.274 was read for derivedScopes(), by exact
	//class: FieldtypeToggle matches pages lacking the field for a value it does not know, and a
	//subclass, a language variant or a fieldtype of a site may override the query alike (Q65)
	const derivedFieldtypes = array('FieldtypeText', 'FieldtypeTextarea', 'FieldtypePageTitle', 'FieldtypeEmail', 'FieldtypeURL', 'FieldtypeInteger', 'FieldtypeFloat', 'FieldtypeDecimal', 'FieldtypeCheckbox', 'FieldtypeDatetime', 'FieldtypePage', 'FieldtypeOptions');

	//the ids of the templates carrying a field, field id => ids, until a fieldgroup or template
	//save: see fieldTemplateIds()
	protected $fieldTemplates = array();

	public function __construct(StaticPages $sp) {
		parent::__construct();
		$this->sp = $sp;
	}

////  what a render depends on, what a save invalidates

	public function collect($on){
		//switched on from init() for every request that may be cached, off from ready() for
		//those that are not: what was collected so far is dropped
		$this->collecting = (bool) $on;
		if( !$this->collecting ){
			$this->deps = array();
			$this->finds = array();
		}
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

		//the selector is kept, not resolved: resolving a path costs a query, and this runs on
		//every guest find of every request, most of which end up not cached at all (a 404, a
		//skip, a page already on disk for everybody else). findTags() resolves them, once per
		//request, when a file is actually recorded
		if( isset($this->deps['any']) ) return;
		if( count($this->finds) >= self::maxFinds ){
			$this->deps['any'] = true;
			$this->finds = array();
			return;
		}
		$this->finds[] = array( $items->getSelectors(), $items->count() );
	}

	public function findTags($page = null){
		//the tags of every find kept by pagesFound(), tag => true. Given the page and with Log
		//skipped pages on, each selector that fell back to 'any' is logged, once per call
		$tags = array();
		$logged = ( $page instanceof Page && $this->sp->configValue('logSkips', 0) ) ? array() : null;
		$this->resolving = true;
		try {
			foreach( $this->finds as $find ){
				try {
					$found = $this->selectorTags( $find[0], $find[1] );
				}
				catch( \Throwable $e ){
					$found = array('any');
				}
				foreach( $found as $tag ) $tags[$tag] = true;
				if( $logged === null || !in_array('any', $found, true) ) continue;
				$selector = $find[0] === null ? '' : (string) $find[0];
				if( isset($logged[$selector]) ) continue;
				$logged[$selector] = true;
				$this->wire()->log->save( 'static-pages', "any {$page->path}: a find this module does not understand, selector \"$selector\"" );
			}
		}
		finally {
			$this->resolving = false;
		}
		return $tags;
	}

	public function selectorTags($selectors, $count){
		//the tags of one find. Narrow finds on: by what it filters and sorts on, narrowTags().
		//Off: by its scope alone, scopeTags(), as before 0.13.0
		if( $this->sp->configValue('narrowFinds', 0) ) return $this->narrowTags($selectors, $count);
		return $this->scopeTags($selectors, $count);
	}

	public function scopeTags($selectors, $count){
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

	public function narrowTags($selectors, $count){
		//the tags of one find as <scope>/<what> and x:<what>: the scope of scopeTags(), or * when
		//it has none, times s and what every selector field and sort= value names. Whatever is
		//not understood makes the find depend on 'any', which only ever invalidates more

		if( !$selectors instanceof Selectors ) return array('any');

		//a find by id, or by a path or name that had a result, with no scope: its pages are
		//tagged p:, and nothing else can enter it, whatever else it asks
		$scopes = $this->scopeTags($selectors, $count);
		if( !count($scopes) ) return array();
		//no parent, template or tree limit: the templates carrying a field it asks a value of,
		//else every page may enter it
		if( $scopes === array('any') ) $scopes = $this->derivedScopes($selectors);

		$whats = array('s' => true);
		$foreign = array();
		foreach( $selectors as $selector ){
			if( !$selector instanceof Selector ) return array('any');
			//an OR-group (a=1), (b=2) and a sub-selector value field=[a=1]
			if( $selector->quote === '(' || $selector->quote === '[' ) return array('any');
			$fields = array_map( 'strval', $selector->fields() );
			//sort=: the order, by the fields it names; sort with another operator is the column
			$names = $fields === array('sort') && $selector->operator() === '=' ? $this->sortNames($selector) : $fields;
			foreach( $names as $name ) if( !$this->fieldWhats($name, $whats, $foreign) ) return array('any');
		}

		return $this->budgetTags( $scopes, array_keys($whats), array_keys($foreign) );
	}

	public function derivedScopes(Selectors $selectors){
		//t:<id> for each template carrying a field the find asks a value of (requiredField()):
		//only a page having the field can match. The smallest such list; none, or one over
		//maxDerivedTemplates: * (every page may enter the find)
		$lists = array();
		foreach( $selectors as $selector ){
			$field = $this->requiredField($selector);
			if( $field ) $lists[] = $this->fieldTemplateIds($field);
		}
		$ids = $this->smallestScope($lists);
		return $ids === null ? array('*') : array_map( fn($id) => "t:$id", $ids );
	}

	public function requiredField($selector){
		//the custom field of a selector only pages having it can match, null for any other.
		//Core's PageFinder (PageFinder.php:1915-2017, PageFinder2.php:2081-2178, the same) joins
		//a field's table with an inner join for =, %=, *= and ~= when the selector is not
		//negated, names one field and no subfield, and has no value the fieldtype calls empty:
		//an empty value goes through whereEmptyValuePossible(), which matches pages lacking the
		//field. Holds for the match queries of derivedFieldtypes
		if( !$selector instanceof Selector || $selector->not ) return null;
		if( $selector->quote === '(' || $selector->quote === '[' ) return null;
		if( !in_array( $selector->operator(), array('=', '%=', '*=', '~='), true ) ) return null;
		$fields = $selector->fields();
		if( count($fields) !== 1 ) return null;
		$name = (string) reset($fields);
		if( !preg_match( '/^[a-z_][a-z0-9_]*$/i', $name ) ) return null;
		$field = $this->wire()->fields->get($name);
		if( !$field instanceof Field || !$field->id ) return null;
		$type = $field->type;
		if( !$type instanceof Fieldtype || !in_array( $type->className(), self::derivedFieldtypes, true ) ) return null;
		$values = $selector->values(true);
		if( !count($values) ) return null;
		foreach( $values as $value ){
			if( !is_scalar($value) || (string) $value === '' || $type->isEmptyValue($field, $value) ) return null;
		}
		return $field;
	}

	public function fieldTemplateIds(Field $field){
		//the ids of the templates whose fieldgroup carries $field, one query per field until
		//forgetFieldTemplates()
		$id = (int) $field->id;
		if( !isset($this->fieldTemplates[$id]) ){
			$ids = array();
			foreach( $field->getTemplates() as $template ) $ids[] = (int) $template->id;
			sort($ids);
			$this->fieldTemplates[$id] = $ids;
		}
		return $this->fieldTemplates[$id];
	}

	public function forgetFieldTemplates(){
		//a fieldgroup or template was saved: which template carries which field may have changed
		$this->fieldTemplates = array();
	}

	public function smallestScope(array $lists){
		//the shortest of lists of template ids, null when there is none or it holds more than
		//maxDerivedTemplates. An empty list (a field on no template) is passed over: * is never
		//narrower than what a find matches
		$best = null;
		foreach( $lists as $ids ){
			$ids = array_values( (array) $ids );
			if( !count($ids) ) continue;
			if( $best === null || count($ids) < count($best) ) $best = $ids;
		}
		if( $best === null || count($best) > self::maxDerivedTemplates ) return null;
		return $best;
	}

	public function sortNames(Selector $selector){
		//the fields a sort= orders by: a leading - stripped, random orders by nothing
		$names = array();
		foreach( $selector->values(true) as $value ){
			$value = trim( (string) $value );
			if( $value !== '' && $value[0] === '-' ) $value = substr( $value, 1 );
			if( $value === '' || $value === 'random' ) continue;
			$names[] = $value;
		}
		return $names;
	}

	public function fieldWhats($name, array &$whats, array &$foreign){
		//what one selector field or sort= value filters on, into $whats (for <scope>/<what>) and
		//$foreign (x:<what>, keys each). false: not understood, the find depends on 'any'

		$segments = explode( '.', (string) $name );
		if( count($segments) > 2 ) return false;
		$first = $segments[0];
		$sub = count($segments) === 2 ? $segments[1] : null;
		if( $first === '' || $sub === '' ) return false;
		if( $first === 'page' && $sub === 'sort' ){
			$first = 'sort';
			$sub = null;
		}

		//modifiers, and an id that is not specific (scopeTags() took a specific one): ids never
		//change, nothing to follow
		if( in_array( $first, array('limit', 'start', 'include', 'check_access', 'checkAccess', 'get_total', 'getTotal', 'id'), true ) )
			return $sub === null;

		if( preg_match( '/^[a-z_][a-z0-9_]*$/i', $first ) ){
			$field = $this->wire()->fields->get($first);
			if( $field instanceof Field && $field->id ) return $this->customWhats($field, $sub, $whats, $foreign);
		}

		$aliases = array(
			'hasParent' => 'has_parent',
			'numChildren' => 'num_children',
			'createdUser' => 'created_users_id',
			'createdUserID' => 'created_users_id',
			'createdUsersID' => 'created_users_id',
			'modifiedUser' => 'modified_users_id',
			'modifiedUserID' => 'modified_users_id',
			'modifiedUsersID' => 'modified_users_id',
			'url' => 'path',
		);
		if( isset($aliases[$first]) ) $first = $aliases[$first];

		switch( $first ){
			case 'parent':
				//parent.<field>: the parent, another page, changed that field
				if( $sub === null ) break;
				$id = $this->fieldId($sub);
				if( !$id ) return false;
				$foreign["x:f:$id"] = true;
				return true;

			case 'children':
				//children.count, children.<field>: a child created, moved, trashed, or changed
				if( $sub === null ) return false;
				$foreign['x:s'] = true;
				if( $sub === 'count' ) return true;
				$id = $this->fieldId($sub);
				if( !$id ) return false;
				$foreign["x:f:$id"] = true;
				return true;

			case 'num_children':
				if( $sub !== null ) return false;
				$foreign['x:s'] = true;
				return true;

			case 'path':
				//a page's own path changes by a rename, which a save names n:name, or by a move,
				//s; an ancestor's rename or move is followed no further than before 0.13.0
				if( $sub !== null ) return false;
				$whats['n:name'] = true;
				return true;
		}

		//the other columns of the pages table, and the page name or status in another language
		$natives = array('name', 'status', 'parent', 'parent_id', 'template', 'templates_id', 'sort', 'sortfield', 'created', 'modified', 'published', 'created_users_id', 'modified_users_id', 'has_parent');
		if( $sub !== null ) return false;
		if( !in_array( $first, $natives, true ) && !preg_match( '/^(name|status)\d+$/', $first ) ) return false;
		$whats[ $this->whatTag($first) ] = true;
		return true;
	}

	public function customWhats(Field $field, $sub, array &$whats, array &$foreign){
		//a custom field, and a subfield of it. A subfield counts only for a field referencing
		//other pages: whatever else a field holds is saved with the page carrying it (a repeater
		//item hands its owner f:<repeater>)
		$whats[ 'f:' . (int) $field->id ] = true;
		if( $sub === null ) return true;
		$type = $field->type;
		if( !$type instanceof FieldtypePage && !( $type instanceof Fieldtype && wireInstanceOf( $type, 'FieldtypePageTable' ) ) ) return true;
		if( $sub === 'id' || $sub === 'count' ) return true;
		if( in_array( $sub, array('name', 'sort', 'created', 'published'), true ) ){
			$foreign["x:n:$sub"] = true;
			return true;
		}
		if( in_array( $sub, array('status', 'template', 'parent'), true ) ){
			$foreign['x:s'] = true;
			return true;
		}
		$id = $this->fieldId($sub);
		if( !$id ) return false;
		$foreign["x:f:$id"] = true;
		return true;
	}

	public function fieldId($name){
		//the id of the custom field named $name, 0 when there is none
		if( !preg_match( '/^[a-z_][a-z0-9_]*$/i', (string) $name ) ) return 0;
		$field = $this->wire()->fields->get($name);
		return $field instanceof Field ? (int) $field->id : 0;
	}

	public function budgetTags(array $scopes, array $whats, array $foreign){
		//every scope times every what, and the foreign tags: more than maxFindTags of them, the
		//scope collapses to *; still more, the find depends on 'any'
		if( count($scopes) * count($whats) + count($foreign) > self::maxFindTags ) $scopes = array('*');
		if( count($scopes) * count($whats) + count($foreign) > self::maxFindTags ) return array('any');
		$tags = array();
		foreach( $scopes as $scope ) foreach( $whats as $what ) $tags[] = "$scope/$what";
		return array_merge( $tags, $foreign );
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
		//a listing asks for the same parent path in every find: one query per path and request
		if( !isset($this->pathIds[$value]) )
			$this->pathIds[$value] = (int) $this->wire()->pages->getByPath( $value, array('getID' => true, 'useLanguages' => true, 'allowPartial' => false) );
		return $this->pathIds[$value];
	}

	public function renderTags(Page $page){
		//everything the request collected, for the file cachePage() just wrote

		$tags = $this->deps + $this->findTags($page);

		//pages loaded before collecting started, by modules initialised before this one
		foreach( array_keys( (array) $this->wire()->pages->getCache() ) as $id ) $tags['p:' . (int) $id] = true;

		//the page and its ancestors: a breadcrumb needs no find, and a rename or a move of an
		//ancestor changes this url
		for( $p = $page, $i = 0; $p && $p->id && $i < 100; $p = $p->parent, $i++ ) $tags["p:{$p->id}"] = true;

		unset( $tags['p:0'] );

		if( count($tags) > StaticPages::maxTags ) return array('*');

		return array_keys($tags);
	}

	public function invalidatePage(Page $page, array $changes, $depth = 0, $created = false, array $carried = array()){
		//$created: a new page, from Pages::added, a structural save whatever $changes holds.
		//$carried: whats a repeater item hands its owner, the repeater field among them

		if( !$page->id ) return;

		//a template listed in Never invalidate: nothing is queued, so no generation bump and no
		//drain either. Asked once, before anything else: a repeater item counts as its owner's
		if( !$depth && $this->ignoresSave($page) ) return;

		//a repeater item is part of the content of the page owning it, and of nothing else. The
		//owner is not created by a new item: it gets the item's changes and the repeater field
		if( $page instanceof RepeaterPage ){
			$this->sp->queue()->enqueue( StaticPages::modeTags, array("p:{$page->id}") );
			$owner = $page->getForPage();
			if( $depth < 10 && $owner instanceof Page && $owner->id && $owner->id !== $page->id )
				return $this->invalidatePage( $owner, $changes, $depth + 1, false, array_merge( $carried, $this->repeaterWhats($page) ) );
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

		$listed = !$hidden || count($structural) || $created;
		$whats = $this->saveWhats( $changes, $created, $carried );

		if( !$system && $listed )
			$tags = array_merge( $tags, $this->listingTags($page), $this->changeTags($page, $whats) );

		//a find reaching this page through a subfield, of any page: a user page as well
		if( $listed ) $tags = array_merge( $tags, $this->foreignTags($whats) );

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

	public function changeTags(Page $page, array $whats){
		//every scope of listingTags() and *, times every what of the save: <scope>/<what>
		$scopes = array_values( array_unique( array_diff( $this->listingTags($page), array('any') ) ) );
		$scopes[] = '*';
		$tags = array();
		foreach( $scopes as $scope ) foreach( $whats as $what ) $tags[] = "$scope/$what";
		return $tags;
	}

	public function foreignTags(array $whats){
		//x:<what> for a find reaching the page through a subfield. A foreign modified makes that
		//find depend on 'any', so no file carries x:m
		$tags = array();
		foreach( $whats as $what ) if( $what !== 'm' ) $tags[] = "x:$what";
		return $tags;
	}

	public function saveWhats(array $changes, $created = false, array $carried = array()){
		//what a save changed, named by whatTag(): m always, s for a new page
		$whats = array('m' => true);
		if( $created ) $whats['s'] = true;
		foreach( $changes as $change ) $whats[ $this->whatTag($change) ] = true;
		foreach( $carried as $what ) $whats[ (string) $what ] = true;
		unset( $whats[''] );
		return array_keys($whats);
	}

	public function repeaterWhats(RepeaterPage $item){
		//the repeater field holding $item: a selector on rep.title sees f:<rep>
		$field = $item->getForField();
		return $field instanceof Field && $field->id ? array( 'f:' . (int) $field->id ) : array();
	}

	public function whatTag($name){
		//one name for a changed property or a selector field, on the save and the render side.
		//At most 24 characters: behind the longest scope, tree:4294967295/, a tag fits 40
		$name = (string) $name;

		//the page name or status in another language
		if( preg_match( '/^(name|status)\d+$/', $name, $m ) ) $name = $m[1];

		if( preg_match( '/^[a-z_][a-z0-9_]*$/i', $name ) ){
			$field = $this->wire()->fields->get($name);
			if( $field instanceof Field && $field->id ) return 'f:' . (int) $field->id;
		}

		$lower = strtolower($name);
		if( in_array( $lower, array('status', 'parent', 'parent_id', 'template', 'templates_id'), true ) ) return 's';
		if( $lower === 'modified' || $lower === 'modified_users_id' ) return 'm';
		if( preg_match( '/^[a-z0-9_]{1,22}$/', $lower ) ) return "n:$lower";
		return 'n:' . substr( md5($lower), 0, 16 );
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
		//init(), and the operator names templates and fields whose content ends up everywhere.
		//A template in Never invalidate never gets here, see ignoresSave(): the fields below
		//are asked after that list, the templates above before it

		$template = $page->template;
		if( !$template instanceof Template ) return true;
		if( $this->wipesAllTemplate($page, $template) ) return true;

		$fields = array_map( 'strtolower', $this->sp->linesToArray( $this->sp->configValue('wipeAllFields', '') ) );
		if( !count($fields) ) return false;
		$changed = array_map( fn($change) => strtolower( (string) $change ), $changes );
		return count( array_intersect($fields, $changed) ) > 0;
	}

	public function wipesAllTemplate(Page $page, Template $template){
		//a language page, or a template listed in Wipe everything on save
		if( $this->isLanguage($page, $template) ) return true;
		$templates = array_map( 'intval', (array) $this->sp->wipeAllTemplates );
		return in_array( (int) $template->id, $templates, true );
	}

	public function ignoresSave(Page $page){
		//whether a save, a deletion or a trashing of $page invalidates nothing at all: its
		//template, or for a repeater item its owner's, is listed in Never invalidate on save.
		//The operator signs that a page of such a template shown on a cached page stays stale
		//there. A language page and a template also listed in Wipe everything still wipe
		//everything: the broader rule wins, and saving the config warns about the overlap

		//absent until the config is saved once: an empty list, as before the setting existed
		$ignored = array_filter( array_map( 'intval', (array) $this->sp->configValue('ignoreTemplates', array()) ) );
		if( !count($ignored) ) return false;

		$owner = $this->saveOwner($page);
		if( !$owner ) return false;

		$template = $owner->template;
		if( !$template instanceof Template ) return false;
		if( $this->wipesAllTemplate($owner, $template) ) return false;
		if( !in_array( (int) $template->id, $ignored, true ) ) return false;

		//a page given a listed template by this save was a page of another template until now,
		//and cached pages may show it as one: judged by both sides, as listingTags() does. Core
		//keeps templatePrevious on the page object for the rest of the request, so a later save
		//of the same object there invalidates as well, which errs on the side of a wipe
		$previous = $owner->templatePrevious;
		if( $previous instanceof Template && (int) $previous->id !== (int) $template->id ){
			if( !in_array( (int) $previous->id, $ignored, true ) ) return false;
			if( $this->wipesAllTemplate($owner, $previous) ) return false;
		}

		return true;
	}

	public function saveOwner(Page $page){
		//the page whose template decides about a save of $page: $page itself, or the page a
		//repeater item belongs to, through nested repeaters as far as invalidatePage() follows
		//them (10 levels). null when the chain ends before reaching one: an item without an owner
		for( $depth = 0; $page instanceof RepeaterPage; $depth++ ){
			$owner = $page->getForPage();
			if( $depth >= 10 || !$owner instanceof Page || !$owner->id || $owner->id === $page->id ) return null;
			$page = $owner;
		}
		return $page;
	}

	public function isLanguage(Page $page, Template $template){
		//by class and by the template the languages API was built on, not by a template named
		//'language': a site template of that name is not a language, and LanguageSupport
		//keeps its template in the Languages object
		if( $page instanceof Language ) return true;
		$languages = $this->wire()->languages;
		if( !$languages instanceof Languages ) return false;
		$languageTemplate = $languages->getTemplate();
		return $languageTemplate instanceof Template && (int) $languageTemplate->id === (int) $template->id;
	}

////  the index

	public function ensureTables($verify = false){
		//created when first needed: an install upgraded from the manifest has no install step to
		//create them in, and neither has a site sharing this module dir through a link. Brought
		//up to schemaVersion when older, see upgradeSchema(). InnoDB whatever $config->dbEngine
		//says, a record is a transaction.
		//False when they can not be created or migrated (no CREATE or ALTER privilege), or when a
		//newer module version left them in a layout this one does not know: the module falls
		//back to the manifest and to wiping everything on every save.
		//The check (three CREATE TABLE IF NOT EXISTS and an information_schema query) runs once:
		//its outcome is kept in the module config as indexSchema, and every later request takes
		//that for granted without a query. Install and upgrade check again ($verify), and so
		//does the request after a query found a table missing, see tablesFailed(). The layout
		//version itself stays in the table comments, see readSchemaVersion()

		if( $this->tablesReady !== null && !$verify ) return $this->tablesReady;

		if( !$verify && (int) $this->sp->get('indexSchema') === StaticPages::schemaVersion ){
			$this->tablesReady = true;
			return true;
		}

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
		$this->rememberSchema( StaticPages::schemaVersion );

		return true;
	}

	public function rememberSchema($version){
		//indexSchema in the module config, see ensureTables(). Stored past Modules::saveConfig
		//hooks: a bookkeeping key of this module is no config save anybody has to react to, and
		//it may be written from a front end request. null removes it
		try {
			$this->wire()->modules->configs->saveConfig( $this->sp, 'indexSchema', $version );
			if( $version === null ) $this->sp->remove('indexSchema');
			else $this->sp->set('indexSchema', (int) $version);
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'could not store indexSchema: ' . $e->getMessage() );
		}
	}

	public function isMissingTable(\Throwable $e){
		//SQLSTATE 42S02, base table or view not found: a table dropped or a dump restored
		//without it, while indexSchema still says the tables are there
		return $e instanceof \PDOException && (string) $e->getCode() === '42S02';
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

		//a manifest line names an HTML copy or nothing of ours: no other name is adopted from
		//it. No valid name: the manifest stays for a later import
		$staticFile = $this->sp->staticFileName();
		if( $staticFile === '' ) return;

		$imported = true;
		foreach( array_unique($lines) as $relative ){
			if( basename( (string) $relative ) !== $staticFile ) continue;
			if( !$this->recordStaticFile( $relative, 0, array('*') ) ) $imported = false;
		}

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
			if( $this->isMissingTable($e) ) $this->tablesFailed($e);
			return false;
		}
	}

	public function hasFile($relative){
		//whether the index has a row for this root relative path. A failed query answers false:
		//a file not known to be the module's own is never written over
		try {
			$query = $this->wire()->database->prepare('SELECT 1 FROM `' . StaticPages::tableFiles . '` WHERE path_hash = UNHEX(:hash) LIMIT 1');
			$query->bindValue(':hash', md5( (string) $relative ));
			$query->execute();
			$found = $query->fetchColumn() !== false;
			$query->closeCursor();
			return $found;
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( "could not look up $relative: " . $e->getMessage() );
			if( $this->isMissingTable($e) ) $this->tablesFailed($e);
			return false;
		}
	}

	public function hasFileBelow($prefix){
		//whether the index has a row whose path starts with $prefix ('/<dir>/'), byte for byte:
		//letter case counts whatever the column's collation, LIKE wildcards in $prefix are
		//literal [decided by user, Q49 D (a)]. One LIMIT 1 query. A failed query answers false:
		//the dir stays protected
		$prefix = (string) $prefix;
		if( $prefix === '' ) return false;
		$like = strtr( $prefix, array('!' => '!!', '%' => '!%', '_' => '!_') ) . '%';
		try {
			$query = $this->wire()->database->prepare('SELECT 1 FROM `' . StaticPages::tableFiles . "` WHERE CAST(path AS BINARY) LIKE CAST(:prefix AS BINARY) ESCAPE '!' LIMIT 1");
			$query->bindValue(':prefix', $like);
			$query->execute();
			$found = $query->fetchColumn() !== false;
			$query->closeCursor();
			return $found;
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( "could not look up files below $prefix: " . $e->getMessage() );
			if( $this->isMissingTable($e) ) $this->tablesFailed($e);
			return false;
		}
	}

	public function tablesKnownReady(){
		//whether the index tables are there as far as this request knows without a query:
		//checked this request, or indexSchema says so (see ensureTables()). Never creates them
		if( $this->tablesReady !== null ) return $this->tablesReady;
		return (int) $this->sp->get('indexSchema') === StaticPages::schemaVersion;
	}

	public function fileCounts(){
		//for the settings form only, one query: the files the index knows ('total'), those
		//carrying 'any', a find not understood ('any'), and those carrying '*' ('*'): nearly any
		//page save removes both. Null when the query fails
		try {
			$query = $this->wire()->database->prepare(
				'SELECT (SELECT COUNT(*) FROM `' . StaticPages::tableFiles . '`) AS n_total, ' .
				'(SELECT COUNT(*) FROM `' . StaticPages::tableTags . '` WHERE tag = :any) AS n_any, ' .
				'(SELECT COUNT(*) FROM `' . StaticPages::tableTags . '` WHERE tag = :all) AS n_all'
				);
			$query->bindValue(':any', 'any');
			$query->bindValue(':all', '*');
			$query->execute();
			$row = $query->fetch(\PDO::FETCH_ASSOC);
			$query->closeCursor();
			if( !is_array($row) ) return null;
			return array( 'total' => (int) $row['n_total'], 'any' => (int) $row['n_any'], '*' => (int) $row['n_all'] );
		}
		catch( \Throwable $e ){
			$this->sp->logDrain( 'could not count the indexed files: ' . $e->getMessage() );
			if( $this->isMissingTable($e) ) $this->tablesFailed($e);
			return null;
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

	public function tablesFailed(\Throwable $e = null){
		//a query on the tables failed after all: the rest of the request makes do without them.
		//A missing table also drops indexSchema, so the next request checks and creates again
		$this->tablesReady = false;
		if( $e && $this->isMissingTable($e) && $this->sp->get('indexSchema') !== null ) $this->rememberSchema(null);
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
