<?php
namespace VRC_Master_Aggregator;

use WP_CLI;
use WP_Error;
use WP_Query;

/**
 * Core plugin singleton.
 */
class Plugin {
const OPTION_CLIENTS = 'vrc_master_clients';
const OPTION_SETTINGS = 'vrc_master_settings';
const CPT_CAR = 'vrc_car';
const TAX_CLIENT = 'vrc_client';

/** @var Plugin */
protected static $instance;

/** @var array */
protected $settings = [];

/**
 * Return singleton instance.
 */
public static function instance() {
if ( null === self::$instance ) {
self::$instance = new self();
}
return self::$instance;
}

/**
 * Activation callback.
 */
public static function activate() {
self::instance()->create_tables();
self::instance()->schedule_events();
}

/**
 * Deactivation callback.
 */
public static function deactivate() {
self::instance()->unschedule_events();
}

/**
 * Uninstall callback.
 */
public static function uninstall() {
delete_option( self::OPTION_CLIENTS );
delete_option( self::OPTION_SETTINGS );
}

public function __construct() {
$this->settings = wp_parse_args(
get_option( self::OPTION_SETTINGS, [] ),
[
'default_commission' => 0.2,
'availability_window_days' => 365,
]
);

add_action( 'init', [ $this, 'register_post_types' ] );
add_action( 'init', [ $this, 'register_taxonomies' ] );
add_filter( 'cron_schedules', [ $this, 'register_intervals' ] );
add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
add_action( 'admin_post_vrc_master_save', [ $this, 'handle_admin_save' ] );
add_action( 'admin_post_vrc_master_run', [ $this, 'handle_admin_run' ] );
add_action( 'rest_api_init', [ $this, 'register_health_route' ] );
add_action( 'init', [ $this, 'register_shortcodes' ] );

add_action( 'vrc_sync_availability', [ $this, 'sync_availability' ] );
add_action( 'vrc_sync_prices', [ $this, 'sync_prices' ] );
add_action( 'vrc_sync_daily', [ $this, 'sync_daily' ] );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
WP_CLI::add_command( 'vrc sync', [ $this, 'cli_sync' ] );
}
}

public function register_post_types() {
register_post_type( self::CPT_CAR, [
'label' => __( 'VRC Car', 'vrc-master' ),
'public' => false,
'show_ui' => true,
'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
'has_archive' => false,
'capability_type' => 'post',
'map_meta_cap' => true,
] );
}

public function register_taxonomies() {
register_taxonomy( self::TAX_CLIENT, self::CPT_CAR, [
'label' => __( 'VRC Client', 'vrc-master' ),
'public' => false,
'show_ui' => true,
'hierarchical' => false,
] );
}

public function register_admin_menu() {
add_options_page(
__( 'VRC Sync', 'vrc-master' ),
__( 'VRC Sync', 'vrc-master' ),
'manage_vrc',
'vrc-master-sync',
[ $this, 'render_admin_page' ]
);
}

public function render_admin_page() {
if ( ! current_user_can( 'manage_vrc' ) ) {
wp_die( __( 'You do not have permission.', 'vrc-master' ) );
}
$clients  = get_option( self::OPTION_CLIENTS, [] );
$settings = $this->settings;
$nonce    = wp_create_nonce( 'vrc_master_save' );
?>
<div class="wrap">
<h1><?php esc_html_e( 'VRC Master Aggregator', 'vrc-master' ); ?></h1>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="vrc_master_save" />
<?php wp_nonce_field( 'vrc_master_save', '_wpnonce', false ); ?>
<h2><?php esc_html_e( 'Global Settings', 'vrc-master' ); ?></h2>
<table class="form-table">
<tr>
<th scope="row"><?php esc_html_e( 'Default Commission', 'vrc-master' ); ?></th>
<td><input name="settings[default_commission]" type="number" step="0.01" value="<?php echo esc_attr( $settings['default_commission'] ); ?>" /></td>
</tr>
<tr>
<th scope="row"><?php esc_html_e( 'Availability Window (days)', 'vrc-master' ); ?></th>
<td><input name="settings[availability_window_days]" type="number" value="<?php echo esc_attr( $settings['availability_window_days'] ); ?>" /></td>
</tr>
</table>
<h2><?php esc_html_e( 'Clients', 'vrc-master' ); ?></h2>
<p><?php esc_html_e( 'Manage connected client sites. JSON configuration per client, keyed by slug.', 'vrc-master' ); ?></p>
<textarea name="clients" rows="12" class="large-text"><?php echo esc_textarea( wp_json_encode( $clients, JSON_PRETTY_PRINT ) ); ?></textarea>
<?php submit_button(); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'vrc_master_run', '_wpnonce', false ); ?>
<input type="hidden" name="action" value="vrc_master_run" />
<p><?php submit_button( __( 'Run Sync Now', 'vrc-master' ), 'secondary', 'run_availability' ); ?></p>
</form>
</div>
<?php
}

public function handle_admin_save() {
if ( ! current_user_can( 'manage_vrc' ) ) {
wp_die( __( 'Unauthorized.', 'vrc-master' ) );
}
check_admin_referer( 'vrc_master_save' );
$clients  = json_decode( wp_unslash( $_POST['clients'] ?? '[]' ), true );
$settings = wp_unslash( $_POST['settings'] ?? [] );
if ( ! is_array( $clients ) ) {
$clients = [];
}
update_option( self::OPTION_CLIENTS, $this->sanitize_clients( $clients ) );
update_option( self::OPTION_SETTINGS, [
'default_commission'        => isset( $settings['default_commission'] ) ? (float) $settings['default_commission'] : 0.2,
'availability_window_days' => isset( $settings['availability_window_days'] ) ? absint( $settings['availability_window_days'] ) : 365,
] );
wp_safe_redirect( add_query_arg( 'updated', 'true', wp_get_referer() ) );
exit;
}

public function handle_admin_run() {
if ( ! current_user_can( 'manage_vrc' ) ) {
wp_die( __( 'Unauthorized.', 'vrc-master' ) );
}
check_admin_referer( 'vrc_master_run' );
$this->sync_prices();
$this->sync_availability();
wp_safe_redirect( add_query_arg( 'run', 'true', wp_get_referer() ) );
exit;
}

public function register_health_route() {
register_rest_route( 'vrc-sync/v1', '/health', [
'permission_callback' => '__return_true',
'callback'            => function () {
return [
'clients'   => array_keys( get_option( self::OPTION_CLIENTS, [] ) ),
'last_run'  => get_option( 'vrc_master_last_run' ),
'next_cron' => wp_next_scheduled( 'vrc_sync_availability' ),
];
},
] );
}

public function register_shortcodes() {
add_shortcode( 'vrc_car_list', [ $this, 'shortcode_car_list' ] );
}

public function shortcode_car_list( $atts ) {
$atts = shortcode_atts( [ 'client' => '', 'limit' => 10 ], $atts );
$query_args = [
'post_type'      => self::CPT_CAR,
'posts_per_page' => (int) $atts['limit'],
];
if ( $atts['client'] ) {
$query_args['tax_query'] = [
[
'taxonomy' => self::TAX_CLIENT,
'field'    => 'slug',
'terms'    => sanitize_key( $atts['client'] ),
],
];
}
$query = new WP_Query( $query_args );
ob_start();
if ( $query->have_posts() ) {
echo '<ul class="vrc-car-list">';
while ( $query->have_posts() ) {
$query->the_post();
echo '<li>' . esc_html( get_the_title() ) . ' — ' . esc_html( get_post_meta( get_the_ID(), '_vrc_public_price', true ) ) . '</li>';
}
echo '</ul>';
} else {
echo '<p>' . esc_html__( 'No cars available.', 'vrc-master' ) . '</p>';
}
wp_reset_postdata();
return ob_get_clean();
}

protected function sanitize_clients( array $clients ) {
foreach ( $clients as $id => &$client ) {
$client['name']       = sanitize_text_field( $client['name'] ?? '' );
$client['site_url']   = esc_url_raw( $client['site_url'] ?? '' );
$client['enabled']    = ! empty( $client['enabled'] );
$client['timezone']   = sanitize_text_field( $client['timezone'] ?? 'UTC' );
$client['commission'] = isset( $client['commission'] ) ? (float) $client['commission'] : null;
$client['auth']       = isset( $client['auth'] ) && is_array( $client['auth'] ) ? array_map( 'sanitize_text_field', $client['auth'] ) : [];
$client['resources']  = isset( $client['resources'] ) && is_array( $client['resources'] ) ? array_map( 'boolval', $client['resources'] ) : [];
}
return $clients;
}

public function schedule_events() {
if ( ! wp_next_scheduled( 'vrc_sync_availability' ) ) {
wp_schedule_event( time() + 60, 'five_minutes', 'vrc_sync_availability' );
}
if ( ! wp_next_scheduled( 'vrc_sync_prices' ) ) {
wp_schedule_event( time() + 120, 'hourly', 'vrc_sync_prices' );
}
if ( ! wp_next_scheduled( 'vrc_sync_daily' ) ) {
wp_schedule_event( time() + 180, 'daily', 'vrc_sync_daily' );
}
}

public function unschedule_events() {
wp_clear_scheduled_hook( 'vrc_sync_availability' );
wp_clear_scheduled_hook( 'vrc_sync_prices' );
wp_clear_scheduled_hook( 'vrc_sync_daily' );
}

public function create_tables() {
global $wpdb;
$charset = $wpdb->get_charset_collate();
$tables  = [];
$tables[] = "CREATE TABLE {$wpdb->prefix}vrc_prices (\n\tID bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tcar_id bigint(20) unsigned NOT NULL,\n\tprice_type varchar(20) NOT NULL,\n\tseason_id bigint(20) unsigned NULL,\n\tcurrency varchar(8) NOT NULL,\n\traw_price decimal(10,2) NOT NULL,\n\tpublic_price decimal(10,2) NOT NULL,\n\tupdated_at datetime NOT NULL,\n\tPRIMARY KEY (ID),\n\tKEY car_id (car_id)\n) $charset;";
$tables[] = "CREATE TABLE {$wpdb->prefix}vrc_seasons (\n\tseason_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tcar_id bigint(20) unsigned NOT NULL,\n\tname varchar(200) NOT NULL,\n\tdate_from date NOT NULL,\n\tdate_to date NOT NULL,\n\trules_json longtext NOT NULL,\n\tupdated_at datetime NOT NULL,\n\tPRIMARY KEY (season_id),\n\tKEY car_id (car_id)\n) $charset;";
$tables[] = "CREATE TABLE {$wpdb->prefix}vrc_availability (\n\tID bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tcar_id bigint(20) unsigned NOT NULL,\n\tdate date NOT NULL,\n\tstatus varchar(20) NOT NULL,\n\tsource_res_id varchar(100) NULL,\n\tupdated_at datetime NOT NULL,\n\tUNIQUE KEY car_date (car_id,date),\n\tKEY updated_at (updated_at)\n) $charset;";
foreach ( $tables as $sql ) {
dbDelta( $sql );
}

}

public function register_intervals( $schedules ) {
$schedules['five_minutes'] = [
'interval' => 5 * MINUTE_IN_SECONDS,
'display'  => __( 'Every Five Minutes', 'vrc-master' ),
];
return $schedules;
}

protected function clients() {
return array_filter( get_option( self::OPTION_CLIENTS, [] ), function ( $client ) {
return ! empty( $client['enabled'] );
} );
}

public function sync_prices( $args = [] ) {
return $this->run_sync( 'prices', $args );
}

public function sync_availability( $args = [] ) {
return $this->run_sync( 'availability', $args );
}

public function sync_daily( $args = [] ) {
$this->run_sync( 'cars', $args );
$this->run_sync( 'seasons', $args );
}

public function cli_sync( $args, $assoc_args ) {
$resource = $args[0] ?? 'cars';
$client   = $assoc_args['client'] ?? 'all';
$since    = isset( $assoc_args['since'] ) ? sanitize_text_field( $assoc_args['since'] ) : null;
$result   = $this->run_sync( $resource, [ 'client' => $client, 'since' => $since ] );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
WP_CLI::success( sprintf( 'Synced %s: %s', $resource, wp_json_encode( $result ) ) );
}
return $result;
}

protected function run_sync( $resource, $args ) {
$clients = $this->clients();
if ( isset( $args['client'] ) && 'all' !== $args['client'] ) {
$clients = isset( $clients[ $args['client'] ] ) ? [ $args['client'] => $clients[ $args['client'] ] ] : [];
}
$results = [];
foreach ( $clients as $id => $client ) {
if ( isset( $client['resources'][ $resource ] ) && ! $client['resources'][ $resource ] ) {
continue;
}
$results[ $id ] = $this->sync_client_resource( $id, $client, $resource, $args );
}
update_option( 'vrc_master_last_run', [ 'resource' => $resource, 'time' => current_time( 'mysql' ) ] );
return $results;
}

protected function sync_client_resource( $client_id, $client, $resource, $args ) {
$http = new Rest_Client( $client );
$since = $args['since'] ?? get_option( "vrc_master_last_{$resource}_{$client_id}" );
$params = [];
if ( $since ) {
$params['updated_after'] = $since;
}
if ( 'availability' === $resource ) {
$params['date_from'] = wp_date( 'Y-m-d' );
$params['date_to']   = wp_date( 'Y-m-d', time() + (int) $this->settings['availability_window_days'] * DAY_IN_SECONDS );
}
$processor = new Sync_Processor( $client_id, $client, $this->settings );
$total = [ 'processed' => 0, 'last_updated' => null ];
$page = 1;
do {
$params['page'] = $page;
$data = $http->get( $resource, $params );
if ( is_wp_error( $data ) ) {
return $data;
}
$chunk = $processor->process( $resource, $data );
$total['processed'] += $chunk['processed'] ?? 0;
if ( ! empty( $chunk['last_updated'] ) && ( empty( $total['last_updated'] ) || $chunk['last_updated'] > $total['last_updated'] ) ) {
$total['last_updated'] = $chunk['last_updated'];
}
$page++;
$has_more = ! empty( $data['items'] );
} while ( $has_more && $page < 50 );
update_option( "vrc_master_last_{$resource}_{$client_id}", $total['last_updated'] ?? current_time( 'mysql', true ) );
return $total;
}
}

/**
 * REST client for pulling data from client connectors.
 */
class Rest_Client {
protected $client;

public function __construct( array $client ) {
$this->client = $client;
}

public function get( $resource, array $params = [] ) {
$endpoint = trailingslashit( $this->client['site_url'] ) . 'wp-json/vrc-sync/v1/' . $resource;
$args = [
'timeout' => 20,
'headers' => [ 'Accept' => 'application/json' ],
];
if ( ! empty( $params ) ) {
$endpoint = add_query_arg( array_map( 'sanitize_text_field', $params ), $endpoint );
}
if ( isset( $this->client['auth']['type'] ) && 'app_pass' === $this->client['auth']['type'] ) {
$args['headers']['Authorization'] = 'Basic ' . base64_encode( $this->client['auth']['user'] . ':' . $this->client['auth']['pass'] );
}
$response = wp_remote_get( $endpoint, $args );
if ( is_wp_error( $response ) ) {
return $response;
}
$code = wp_remote_retrieve_response_code( $response );
if ( 200 !== $code ) {
return new WP_Error( 'vrc_http_error', 'Unexpected response code ' . $code );
}
$data = json_decode( wp_remote_retrieve_body( $response ), true );
if ( null === $data ) {
return new WP_Error( 'vrc_invalid_json', 'Invalid JSON from client' );
}
return $data;
}
}

/**
 * Handles persistence of resources.
 */
class Sync_Processor {
protected $client_id;
protected $client;
protected $settings;

public function __construct( $client_id, array $client, array $settings ) {
$this->client_id = $client_id;
$this->client    = $client;
$this->settings  = $settings;
}

public function process( $resource, array $payload ) {
$method = 'process_' . $resource;
if ( method_exists( $this, $method ) ) {
return $this->$method( $payload );
}
return [ 'processed' => 0 ];
}

protected function process_cars( array $payload ) {
$count = 0;
$last  = null;
foreach ( $payload['items'] ?? [] as $car ) {
$car_post_id = $this->upsert_car_post( $car );
if ( $car_post_id ) {
$count++;
$last = $car['updated_at'] ?? $last;
}
}
return [ 'processed' => $count, 'last_updated' => $last ];
}

protected function process_prices( array $payload ) {
global $wpdb;
$count = 0;
$last  = null;
foreach ( $payload['items'] ?? [] as $price ) {
$car_id = $this->map_car_uid_to_post( $price['car_uid'] );
if ( ! $car_id ) {
continue;
}
$commission = $this->resolve_commission( $car_id );
$public     = Commission::apply( (float) $price['amount'], $commission );
$wpdb->replace( $wpdb->prefix . 'vrc_prices', [
'car_id'       => $car_id,
'price_type'   => $price['type'] ?? 'base',
'season_id'    => $price['season_id'] ?? null,
'currency'     => $price['currency'] ?? 'EUR',
'raw_price'    => $price['amount'],
'public_price' => $public,
'updated_at'   => $price['updated_at'] ?? current_time( 'mysql', true ),
] );
update_post_meta( $car_id, '_vrc_public_price', $public );
$count++;
$last = $price['updated_at'] ?? $last;
}
return [ 'processed' => $count, 'last_updated' => $last ];
}

protected function process_seasons( array $payload ) {
global $wpdb;
$count = 0;
$last  = null;
foreach ( $payload['items'] ?? [] as $season ) {
$car_id = $this->map_car_uid_to_post( $season['car_uid'] );
if ( ! $car_id ) {
continue;
}
$wpdb->replace( $wpdb->prefix . 'vrc_seasons', [
'car_id'     => $car_id,
'name'       => $season['name'],
'date_from'  => $season['date_from'],
'date_to'    => $season['date_to'],
'rules_json' => wp_json_encode( $season['rules'] ),
'updated_at' => $season['updated_at'] ?? current_time( 'mysql', true ),
] );
$count++;
$last = $season['updated_at'] ?? $last;
}
return [ 'processed' => $count, 'last_updated' => $last ];
}

protected function process_availability( array $payload ) {
global $wpdb;
$count = 0;
$last  = null;
foreach ( $payload['items'] ?? [] as $slot ) {
$car_id = $this->map_car_uid_to_post( $slot['car_uid'] );
if ( ! $car_id ) {
continue;
}
$wpdb->replace( $wpdb->prefix . 'vrc_availability', [
'car_id'      => $car_id,
'date'        => $slot['date'],
'status'      => $slot['status'],
'source_res_id' => $slot['reservation_id'] ?? null,
'updated_at'  => $slot['updated_at'] ?? current_time( 'mysql', true ),
] );
$count++;
$last = $slot['updated_at'] ?? $last;
}
return [ 'processed' => $count, 'last_updated' => $last ];
}

protected function upsert_car_post( $car ) {
$uid    = sanitize_key( $car['car_uid'] );
$client = sanitize_key( $this->client_id );
$postarr = [
'post_type'    => Plugin::CPT_CAR,
'post_status'  => 'publish',
'post_title'   => sanitize_text_field( $car['name'] ?? $uid ),
'post_name'    => sanitize_title( $uid . '-' . $client ),
'post_content' => wp_kses_post( $car['description'] ?? '' ),
];
$existing_id = $this->map_car_uid_to_post( $uid );
if ( $existing_id ) {
$postarr['ID'] = $existing_id;
$post_id       = wp_update_post( wp_slash( $postarr ), true );
} else {
$post_id = wp_insert_post( wp_slash( $postarr ), true );
}
if ( is_wp_error( $post_id ) ) {
return 0;
}
wp_set_object_terms( $post_id, $client, Plugin::TAX_CLIENT );
update_post_meta( $post_id, '_vrc_car_uid', $uid );
update_post_meta( $post_id, '_vrc_client_id', $client );
$last = $car['updated_at'] ?? current_time( 'mysql', true );
update_post_meta( $post_id, '_vrc_updated_at', $last );
if ( ! empty( $car['images'] ) ) {
Media_Sync::sync_images( $post_id, $car['images'] );
}
return $post_id;
}

protected function map_car_uid_to_post( $uid ) {
$query = new WP_Query( [
'post_type'  => Plugin::CPT_CAR,
'fields'     => 'ids',
'meta_query' => [
[
'key'   => '_vrc_car_uid',
'value' => sanitize_key( $uid ),
],
],
'tax_query'  => [
[
'taxonomy' => Plugin::TAX_CLIENT,
'field'    => 'slug',
'terms'    => sanitize_key( $this->client_id ),
],
],
'posts_per_page' => 1,
] );
return $query->posts ? $query->posts[0] : 0;
}

protected function resolve_commission( $car_id ) {
$client_commission = $this->client['commission'] ?? null;
$car_commission    = get_post_meta( $car_id, '_vrc_commission_override', true );
if ( '' !== $car_commission && null !== $car_commission ) {
return (float) $car_commission;
}
if ( null !== $client_commission ) {
return (float) $client_commission;
}
return (float) ( $this->settings['default_commission'] ?? 0.2 );
}
}

/**
 * Commission helper.
 */
class Commission {
public static function apply( $raw, $commission ) {
$commission = (float) $commission;
$public     = (float) $raw * ( 1 + $commission );
$public     = round( $public, 2 );
return apply_filters( 'vrc_master_public_price', $public, $raw, $commission );
}
}

/**
 * Media synchronization helper.
 */
class Media_Sync {
public static function sync_images( $post_id, array $images ) {
foreach ( $images as $image ) {
if ( empty( $image['url'] ) ) {
continue;
}
self::maybe_attach_image( $post_id, esc_url_raw( $image['url'] ) );
}
}

protected static function maybe_attach_image( $post_id, $url ) {
$hash = md5( $url );
$existing = get_posts( [
'post_type'  => 'attachment',
'fields'     => 'ids',
'numberposts'=> 1,
'meta_key'   => '_vrc_hash',
'meta_value' => $hash,
] );
if ( $existing ) {
set_post_thumbnail( $post_id, $existing[0] );
return;
}
require_once ABSPATH . 'wp-admin/includes/file.php';
$media = media_sideload_image( $url, $post_id, null, 'id' );
if ( ! is_wp_error( $media ) ) {
update_post_meta( $media, '_vrc_hash', $hash );
set_post_thumbnail( $post_id, $media );
}
}
}
