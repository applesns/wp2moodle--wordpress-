<?php
/*
Plugin Name: wp2moodle
Plugin URI: https://github.com/frumbert/wp2moodle--wordpress-
Description: A plugin that sends the authenticated users details to a moodle site for authentication, enrols them in the specified cohort
Requires: Moodle 2.2 site with the wp2moodle (Moodle) auth plugin enabled
Version: 0.1
Author: Tim St.Clair
Author URI: http://timstclair.me
License: GPL2
*/

/*  Copyright 2012

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
?><?php

// some definition we will use
define( 'WP2M_PUGIN_NAME', 'Wordpress 2 Moodle (SSO)');
define( 'WP2M_PLUGIN_DIRECTORY', 'wp2moodle');
define( 'WP2M_CURRENT_VERSION', '0.2' );
define( 'WP2M_CURRENT_BUILD', '1' );
define( 'EMU2_I18N_DOMAIN', 'wp2m' );
define( 'WP2M_MOODLE_PLUGIN_URL', '/auth/wp2moodle/login.php?data=');

function wp2m_set_lang_file() {
	$currentLocale = get_locale();
	if(!empty($currentLocale)) {
		$moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
		if (@file_exists($moFile) && is_readable($moFile)) {
			load_textdomain(EMU2_I18N_DOMAIN, $moFile);
		}

	}
}
wp2m_set_lang_file();

//shortcodes - register the shortcode this plugin uses and the handler to insert it
add_shortcode('wp2moodle', 'wp2moodle_handler');

// actions - register the plugin itself, it's settings pages and its wordpress hooks
add_action( 'admin_menu', 'wp2m_create_menu' );
add_action( 'admin_init', 'wp2m_register_settings' );
register_activation_hook(__FILE__, 'wp2m_activate');
register_deactivation_hook(__FILE__, 'wp2m_deactivate');
register_uninstall_hook(__FILE__, 'wp2m_uninstall');

// on page load, init the handlers for the editor to insert the shortcodes (javascript)
add_action('init', 'wp2m_add_button');

/**
 * activating the default values
*/
function wp2m_activate() {
	add_option('wp2m_moodle_url', 'http://localhost/moodle');
	add_option('wp2m_shared_secret', 'enter a random sequence of letters, numbers and symbols here');
}

/**
 * deactivating requires deleting any options set
 */
function wp2m_deactivate() {
	delete_option('wp2m_moodle_url');
	delete_option('wp2m_shared_secret');
}

/**
 * uninstall routine
 */
function wp2m_uninstall() {
	delete_option('wp2m_moodle_url');
	delete_option('wp2m_shared_secret');
}

/**
 * Creates a sub menu in the settings menu for the Link2Moodle settings
 */
function wp2m_create_menu() {
	add_menu_page( 
	__('wp2Moodle', EMU2_I18N_DOMAIN),
	__('wp2Moodle', EMU2_I18N_DOMAIN),
	'administrator',
	WP2M_PLUGIN_DIRECTORY.'/wp2m_settings_page.php',
	'',
	plugins_url('icon.png', __FILE__));
}

/**
 * Registers the settings that this plugin will read and write
 */
function wp2m_register_settings() {
	//register settings against a grouping (how wp-admin/options.php works)
	register_setting( 'wp2m-settings-group', 'wp2m_moodle_url' );
	register_setting( 'wp2m-settings-group', 'wp2m_shared_secret' );
}

/**
 * Given a string and key, return the encrypted version (hard coded to use rijndael because it's tough)
 */
function encrypt_string($value, $key) { 
	if (!$value) {return "";}
	$text = $value;
	$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
	$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
	$crypttext = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key.$key), $text, MCRYPT_MODE_ECB, $iv);

	// encode data so that $_GET won't urldecode it and mess up some characters
	$data = base64_encode($crypttext);
    $data = str_replace(array('+','/','='),array('-','_',''),$data);
    return trim($data);
}


/**
 * handler for the plugins shortcode (e.g. [link2moodle cohort='abc123']my link text[/link2moodle])
 * note: applies do_shortcode() to content to allow other plugins to be handled on links
 * when unauthenticated just returns the inner content (e.g. my link text) without a link
 */
function wp2moodle_handler( $atts, $content = null ) {
	
	// needs authentication; ensure userinfo globals are populated
	global $current_user;
    get_currentuserinfo();

	// clone attribs over any default values, builds variables out of them so we can use them below
	// $class => css class to put on link we build
	// $cohort => text id of the moodle cohort in which to enrol this user
	extract(shortcode_atts(array(
		"cohort" => '',
		"class" => 'wp2moodle',
		"target" => '_self'
	), $atts));
	
	if ($content == null || !is_user_logged_in() ) {
		// return just the content when the user is unauthenticated or the tag wasn't set properly
		$url = do_shortcode($content);
	} else {
		// url = moodle_url + "?data=" + <encrypted-value>
		// encryption = 3des using shared_secret
		$details = http_build_query(array(
			"a", rand(1, 1500),									// set first to randomise the encryption when this string is encoded
			"stamp" => time(),	// unix timestamp so we can check that the link isn't expired
			"firstname" => $current_user->user_firstname,		// first name
			"lastname" => $current_user->user_lastname,			// last name
			"email" => $current_user->user_email,				// email
			"username" => $current_user->user_login,			// username
			"passwordhash" => $current_user->user_pass,			// hash of password (we don't know/care about the raw password)
			"idnumber" => $current_user->ID,					// int id of user in this db (for user matching on services, etc)
			"cohort" => $cohort,								// string containing cohort to enrol this user into
			"z" => rand(1, 1500),								// extra randomiser for when this string is encrypted (for variance)
		));
		$url = '<a target="'.esc_attr($target).'" class="'.esc_attr($class).'" href="'.get_option('wp2m_moodle_url').WP2M_MOODLE_PLUGIN_URL.encrypt_string($details, get_option('wp2m_shared_secret')).'">'.do_shortcode($content).'</a>';
	}		
	return $url;
}

/**
 * initialiser for registering scripts to the rich editor
 */
function wp2m_add_button() {
	if ( current_user_can('edit_posts') &&  current_user_can('edit_pages') ) {
	    add_filter('mce_external_plugins', 'wp2m_add_plugin');
	    add_filter('mce_buttons', 'wp2m_register_button');
	}
}
function wp2m_register_button($buttons) {
   array_push($buttons,"|","wp2m"); // pipe = break on toolbar
   return $buttons;
}
function wp2m_add_plugin($plugin_array) {
   $plugin_array['wp2m'] = plugins_url( 'wp2m.js', __FILE__ );
   return $plugin_array;
}

?>
