<?php
/**
 * Plugin Name: VRC Client Connector
 * Description: Exposes Vik Rent Car inventory via normalized REST endpoints.
 * Version: 0.1.0
 * Author: OpenAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VRC_CC_PATH', plugin_dir_path( __FILE__ ) );
define( 'VRC_CC_VERSION', '0.1.0' );

require_once VRC_CC_PATH . 'includes/class-plugin.php';

add_action( 'plugins_loaded', [ 'VRC_Client_Connector\\Plugin', 'instance' ] );

register_activation_hook( __FILE__, [ 'VRC_Client_Connector\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'VRC_Client_Connector\\Plugin', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'VRC_Client_Connector\\Plugin', 'uninstall' ] );
