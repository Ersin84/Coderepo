<?php
/**
 * Module Name: After the Deadline
 * Module Description: After the Deadline helps you write better by adding spell, style, and grammar checking to WordPress.
 */
 
add_action( 'jetpack_modules_loaded', 'AtD_load' );

function AtD_load() {
	Jetpack::enable_module_configurable( __FILE__ ); 
	Jetpack::module_configuration_load( __FILE__, 'AtD_configuration_load' );	
}

function AtD_configuration_load() {
	wp_redirect( admin_url( 'profile.php#atd' ) );
	exit;	
}

/*  
 *  Load necessary include files
 */
include( 'after-the-deadline/config-options.php' );
include( 'after-the-deadline/config-unignore.php' );
include( 'after-the-deadline/proxy.php' );

/**
 * Update a user's After the Deadline Setting
 */
function AtD_update_setting( $user_id, $name, $value ) {
	update_user_meta( $user_id, $name, $value );
}

/**
 * Retrieve a user's After the Deadline Setting
 */
function AtD_get_setting( $user_id, $name, $single = true ) {
	return get_user_meta( $user_id, $name, $single );
}

/*
 * Display the AtD configuration options (or not supported if the language id is not English [1])
 */
function AtD_config() {
	AtD_display_options_form();
	AtD_display_unignore_form();
}

/*
 *  Code to update the toolbar with the AtD Button and Install the AtD TinyMCE Plugin
 */
function AtD_addbuttons() {

	/* Don't bother doing this stuff if the current user lacks permissions */
	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) )
		return;
   
	/* Add only in Rich Editor mode w/ Blog language ID set to English */
	if ( get_user_option( 'rich_editing' ) == 'true' ) {
		add_filter( 'mce_external_plugins', 'add_AtD_tinymce_plugin' );
		add_filter( 'mce_buttons', 'register_AtD_button' );
	}

	add_action( 'personal_options_update', 'AtD_process_options_update' );
	add_action( 'personal_options_update', 'AtD_process_unignore_update' );
	add_action( 'profile_personal_options', 'AtD_config' );
}

/*
 * Hook into the TinyMCE buttons and replace the current spellchecker
 */
function register_AtD_button( $buttons ) {

	/* kill the spellchecker.. don't need no steenkin PHP spell checker */
	foreach ( $buttons as $key => $button ) {
		if ( $button == 'spellchecker' ) {
			$buttons[$key] = 'AtD';
			return $buttons;
		}
	}

	/* hrm... ok add us last plz */
	array_push( $buttons, 'separator', 'AtD' );
	return $buttons;
}
 
/*
 * Load the TinyMCE plugin : editor_plugin.js (wp2.5) 
 */
function add_AtD_tinymce_plugin( $plugin_array ) {
	$plugin_array['AtD'] = plugins_url( '/after-the-deadline/tinymce/editor_plugin.js', __FILE__ );
	return $plugin_array;
}

/* 
 * Update the TinyMCE init block with AtD specific settings
 */
function AtD_change_mce_settings( $init_array ) {
        /* grab our user and validate their existence */
        $user = wp_get_current_user();
        if ( ! $user || $user->ID == 0 )
                return;

	$init_array['atd_rpc_url']        = admin_url() . 'admin-ajax.php?action=proxy_atd&url=';
	$init_array['atd_ignore_rpc_url'] = admin_url() . 'admin-ajax.php?action=atd_ignore&phrase=';
	$init_array['atd_rpc_id']         = 'WPORG-' . md5(get_bloginfo('wpurl'));
	$init_array['atd_theme']          = 'wordpress';
	$init_array['atd_ignore_enable']  = 'true';
	$init_array['atd_strip_on_get']   = 'true';
	$init_array['atd_ignore_strings'] = str_replace( '"', '', AtD_get_setting( $user->ID, 'AtD_ignored_phrases' ) );
	$init_array['atd_show_types']     = AtD_get_setting( $user->ID, 'AtD_options' );
	$init_array['gecko_spellcheck']   = 'false';

	return $init_array;
}

/* 
 * Sanitizes AtD AJAX data to acceptable chars, caller needs to make sure ' is escaped
 */
function AtD_sanitize( $untrusted ) {
        return preg_replace( '/[^a-zA-Z0-9\-\',_ ]/i', "", $untrusted );
}

/* 
 * AtD HTML Editor Stuff 
 */

function AtD_settings() {
        $user = wp_get_current_user();

        header( 'Content-Type: text/javascript' );

        /* set the RPC URL for AtD */
	echo "AtD.rpc = '" . admin_url() . "admin-ajax.php?action=proxy_atd&url=';\n";

        /* set the API key for AtD */
        echo "AtD.api_key = 'WPORG-" . md5(get_bloginfo('wpurl')) . "';\n";

        /* set the ignored phrases for AtD */
        echo "AtD.setIgnoreStrings('" . str_replace( "'", "\\'", AtD_get_setting( $user->ID, 'AtD_ignored_phrases' ) ) . "');\n";

        /* honor the types we want to show */
        echo "AtD.showTypes('" . str_replace( "'", "\\'", AtD_get_setting( $user->ID, 'AtD_options' ) ) ."');\n";

        /* this is not an AtD/jQuery setting but I'm putting it in AtD to make it easy for the non-viz plugin to find it */
	echo "AtD.rpc_ignore = '" . admin_url() . "admin-ajax.php?action=atd_ignore&phrase=';\n";

        die;
}

function AtD_load_javascripts() {
        global $pagenow;
        
	if ( AtD_should_load_on_page() ) {
		wp_enqueue_script( 'AtD_core', plugins_url( '/after-the-deadline/atd.core.js', __FILE__ ), array() );
	        wp_enqueue_script( 'AtD_quicktags', plugins_url( '/after-the-deadline/atd-nonvis-editor-plugin.js', __FILE__ ), array('quicktags') );
        	wp_enqueue_script( 'AtD_jquery', plugins_url( '/after-the-deadline/jquery.atd.js', __FILE__ ), array('jquery') );
        	wp_enqueue_script( 'AtD_settings', admin_url() . 'admin-ajax.php?action=atd_settings', array('AtD_jquery') );
		wp_enqueue_script( 'AtD_autoproofread', plugins_url( '/after-the-deadline/atd-autoproofread.js', __FILE__ ), array('AtD_jquery') );
	}		
}

/* Spits out user options for auto-proofreading on publish/update */
function AtD_load_submit_check_javascripts() {
	global $pagenow;
	
	$user = wp_get_current_user();
	if ( ! $user || $user->ID == 0 )
		return;
	
	if ( AtD_should_load_on_page() ) {
		$atd_check_when = AtD_get_setting( $user->ID, 'AtD_check_when', true );
		
		if ( !empty( $atd_check_when ) ) {
			$check_when = '';
			/* Set up the options in json */
			foreach( explode( ',', $atd_check_when ) as $option ) {
				$check_when .= ( $check_when ? ', ' : '' ) . $option . ': true';
			}
			echo '<script type="text/javascript">' . "\n";
			echo 'AtD_check_when = { ' . $check_when . ' };' . "\n";
			echo '</script>' . "\n";
		}
	}
}

function AtD_is_allowed() {
        $user = wp_get_current_user();
        if ( ! $user || $user->ID == 0 )
                return;

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) )
                return;

        return 1;
}

function AtD_load_css() {
	if ( AtD_should_load_on_page() )
	        wp_enqueue_style( 'AtD_style', plugins_url( '/after-the-deadline/atd.css', __FILE__ ), null, '1.0', 'screen' );
}

/* Helper used to check if javascript should be added to page. Helps avoid bloat in admin */
function AtD_should_load_on_page() {
	global $pagenow;
	
	$pages = array( 'post.php', 'post-new.php', 'page.php', 'page-new.php', 'admin.php', 'profile.php' );
	
	if( in_array( $pagenow, $pages ) ) 
		return true;
	
	return false;
}

// add button to DFW
add_filter( 'wp_fullscreen_buttons', 'AtD_fullscreen' );
function AtD_fullscreen($buttons) {
	$buttons['spellchecker'] = array( 'title' => __('Proofread Writing'), 'onclick' => "tinyMCE.execCommand('mceWritingImprovementTool');", 'both' => false );

	return $buttons;
}

/* add some vars into the AtD plugin */
add_filter( 'tiny_mce_before_init', 'AtD_change_mce_settings' );

/* load some stuff for non-visual editor */
add_action( 'admin_print_scripts', 'AtD_load_javascripts' );
add_action( 'admin_print_scripts', 'AtD_load_submit_check_javascripts' );
add_action( 'admin_print_styles', 'AtD_load_css' );

/* init process for button control */
add_action( 'init', 'AtD_addbuttons' );

/* setup hooks for our PHP functions we want to make available via an AJAX call */
add_action( 'wp_ajax_proxy_atd', 'AtD_redirect_call' );
add_action( 'wp_ajax_atd_ignore', 'AtD_ignore_call' );    
add_action( 'wp_ajax_atd_settings', 'AtD_settings' );

/* load and install the localization stuff */
include( 'after-the-deadline/atd-l10n.php' );
