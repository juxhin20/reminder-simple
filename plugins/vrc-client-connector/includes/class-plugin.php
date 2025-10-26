<?php
namespace VRC_Client_Connector;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

class Plugin {
    const OPTION_SETTINGS = 'vrc_client_settings';
    const OPTION_LAST_CHANGE = 'vrc_client_last_change';
    const RATE_LIMIT_PREFIX = 'vrc_client_rate_';

    protected static $instance;
    protected $settings = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        update_option( self::OPTION_SETTINGS, wp_parse_args( get_option( self::OPTION_SETTINGS, [] ), [
            'secret'      => wp_generate_password( 24, false ),
            'webhook_url' => '',
            'enabled'     => [ 'cars' => true, 'availability' => true, 'prices' => true, 'seasons' => true ],
        ] ) );
    }

    public static function deactivate() {
        // nothing for now.
    }

    public static function uninstall() {
        delete_option( self::OPTION_SETTINGS );
    }

    public function __construct() {
        $this->settings = get_option( self::OPTION_SETTINGS, [] );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        add_action( 'admin_post_vrc_client_save', [ $this, 'handle_settings_save' ] );
        add_action( 'vrc_client_maybe_webhook', [ $this, 'send_webhook' ], 10, 2 );
    }

    public function register_settings_page() {
        add_options_page(
            __( 'VRC Sync Client', 'vrc-client' ),
            __( 'VRC Sync Client', 'vrc-client' ),
            'manage_vrc',
            'vrc-client-sync',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_vrc' ) ) {
            wp_die( __( 'Unauthorized.', 'vrc-client' ) );
        }
        $settings = $this->settings;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'VRC Client Connector', 'vrc-client' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="vrc_client_save" />
                <?php wp_nonce_field( 'vrc_client_save' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Shared Secret', 'vrc-client' ); ?></th>
                        <td><input type="text" readonly value="<?php echo esc_attr( $settings['secret'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Webhook URL', 'vrc-client' ); ?></th>
                        <td><input name="settings[webhook_url]" type="url" value="<?php echo esc_attr( $settings['webhook_url'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handle_settings_save() {
        if ( ! current_user_can( 'manage_vrc' ) ) {
            wp_die( __( 'Unauthorized.', 'vrc-client' ) );
        }
        check_admin_referer( 'vrc_client_save' );
        $settings                 = $this->settings;
        $settings['webhook_url']  = esc_url_raw( $_POST['settings']['webhook_url'] ?? '' );
        update_option( self::OPTION_SETTINGS, $settings );
        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public function register_routes() {
        register_rest_route( 'vrc-sync/v1', '/cars', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'page'          => [ 'default' => 1 ],
                'per_page'      => [ 'default' => 50 ],
                'updated_after' => [],
            ],
            'callback'            => function ( WP_REST_Request $request ) {
                return $this->respond_with_cache( 'cars', $request, function () use ( $request ) {
                    return [ 'items' => Data::get_cars( $request->get_params() ), 'last_updated' => Data::last_updated( 'cars' ) ];
                } );
            },
        ] );

        register_rest_route( 'vrc-sync/v1', '/availability', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'car_uid'      => [ 'required' => false ],
                'date_from'    => [ 'required' => false ],
                'date_to'      => [ 'required' => false ],
                'updated_after'=> [],
            ],
            'callback'            => function ( WP_REST_Request $request ) {
                return $this->respond_with_cache( 'availability', $request, function () use ( $request ) {
                    return [ 'items' => Data::get_availability( $request->get_params() ), 'last_updated' => Data::last_updated( 'availability' ) ];
                } );
            },
        ] );

        register_rest_route( 'vrc-sync/v1', '/prices', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'car_uid'      => [],
                'updated_after'=> [],
            ],
            'callback'            => function ( WP_REST_Request $request ) {
                return $this->respond_with_cache( 'prices', $request, function () use ( $request ) {
                    return [ 'items' => Data::get_prices( $request->get_params() ), 'last_updated' => Data::last_updated( 'prices' ) ];
                } );
            },
        ] );

        register_rest_route( 'vrc-sync/v1', '/seasons', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'car_uid'      => [],
                'updated_after'=> [],
            ],
            'callback'            => function ( WP_REST_Request $request ) {
                return $this->respond_with_cache( 'seasons', $request, function () use ( $request ) {
                    return [ 'items' => Data::get_seasons( $request->get_params() ), 'last_updated' => Data::last_updated( 'seasons' ) ];
                } );
            },
        ] );
    }

    public function respond_with_cache( $resource, WP_REST_Request $request, callable $producer ) {
        if ( ! $this->check_rate_limit( $request ) ) {
            return new \WP_Error( 'vrc_rate_limited', __( 'Too many requests', 'vrc-client' ), [ 'status' => 429 ] );
        }
        $response = call_user_func( $producer );
        $etag     = '"' . md5( wp_json_encode( $response ) ) . '"';
        if ( $request->get_header( 'if-none-match' ) === $etag ) {
            $rest_response = new WP_REST_Response( null, 304 );
        } else {
            $rest_response = new WP_REST_Response( $response, 200 );
        }
        $rest_response->set_headers( [ 'ETag' => $etag, 'X-VRC-Signature' => $this->sign_response( $response ), 'X-VRC-Resource' => $resource ] );
        return $rest_response;
    }

    public function check_auth( WP_REST_Request $request ) {
        $secret = $this->settings['secret'] ?? '';
        $auth   = $request->get_header( 'authorization' );
        if ( $auth && 0 === strpos( $auth, 'Bearer ' ) ) {
            $token = substr( $auth, 7 );
            if ( hash_equals( $secret, $token ) ) {
                return true;
            }
        }
        return current_user_can( 'read' );
    }

    protected function check_rate_limit( WP_REST_Request $request ) {
        $key = self::RATE_LIMIT_PREFIX . md5( $request->get_route() . $request->get_header( 'authorization' ) . $request->get_param( 'car_uid' ) );
        $count = (int) get_transient( $key );
        if ( $count > 30 ) {
            return false;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    protected function sign_response( $data ) {
        return hash_hmac( 'sha256', wp_json_encode( $data ), $this->settings['secret'] ?? '' );
    }

    public function send_webhook( $resource, $payload ) {
        if ( empty( $this->settings['webhook_url'] ) ) {
            return;
        }
        wp_remote_post( $this->settings['webhook_url'], [
            'timeout' => 5,
            'body'    => wp_json_encode( [
                'resource' => $resource,
                'payload'  => $payload,
                'signature'=> $this->sign_response( $payload ),
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );
    }
}

class Data {
    protected static function sample_data() {
        return wp_parse_args( get_option( 'vrc_client_fixtures', [] ), [
            'cars' => [
                [
                    'car_uid'   => 'car_1',
                    'name'      => 'Compact',
                    'category'  => 'A',
                    'images'    => [],
                    'updated_at'=> gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
                    'seats'     => 4,
                    'transmission' => 'manual',
                ],
            ],
            'availability' => [
                [
                    'car_uid'   => 'car_1',
                    'date'      => gmdate( 'Y-m-d' ),
                    'status'    => 'free',
                    'updated_at'=> gmdate( 'Y-m-d H:i:s', strtotime( '-10 minutes' ) ),
                ],
            ],
            'prices' => [
                [
                    'car_uid'   => 'car_1',
                    'type'      => 'base',
                    'amount'    => 100,
                    'currency'  => 'EUR',
                    'updated_at'=> gmdate( 'Y-m-d H:i:s', strtotime( '-20 minutes' ) ),
                ],
            ],
            'seasons' => [
                [
                    'car_uid'   => 'car_1',
                    'name'      => 'Summer',
                    'date_from' => gmdate( 'Y-m-d', strtotime( 'June 1' ) ),
                    'date_to'   => gmdate( 'Y-m-d', strtotime( 'August 31' ) ),
                    'rules'     => [ 'weekdays' => [1,2,3,4,5], 'price' => 120 ],
                    'updated_at'=> gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
                ],
            ],
        ] );
    }

    public static function last_updated( $resource ) {
        $data = self::sample_data();
        $list = $data[ $resource ] ?? [];
        $times = wp_list_pluck( $list, 'updated_at' );
        return $times ? max( $times ) : gmdate( 'Y-m-d H:i:s' );
    }

    protected static function filter_updated_after( $items, $updated_after ) {
        if ( ! $updated_after ) {
            return $items;
        }
        return array_values( array_filter( $items, function ( $item ) use ( $updated_after ) {
            return empty( $item['updated_at'] ) || $item['updated_at'] > $updated_after;
        } ) );
    }

    public static function get_cars( $params ) {
        $data = self::sample_data()['cars'];
        $data = self::filter_updated_after( $data, $params['updated_after'] ?? null );
        $page = max( 1, (int) ( $params['page'] ?? 1 ) );
        $per  = max( 1, min( 100, (int) ( $params['per_page'] ?? 50 ) ) );
        return array_slice( $data, ( $page - 1 ) * $per, $per );
    }

    public static function get_availability( $params ) {
        $data = self::sample_data()['availability'];
        $data = self::filter_updated_after( $data, $params['updated_after'] ?? null );
        if ( ! empty( $params['car_uid'] ) ) {
            $data = array_values( array_filter( $data, function ( $item ) use ( $params ) {
                return $item['car_uid'] === $params['car_uid'];
            } ) );
        }
        if ( ! empty( $params['date_from'] ) ) {
            $data = array_values( array_filter( $data, function ( $item ) use ( $params ) {
                return $item['date'] >= $params['date_from'];
            } ) );
        }
        if ( ! empty( $params['date_to'] ) ) {
            $data = array_values( array_filter( $data, function ( $item ) use ( $params ) {
                return $item['date'] <= $params['date_to'];
            } ) );
        }
        return $data;
    }

    public static function get_prices( $params ) {
        $data = self::sample_data()['prices'];
        $data = self::filter_updated_after( $data, $params['updated_after'] ?? null );
        if ( ! empty( $params['car_uid'] ) ) {
            $data = array_values( array_filter( $data, function ( $item ) use ( $params ) {
                return $item['car_uid'] === $params['car_uid'];
            } ) );
        }
        return $data;
    }

    public static function get_seasons( $params ) {
        $data = self::sample_data()['seasons'];
        $data = self::filter_updated_after( $data, $params['updated_after'] ?? null );
        if ( ! empty( $params['car_uid'] ) ) {
            $data = array_values( array_filter( $data, function ( $item ) use ( $params ) {
                return $item['car_uid'] === $params['car_uid'];
            } ) );
        }
        return $data;
    }
}
