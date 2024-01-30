<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://Kevin-Greene.com
 * @since             1.0.0
 * @package           twilio_responder
 *
 * @wordpress-plugin
 * Plugin Name:       Twilio MobileCare Responder
 * Plugin URI:        https://Kevin-Greene.com
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.0
 * Author:            Kevin Greene
 * Author URI:        https://Kevin-Greene.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       twilio-responder
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'twilio_responder_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-twilio-responder-activator.php
 */
function activate_twilio_responder() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-twilio-responder-activator.php';
	twilio_responder_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-twilio-responder-deactivator.php
 */
function deactivate_twilio_responder() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-twilio-responder-deactivator.php';
	twilio_responder_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_twilio_responder' );
register_deactivation_hook( __FILE__, 'deactivate_twilio_responder' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-twilio-responder.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_twilio_responder() {

	$plugin = new twilio_responder();
	$plugin->run();

}
run_twilio_responder();
