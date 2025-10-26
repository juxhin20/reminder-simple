<?php
/**
 * Plugin Name: VRC Master Aggregator
 * Description: Aggregates Vik Rent Car data from connected client sites.
 * Version: 0.1.0
 * Author: OpenAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VRC_MA_PATH', plugin_dir_path( __FILE__ ) );
define( 'VRC_MA_URL', plugin_dir_url( __FILE__ ) );
define( 'VRC_MA_VERSION', '0.1.0' );

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once VRC_MA_PATH . 'includes/class-plugin.php';

add_action( 'plugins_loaded', [ 'VRC_Master_Aggregator\\Plugin', 'instance' ] );

register_activation_hook( __FILE__, [ 'VRC_Master_Aggregator\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'VRC_Master_Aggregator\\Plugin', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'VRC_Master_Aggregator\\Plugin', 'uninstall' ] );
