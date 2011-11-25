<?php

abstract class YARPP_Cache {

	public $core;
	public $score_override = false;
	public $online_limit = false;
	public $last_sql;

	function __construct( &$core ) {
		$this->core = &$core;
		$this->name = __($this->name, 'yarpp');
	}
	
	function add_signature( $query ) {
		$query->yarpp_cache_type = $this->name;
	}
	
	// Note: return value changed in 3.4
	// return YARPP_NO_RELATED | YARPP_RELATED | YARPP_DONT_RUN | false if no good input
	function enforce( $reference_ID, $force = false ) {
		if ( !$reference_ID = absint($reference_ID) )
			return false;
	
		$status = $this->is_cached($reference_ID);
		$status = apply_filters( 'yarpp_cache_enforce_status', $status, $reference_ID );
	
		// There's a stop signal:
		if ( YARPP_DONT_RUN === $status )
			return YARPP_DONT_RUN;
	
		// If not cached, process now:
		if ( YARPP_NOT_CACHED == $status || $force ) {
			$status = $this->update($reference_ID);
			// if still not cached, there's a problem, but for the time being return NO RELATED
			if ( YARPP_NOT_CACHED === $status )
				return YARPP_NO_RELATED;
		}
	
		// There are no related posts
		if ( YARPP_NO_RELATED === $status )
			return YARPP_NO_RELATED;
	
		// There are results
		return YARPP_RELATED;
	}
	
	/*
	 * POST STATUS INTERACTIONS
	 */
	
	function save_post($post_ID, $force=true) {
		global $wpdb;
	
		// new in 3.2: don't compute cache during import
		if ( defined( 'WP_IMPORTING' ) )
			return;
	
		$post = get_post( $post_ID );
		$parent_ID = $post->post_parent;
	
		if ( $parent_ID != $post_ID && $parent_ID )
			$post_ID = $parent_ID;
	
		$this->enforce((int) $post_ID, $force);
	}
	
	// Clear the cache for this entry and for all posts which are "related" to it.
	// New in 3.2: This is called when a post is deleted.
	function delete_post($post_ID) {
		// Clear the cache for this post.
		$this->clear($post_ID);
	
		// Find all "peers" which list this post as a related post.
		$peers = $this->related(null, $post_ID);
		// Clear the peers' caches.
		$this->clear($peers);
	}
	
	// New in 3.2.1: handle various post_status transitions
	function transition_post_status($new_status, $old_status, $post) {
		switch ($new_status) {
			case "draft":
				$this->delete_post($post->ID);
				break;
			case "publish":
				// find everything which is related to this post, and clear them, so that this
				// post might show up as related to them.
				$related = $this->related($post->ID, null);
				$this->clear($related);
		}
	}
	
	function set_score_override_flag($q) {
		if ( $this->is_yarpp_time() ) {
			$this->score_override = ($q->query_vars['orderby'] == 'score');
	
			if (!empty($q->query_vars['showposts'])) {
				$this->online_limit = $q->query_vars['showposts'];
			} else {
				$this->online_limit = false;
			}
		} else {
			$this->score_override = false;
			$this->online_limit = false;
		}
	}

	/*
	 * SQL!
	 */

	function sql( $reference_ID = false, $args ) {
		global $wpdb, $post;
	
		if ( is_object($post) && !$reference_ID ) {
			$reference_ID = $post->ID;
		}
		
		if ( !is_object($post) || $reference_ID != $post->ID ) {
			$reference_post = get_post( $reference_ID );
		} else {
			$reference_post = $post;
		}
	
		$options = array( 'threshold', 'show_pass_post', 'past_only', 'weight', 'exclude', 'recent_only', 'recent_number', 'recent_units', 'limit' );
		extract( $this->core->parse_args($args, $options) );
		// The maximum number of items we'll ever want to cache
		$limit = max($limit, $this->core->get_option('rss_limit'));
	
		// Fetch keywords
		$keywords = $this->get_keywords($reference_ID);
	
		// SELECT
		$newsql = "SELECT $reference_ID as reference_ID, ID, "; //post_title, post_date, post_content, post_excerpt,
	
		$newsql .= 'ROUND(0';
	
		if ($weight['body'] != 1)
			$newsql .= " + (MATCH (post_content) AGAINST ('".$wpdb->escape($keywords['body'])."')) * ". ($weight['body'] == 3 ? 3 : 1);
		if ($weight['title'] != 1)
			$newsql .= " + (MATCH (post_title) AGAINST ('".$wpdb->escape($keywords['title'])."')) * ". ($weight['title'] == 3 ? 3 : 1);
	
		// Build tax criteria query parts based on the weights
		$tax_criteria = array();
		foreach ( $weight['tax'] as $tax => $value ) {
			// 1 means don't consider:
			if ($value == 1)
				continue;
			$tax_criteria[$tax] = "count(distinct if( termtax.taxonomy = '$tax', termtax.term_taxonomy_id, null ))";
			$newsql .= " + " . $tax_criteria[$tax];
		}
	
		$newsql .= ',1) as score';
	
		$newsql .= "\n from $wpdb->posts \n";
	
		// Get disallowed categories and tags
		$disterms = wp_parse_id_list(join(',',$exclude));
		$usedisterms = count($disterms);
		if ( $usedisterms || count($tax_criteria) ) {
			$newsql .= "left join $wpdb->term_relationships as terms on ( terms.object_id = wp_posts.ID ) \n"
				. "left join $wpdb->term_taxonomy as termtax on ( terms.term_taxonomy_id = termtax.term_taxonomy_id ) \n"
				. "left join $wpdb->term_relationships as refterms on ( terms.term_taxonomy_id = refterms.term_taxonomy_id and refterms.object_id = $reference_ID ) \n";
		}
	
		// WHERE
	
		$newsql .= " where post_status in ( 'publish', 'static' ) and ID != '$reference_ID'";
	
		if ($past_only) // 3.1.8: revised $past_only option
			$newsql .= " and post_date <= '$reference_post->post_date' ";
		if ( !$show_pass_post )
			$newsql .= " and post_password ='' ";
		if ( $recent_only )
			$newsql .= " and post_date > date_sub(now(), interval $recent_number $recent_units) ";
	
		$newsql .= " and post_type = 'post'";
	
		// GROUP BY
		$newsql .= "\n group by ID \n";
	
		// HAVING
		// number_format fix suggested by vkovalcik! :)
		$safethreshold = number_format(max($threshold,0.1), 2, '.', '');
		$newsql .= " having score >= $safethreshold";
		if ( $usedisterms ) {
			$disterms = implode(',', $disterms);
			$newsql .= " and bit_and(termtax.term_id in ($disterms)) = 0";
		}
	
		foreach ( $weight['tax'] as $tax => $value ) {
			if ( $value == 3 )
				$newsql .= ' and '.$tax_criteria[$tax].' >= 1';
			if ( $value == 4 )
				$newsql .= ' and '.$tax_criteria[$tax].' >= 2';
		}
	
		$newsql .= " order by score desc limit $limit";
	
		// in caching, we cross-relate regardless of whether we're going to actually
		// use it or not.
		$newsql = "($newsql) union (".str_replace("post_type = 'post'","post_type = 'page'",$newsql).")";
	
		if ($this->core->debug) echo "<!--$newsql-->";
		
		$this->last_sql = $newsql;
		
		return $newsql;
	}

	/*
	 * KEYWORDS
	 */
	
	public function title_keywords($ID,$max = 20) {
		return $this->extract_keywords(get_the_title($ID),$max);
	}
	
	public function body_keywords( $ID, $max = 20 ) {
		$post = get_post( $ID );
		if ( empty($post) )
			return '';
		$content = $this->apply_filters_if_white( 'the_content', $post->post_content );
		return $this->extract_keywords( $content, $max );
	}
	
	private function extract_keywords($html, $max = 20) {
	
		$lang = 'en_US';
		if ( defined('WPLANG') ) {
			$lang = substr(WPLANG, 0, 2);
			switch ( $lang ) {
				case 'de':
					$lang = 'de_DE';
				case 'it':
					$lang = 'it_IT';
				case 'pl':
					$lang = 'pl_PL';
				case 'bg':
					$lang = 'bg_BG';
				case 'fr':
					$lang = 'fr_FR';
				case 'cs':
					$lang = 'cs_CZ';
				case 'nl':
					$lang = 'nl_NL';
			}
		}
	
		$words_file = YARPP_DIR . '/lang/words-' . $lang . '.php';
		if ( file_exists($words_file) )
			include( $words_file );
		if ( !isset($overusedwords) )
			$overusedwords = array();
	
		// strip tags and html entities
		$text = preg_replace('/&(#x[0-9a-f]+|#[0-9]+|[a-zA-Z]+);/', '', strip_tags($html) );
	
		// 3.2.2: ignore soft hyphens
		// Requires PHP 5: http://bugs.php.net/bug.php?id=25670
		$softhyphen = html_entity_decode('&#173;',ENT_NOQUOTES,'UTF-8');
		$text = str_replace($softhyphen, '', $text);
	
		$charset = get_option('blog_charset');
		if ( function_exists('mb_split') && !empty($charset) ) {
			mb_regex_encoding($charset);
			$wordlist = mb_split('\s*\W+\s*', mb_strtolower($text, $charset));
		} else
			$wordlist = preg_split('%\s*\W+\s*%', strtolower($text));
	
		// Build an array of the unique words and number of times they occur.
		$tokens = array_count_values($wordlist);
	
		// Remove the stop words from the list.
		$overusedwords = apply_filters( 'yarpp_keywords_overused_words', $overusedwords );
		if ( is_array($overusedwords) ) {
			foreach ($overusedwords as $word) {
				 unset($tokens[$word]);
			}
		}
		// Remove words which are only a letter
		$mb_strlen_exists = function_exists('mb_strlen');
		foreach (array_keys($tokens) as $word) {
			if ($mb_strlen_exists)
				if (mb_strlen($word) < 2) unset($tokens[$word]);
			else
				if (strlen($word) < 2) unset($tokens[$word]);
		}
	
		arsort($tokens, SORT_NUMERIC);
	
		$types = array_keys($tokens);
	
		if (count($types) > $max)
			$types = array_slice($types, 0, $max);
		return implode(' ', $types);
	}
	
	/* new in 2.0! apply_filters_if_white (previously apply_filters_without) now has a blacklist.
	 * It can be modified via the yarpp_blacklist and yarpp_blackmethods filters.
	 */
	/* blacklisted so far:
		- diggZ-Et
		- reddZ-Et
		- dzoneZ-Et
		- WP-Syntax
		- Viper's Video Quicktags
		- WP-CodeBox
		- WP shortcodes
		- WP Greet Box
		//- Tweet This - could not reproduce problem.
	*/
	function white( $filter ) {
		static $blacklist, $blackmethods;
	
		if ( is_null($blacklist) || is_null($blackmethods) ) {
			$yarpp_blacklist = array('diggZEt_AddBut', 'reddZEt_AddBut', 'dzoneZEt_AddBut', 'wp_syntax_before_filter', 'wp_syntax_after_filter', 'wp_codebox_before_filter', 'wp_codebox_after_filter', 'do_shortcode');//,'insert_tweet_this'
			$yarpp_blackmethods = array('addinlinejs', 'replacebbcode', 'filter_content');
		
			$blacklist = (array) apply_filters( 'yarpp_blacklist', $yarpp_blacklist );
			$blackmethods = (array) apply_filters( 'yarpp_blackmethods', $yarpp_blackmethods );
		}
		
		if ( is_array($filter) && is_a( $filter[0], 'YARPP' ) )
			return false;
		if ( is_array($filter) && in_array( $filter[1], $blackmethods ) )
			return false;
		return !in_array( $filter, $blacklist );
	}
	
	/* FYI, apply_filters_if_white was used here to avoid a loop in apply_filters('the_content') > YARPP::the_content() > YARPP::related() > YARPP_Cache::body_keywords() > apply_filters('the_content').*/
	function apply_filters_if_white($tag, $value) {
		global $wp_filter, $merged_filters, $wp_current_filter;
	
		$args = array();
	
		// Do 'all' actions first
		if ( isset($wp_filter['all']) ) {
			$wp_current_filter[] = $tag;
			$args = func_get_args();
			_wp_call_all_hook($args);
		}
	
		if ( !isset($wp_filter[$tag]) ) {
			if ( isset($wp_filter['all']) )
				array_pop($wp_current_filter);
			return $value;
		}
	
		if ( !isset($wp_filter['all']) )
			$wp_current_filter[] = $tag;
	
		// Sort
		if ( !isset( $merged_filters[ $tag ] ) ) {
			ksort($wp_filter[$tag]);
			$merged_filters[ $tag ] = true;
		}
	
		reset( $wp_filter[ $tag ] );
	
		if ( empty($args) )
			$args = func_get_args();
	
		do {
			foreach( (array) current($wp_filter[$tag]) as $the_ )
				if ( !is_null($the_['function'])
				and $this->white($the_['function'])){ // HACK
					$args[1] = $value;
					$value = call_user_func_array($the_['function'], array_slice($args, 1, (int) $the_['accepted_args']));
				}
	
		} while ( next($wp_filter[$tag]) !== false );
	
		array_pop( $wp_current_filter );
	
		return $value;
	}
}

class YARPP_Cache_Bypass extends YARPP_Cache {

	public $name = "bypass";

	// variables used for lookup
	private $related_postdata = array();
	private $related_IDs = array();

	private $yarpp_time = false;
	public $demo_time = false;
	private $demo_limit = 0;

	/**
	 * SETUP/STATUS
	 */
	function __construct( &$core ) {
		parent::__construct( $core );
	}

	public function is_enabled() {
		return true; // always enabled.
	}

	public function setup() {
	}
	
	public function upgrade( $last_version ) {
	}

	public function cache_status() {
		return 0; // always uncached
	}

	public function uncached($limit = 20, $offset = 0) {
		return array(); // nothing to cache
	}

	/**
	 * MAGIC FILTERS
	 */
	public function where_filter($arg) {
		global $wpdb;
		$threshold = yarpp_get_option('threshold');
		// modify the where clause to use the related ID list.
		if (!count($this->related_IDs))
			$this->related_IDs = array(0);
		$arg = preg_replace("!{$wpdb->posts}.ID = \d+!","{$wpdb->posts}.ID in (".join(',',$this->related_IDs).")",$arg);

		// if we have "recent only" set, add an additional condition
		if (yarpp_get_option("recent_only"))
			$arg .= " and post_date > date_sub(now(), interval ".yarpp_get_option("recent_number")." ".yarpp_get_option("recent_units").") ";
		return $arg;
	}

	public function orderby_filter($arg) {
		global $wpdb;
		// only order by score if the score function is added in fields_filter, which only happens
		// if there are related posts in the postdata
		if ($this->score_override &&
		    is_array($this->related_postdata) && count($this->related_postdata))
			return str_replace("$wpdb->posts.post_date","score",$arg);
		return $arg;
	}

	public function fields_filter($arg) {
		global $wpdb;
		if (is_array($this->related_postdata) && count($this->related_postdata)) {
			$scores = array();
			foreach ($this->related_postdata as $related_entry) {
				$scores[] = " WHEN {$related_entry['ID']} THEN {$related_entry['score']}";
			}
			$arg .= ", CASE {$wpdb->posts}.ID" . join('',$scores) ." END as score";
		}
		return $arg;
	}

	public function demo_request_filter($arg) {
		global $wpdb;
		$wpdb->query("set @count = 0;");

		$loremipsum = 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Cras tincidunt justo a urna. Ut turpis. Phasellus convallis, odio sit amet cursus convallis, eros orci scelerisque velit, ut sodales neque nisl at ante. Suspendisse metus. Curabitur auctor pede quis mi. Pellentesque lorem justo, condimentum ac, dapibus sit amet, ornare et, erat. Quisque velit. Etiam sodales dui feugiat neque suscipit bibendum. Integer mattis. Nullam et ante non sem commodo malesuada. Pellentesque ultrices fermentum lectus. Maecenas hendrerit neque ac est. Fusce tortor mi, tristique sed, cursus at, pellentesque non, dui. Suspendisse potenti.';

		return "SELECT SQL_CALC_FOUND_ROWS ID + {$this->demo_limit} as ID, post_author, post_date, post_date_gmt, '{$loremipsum}' as post_content,
		concat('".__('Example post ','yarpp')."',@count:=@count+1) as post_title, 0 as post_category, '' as post_excerpt, 'publish' as post_status, 'open' as comment_status, 'open' as ping_status, '' as post_password, concat('example-post-',@count) as post_name, '' as to_ping, '' as pinged, post_modified, post_modified_gmt, '' as post_content_filtered, 0 as post_parent, concat('PERMALINK',@count) as guid, 0 as menu_order, 'post' as post_type, '' as post_mime_type, 0 as comment_count, 'SCORE' as score
		FROM $wpdb->posts
		ORDER BY ID DESC LIMIT 0, {$this->demo_limit}";
	}

	public function limit_filter($arg) {
		global $wpdb;
		if ($this->online_limit)
			return " limit {$this->online_limit} ";
		return $arg;
	}

	/**
	 * RELATEDNESS CACHE CONTROL
	 */
	public function is_yarpp_time() {
		return $this->yarpp_time;
	}
	 
	public function begin_yarpp_time( $reference_ID, $args ) {
		global $wpdb;

		$this->yarpp_time = true;

		$this->related_postdata = $wpdb->get_results($this->sql($reference_ID, $args), ARRAY_A);
		$this->related_IDs = array_map(create_function('$x','return $x["ID"];'), $this->related_postdata);

		add_filter('posts_join',array(&$this,'join_filter'));
		add_filter('posts_where',array(&$this,'where_filter'));
		add_filter('posts_orderby',array(&$this,'orderby_filter'));
		add_filter('posts_fields',array(&$this,'fields_filter'));
		add_filter('post_limits',array(&$this,'limit_filter'));
		add_action('pre_get_posts',array(&$this,'add_signature'));
		// sets the score override flag.
		add_action('parse_query',array(&$this,'set_score_override_flag'));
	}
	
	public function begin_demo_time( $limit ) {
		$this->demo_time = true;
		$this->demo_limit = $limit;
		add_action('pre_get_posts',array(&$this,'add_signature'));
		add_filter('posts_request',array(&$this,'demo_request_filter'));
	}

	public function end_yarpp_time() {
		$this->yarpp_time = false;
		remove_filter('posts_join',array(&$this,'join_filter'));
		remove_filter('posts_where',array(&$this,'where_filter'));
		remove_filter('posts_orderby',array(&$this,'orderby_filter'));
		remove_filter('posts_fields',array(&$this,'fields_filter'));
		remove_filter('post_limits',array(&$this,'limit_filter'));
		remove_action('pre_get_posts',array(&$this,'add_signature'));
		remove_action('parse_query',array(&$this,'set_score_override_flag'));
	}
	
	public function end_demo_time() {
		$this->demo_time = false;
		remove_action('pre_get_posts',array(&$this,'add_signature'));
		remove_filter('posts_request',array(&$this,'demo_request_filter'));
	}

	// @return YARPP_NO_RELATED | YARPP_RELATED | YARPP_NOT_CACHED
	public function is_cached($reference_ID) {
		return YARPP_NOT_CACHED;
	}

	public function clear($reference_ID) {
		return;
	}

	// @return YARPP_NO_RELATED | YARPP_RELATED | YARPP_NOT_CACHED
	public function update($reference_ID) {
		global $wpdb;

		// $reference_ID must be numeric
		if ( !$reference_ID = absint($reference_ID) )
			return YARPP_NOT_CACHED;

		return YARPP_RELATED;
	}

	public function flush() {
	}

	public function related($reference_ID = null, $related_ID = null) {
		global $wpdb;

		if ( !is_int( $reference_ID ) && !is_int( $related_ID ) ) {
			_doing_it_wrong( __METHOD__, 'reference ID and/or related ID must be set', '3.4' );
			return;
		}

		// reverse lookup
		if ( is_int($related_ID) && is_null($reference_ID) ) {
			_doing_it_wrong( __METHOD__, 'YARPP_Cache_Bypass::related cannot do a reverse lookup', '3.4' );
			return;
		}

		$results = $wpdb->get_results($this->sql($reference_ID), ARRAY_A);
		if ( !$results || !count($results) )
			return false;
		
		if ( is_null($related_ID) ) {
			return array_map(create_function('$x','return $x["ID"];'), $results);			
		} else {
			foreach($results as $result) {
				if ($result['ID'] == $related_ID)
					return true;
			}
		}

		return false;
	}

	/**
	 * KEYWORDS CACHE CONTROL
	 */
	 
	// @return (array) with body and title keywords
	private function cache_keywords($ID) {
		return array(
			'body' => $this->body_keywords($ID),
			'title' => $this->title_keywords($ID)
		);
	}

	// @param $ID (int)
	// @param $type (string) body | title | all
	// @return (string|array) depending on whether "all" were requested or not
	public function get_keywords( $ID, $type = 'all' ) {
		if ( !$ID = absint($ID) )
			return false;
	
		$keywords = $this->cache_keywords($ID);

		if ( empty($keywords) )
			return false;
		
		if ( 'all' == $type )
			return $keywords;
		return $keywords[$type];
	}
}