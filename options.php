<?php

global $wpdb, $wp_version, $yarpp;

// Reenforce YARPP setup:
if ( !get_option('yarpp_version') )
	$yarpp->activate();
else
	$yarpp->upgrade_check();

// if action=flush, reset the cache
if (isset($_GET['action']) && $_GET['action'] == 'flush') {
	$yarpp->cache->flush();
}

// check to see that templates are in the right place
$yarpp->templates = glob(STYLESHEETPATH . '/yarpp-template-*.php');
if ( !(is_array($yarpp->templates) && count($yarpp->templates)) ) {
	yarpp_set_option(array('use_template' => false, 'rss_use_template' => false));
}

// 3.3: move version checking here, in PHP:
if ( current_user_can('update_plugins' ) ) {
	$yarpp_version_info = $yarpp->version_info();
	
	// these strings are not localizable, as long as the plugin data on wordpress.org
	// cannot be.
	$slug = 'yet-another-related-posts-plugin';
	$plugin_name = 'Yet Another Related Posts Plugin';
	$file = basename(YARPP_DIR) . '/yarpp.php';
	if ( $yarpp_version_info['result'] == 'new' ) {
		// make sure the update system is aware of this version
		$current = get_site_transient( 'update_plugins' );
		if ( !isset( $current->response[ $file ] ) ) {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
		}
	
		echo '<div class="updated"><p>';
		$details_url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . $slug . '&TB_iframe=true&width=600&height=800');
		printf( __('There is a new version of %1$s available. <a href="%2$s" class="thickbox" title="%3$s">View version %4$s details</a> or <a href="%5$s">update automatically</a>.'), $plugin_name, esc_url($details_url), esc_attr($plugin_name), $yarpp_version_info['current']['version'], wp_nonce_url( self_admin_url('update.php?action=upgrade-plugin&plugin=') . $file, 'upgrade-plugin_' . $file) );
		echo '</p></div>';
	} else if ( $yarpp_version_info['result'] == 'newbeta' ) {
		echo '<div class="updated"><p>';
		printf(__("There is a new beta (%s) of Yet Another Related Posts Plugin. You can <a href=\"%s\">download it here</a> at your own risk.","yarpp"), $yarpp_version_info['beta']['version'], $yarpp_version_info['beta']['url']);
		echo '</p></div>';
	}
}

if (isset($_POST['myisam_override'])) {
	yarpp_set_option('myisam_override',1);
	echo "<div class='updated'>"
	.__("The MyISAM check has been overridden. You may now use the \"consider titles\" and \"consider bodies\" relatedness criteria.",'yarpp')
	."</div>";
}

if ( !yarpp_get_option('myisam_override') ) {
	$yarpp_check_return = $yarpp->myisam_check();
	if ($yarpp_check_return !== true) { // if it's not *exactly* true
		echo "<div class='updated'>"
		.sprintf(__("YARPP's \"consider titles\" and \"consider bodies\" relatedness criteria require your <code>%s</code> table to use the <a href='http://dev.mysql.com/doc/refman/5.0/en/storage-engines.html'>MyISAM storage engine</a>, but the table seems to be using the <code>%s</code> engine. These two options have been disabled.",'yarpp'),$wpdb->posts,$yarpp_check_return)
		."<br />"
		.sprintf(__("To restore these features, please update your <code>%s</code> table by executing the following SQL directive: <code>ALTER TABLE `%s` ENGINE = MyISAM;</code> . No data will be erased by altering the table's engine, although there are performance implications.",'yarpp'),$wpdb->posts,$wpdb->posts)
		."<br />"
		.sprintf(__("If, despite this check, you are sure that <code>%s</code> is using the MyISAM engine, press this magic button:",'yarpp'),$wpdb->posts)
		."<br />"
		."<form method='post'><input type='submit' class='button' name='myisam_override' value='"
		.__("Trust me. Let me use MyISAM features.",'yarpp')
		."'></input></form>"
		."</div>";

		yarpp_set_option(array('title' => 1, 'body' => 1));
		$yarpp->myisam = false;
	}
}

if ( $yarpp->myisam && !$yarpp->enabled() ) {
	echo '<div class="updated"><p>';
	if ( $yarpp->activate() ) {
		_e('The YARPP database had an error but has been fixed.','yarpp');
	} else {
		_e('The YARPP database has an error which could not be fixed.','yarpp');
		printf(__('Please try <a href="%s" target="_blank">manual SQL setup</a>.','yarpp'), 'http://mitcho.com/code/yarpp/sql.php?prefix='.urlencode($wpdb->prefix));
	}
	echo '</div></p>';
}

if (isset($_POST['update_yarpp'])) {

	$new_options = array();
	foreach ($yarpp->default_options as $option => $default) {
		if ( is_bool($default) )
			$new_options[$option] = isset($_POST[$option]);
		if ( (is_string($default) || is_int($default)) &&
			isset($_POST[$option]) && is_string($_POST[$option]) )
			$new_options[$option] = stripslashes($_POST[$option]);
	}

	if ( isset($_POST['weight']) ) {
		$new_options['weight'] = $_POST['weight'];
	}

	// excludes are different
	$new_options['exclude'] = array();
	if ( isset($_POST['exclude']) ) {
		$exclude = array_merge( array('category' => array(), 'post_tag' => array()), $_POST['exclude'] );
		$new_options['exclude']['category'] = implode(',',array_keys($exclude['category']));
		$new_options['exclude']['post_tag'] = implode(',',array_keys($exclude['post_tag']));
	}
	
	$new_options = apply_filters( 'yarpp_settings_save', $new_options );
	yarpp_set_option($new_options);

	echo '<div class="updated fade"><p>'.__('Options saved!','yarpp').'</p></div>';
}

?>
<div class="wrap">
		<h2>
			<?php _e('Yet Another Related Posts Plugin Options','yarpp');?> <small><?php
				echo apply_filters( 'yarpp_version_html', esc_html( get_option('yarpp_version') ) );
			?></small>
		</h2>

	<form method="post">

  <div id="yarpp_author_text">
	<small><?php printf(__('by <a href="%s" target="_blank">mitcho (Michael 芳貴 Erlewine)</a>','yarpp'), 'http://mitcho.com/');?></small>
  </div>

<!--	<div style='border:1px solid #ddd;padding:8px;'>-->

<?php
wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
?>
<div id="poststuff" class="metabox-holder has-right-sidebar">

<div class="inner-sidebar" id="side-info-column">
<?php
do_meta_boxes( 'settings_page_yarpp', 'side', array() );
?>
</div>

<div id="post-body-content">
<?php
do_meta_boxes( 'settings_page_yarpp', 'normal', array() );
?>
</div>

<script language="javascript">
var spinner = '<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>',
	loading = '<img class="loading" src="'+spinner+'" alt="loading..."/>';
</script>

<div>
	<p class="submit">
		<input type="submit" class='button-primary' name="update_yarpp" value="<?php _e("Update options",'yarpp')?>" />
	</p>
</div>

</form>
