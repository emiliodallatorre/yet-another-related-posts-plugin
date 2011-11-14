<?php

class YARPP_Admin {
	public $core;
	public $hook;

	function __construct( &$core ) {
		$this->core = &$core;
		
		add_action( 'admin_menu', array( $this, 'register' ) );
		// new in 3.3: set default meta boxes to show:
		add_filter( 'default_hidden_meta_boxes', array( $this, 'default_hidden_meta_boxes' ), 10, 2 );
	}
	
	function register() {
		// setup admin
		$this->hook = add_options_page(__('Related Posts (YARPP)','yarpp'),__('Related Posts (YARPP)','yarpp'), 'manage_options', 'yarpp', array( $this, 'options_page' ) );
		// new in 3.3: load options page sections as metaboxes
		require_once('options-meta-boxes.php');

		// new in 3.0.12: add settings link to the plugins page
		add_filter('plugin_action_links', array( $this, 'settings_link' ), 10, 2);

		// new in 3.0: add meta box		
		add_meta_box( 'yarpp_relatedposts', __( 'Related Posts' , 'yarpp') . ' <span class="postbox-title-action"><a href="' . esc_url( admin_url('options-general.php?page=yarpp') ) . '" class="edit-box open-box">' . __( 'Configure' ) . '</a></span>', array( $this, 'metabox' ), 'post', 'normal' );
		
		// new in 3.3: properly enqueue scripts for admin:
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}
	
	// since 3.3
	function enqueue() {
		global $current_screen;
		if (is_object($current_screen) && $current_screen->id == 'settings_page_yarpp') {
			wp_enqueue_script( 'postbox' );
			wp_enqueue_style( 'yarpp_options', plugins_url( 'options.css', __FILE__ ), array(), YARPP_VERSION );
			wp_enqueue_script( 'yarpp_options', plugins_url( 'options.js', __FILE__ ), array('jquery'), YARPP_VERSION );
			// wp_enqueue_script( 'thickbox' );
			// wp_enqueue_style( 'thickbox' );
		}
	}
	
	function settings_link($links, $file) {
		$this_plugin = dirname(plugin_basename(__FILE__)) . '/yarpp.php';
		if($file == $this_plugin) {
			$links[] = '<a href="options-general.php?page=yarpp">' . __('Settings', 'yarpp') . '</a>';
		}
		return $links;
	}
	
	function options_page() {
		// for proper metabox support:
		require(YARPP_DIR.'/options.php');
	}
		
	function metabox() {
		echo '<style>#yarpp_relatedposts h3 .postbox-title-action { right: 30px; top: 5px; position: absolute; padding: 0 }</style><div id="yarpp-related-posts">';
		if ( get_the_ID() )
			yarpp_related(array('post'),array('limit'=>1000),true,false,'metabox');
		else
			echo "<p>".__("Related entries may be displayed once you save your entry",'yarpp').".</p>";
		echo '</div>';
	}
	
	// since 3.3: default metaboxes to show:
	function default_hidden_meta_boxes($hidden, $screen) {
		if ( 'settings_page_yarpp' == $screen->id )
			$hidden = array( 'yarpp_pool', 'yarpp_relatedness' );
		return $hidden;
	}
}