<?php
/**
 * Plugin Name: AMP
 * Description: Add AMP support to your WordPress site.
 * Plugin URI: https://github.com/automattic/amp-wp
 * Author: Automattic
 * Author URI: https://automattic.com
 * Version: 0.5.1
 * Text Domain: amp
 * Domain Path: /languages/
 * License: GPLv2 or later
 */

define( 'AMP__FILE__', __FILE__ );
define( 'AMP__DIR__', dirname( __FILE__ ) );
define( 'AMP__VERSION', '0.5.1' );

require_once( AMP__DIR__ . '/back-compat/back-compat.php' );
require_once( AMP__DIR__ . '/includes/amp-helper-functions.php' );
require_once( AMP__DIR__ . '/includes/admin/functions.php' );
require_once( AMP__DIR__ . '/includes/admin/class-amp-customizer.php' );
require_once( AMP__DIR__ . '/includes/settings/class-amp-customizer-settings.php' );
require_once( AMP__DIR__ . '/includes/settings/class-amp-customizer-design-settings.php' );

require_once( AMP__DIR__ . '/includes/actions/class-amp-frontend-actions.php' );
require_once( AMP__DIR__ . '/includes/actions/class-amp-paired-post-actions.php' );

register_activation_hook( __FILE__, 'amp_activate' );
function amp_activate() {
	if ( ! did_action( 'amp_init' ) ) {
		amp_init();
	}
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'amp_deactivate' );
function amp_deactivate() {
	// We need to manually remove the amp endpoint
	global $wp_rewrite;
	foreach ( $wp_rewrite->endpoints as $index => $endpoint ) {
		if ( AMP_QUERY_VAR === $endpoint[1] ) {
			unset( $wp_rewrite->endpoints[ $index ] );
			break;
		}
	}

	flush_rewrite_rules();
}

add_action( 'init', 'amp_init' );
function amp_init() {
	if ( false === apply_filters( 'amp_is_enabled', true ) ) {
		return;
	}

	define( 'AMP_QUERY_VAR', apply_filters( 'amp_query_var', 'amp' ) );

	do_action( 'amp_init' );

	load_plugin_textdomain( 'amp', false, plugin_basename( AMP__DIR__ ) . '/languages' );

	add_rewrite_endpoint( AMP_QUERY_VAR, EP_PERMALINK );
	add_post_type_support( 'post', AMP_QUERY_VAR );

	add_filter( 'request', 'amp_force_query_var_value' );
	add_action( 'wp', 'amp_maybe_add_actions' );

	// Redirect the old url of amp page to the updated url.
	add_filter( 'old_slug_redirect_url', 'amp_redirect_old_slug_to_new_url' );

	if ( class_exists( 'Jetpack' ) && ! ( defined( 'IS_WPCOM' ) && IS_WPCOM ) ) {
		require_once( AMP__DIR__ . '/jetpack-helper.php' );
	}
}

// Make sure the `amp` query var has an explicit value.
// Avoids issues when filtering the deprecated `query_string` hook.
function amp_force_query_var_value( $query_vars ) {
	if ( isset( $query_vars[ AMP_QUERY_VAR ] ) && '' === $query_vars[ AMP_QUERY_VAR ] ) {
		$query_vars[ AMP_QUERY_VAR ] = 1;
	}
	return $query_vars;
}

function amp_maybe_add_actions() {
	if ( ! is_singular() || is_feed() ) {
		return;
	}

	$is_amp_endpoint = is_amp_endpoint();

	// Cannot use `get_queried_object` before canonical redirect; see https://core.trac.wordpress.org/ticket/35344
	global $wp_query;
	$post = $wp_query->post;

	$supports = post_supports_amp( $post );

	if ( ! $supports ) {
		if ( $is_amp_endpoint ) {
			wp_safe_redirect( get_permalink( $post->ID ), 301 );
			exit;
		}
		return;
	}

	if ( $is_amp_endpoint ) {
		amp_prepare_render();
	} else {
		amp_add_frontend_actions();
	}
}

function amp_load_classes() {
	require_once( AMP__DIR__ . '/includes/class-amp-post-template.php' ); // this loads everything else
}

function amp_add_frontend_actions() {
	AMP_Frontend_Actions::register_hooks();
}

function amp_add_post_template_actions() {
	AMP_Paired_Post_Actions::register_hooks();
	require_once( AMP__DIR__ . '/includes/amp-post-template-functions.php' );
}

function amp_prepare_render() {
	add_action( 'template_redirect', 'amp_render' );
}

function amp_render() {
	$post_id = get_queried_object_id();
	amp_render_post( $post_id );
	exit;
}

function amp_render_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	amp_load_classes();

	do_action( 'pre_amp_render_post', $post_id );

	amp_add_post_template_actions();
	$template = new AMP_Post_Template( $post_id );
	$template->load();
}

/**
 * Bootstraps the AMP customizer.
 *
 * If the AMP customizer is enabled, initially drop the core widgets and menus panels. If the current
 * preview page isn't flagged as an AMP template, the core panels will be re-added and the AMP panel
 * hidden.
 *
 * @since 0.4
 */
function _amp_bootstrap_customizer() {
	// Drop core panels (menus, widgets) from the AMP customizer
	// `customize_loaded_components` runs super early so we need to call this regardless of whether the AMP customizer is enabled or not
	add_filter( 'customize_loaded_components', array( 'AMP_Template_Customizer', '_unregister_core_panels' ) );

	add_action( 'after_setup_theme', 'amp_maybe_init_customizer' );
}
add_action( 'plugins_loaded', '_amp_bootstrap_customizer', 9 ); // Should be hooked before priority 10 on 'plugins_loaded' to properly unhook core panels.

/**
 * Redirects the old AMP URL to the new AMP URL.
 * If post slug is updated the amp page with old post slug will be redirected to the updated url.
 *
 * @param  string $link New URL of the post.
 *
 * @return string $link URL to be redirected.
 */
function amp_redirect_old_slug_to_new_url( $link ) {

	if ( is_amp_endpoint() ) {
		$link = trailingslashit( trailingslashit( $link ) . AMP_QUERY_VAR );
	}

	return $link;
}
