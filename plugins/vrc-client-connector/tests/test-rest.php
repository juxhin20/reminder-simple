<?php
use VRC_Client_Connector\Plugin;

class VRC_Client_Rest_Test extends WP_UnitTestCase {
public function setUp(): void {
parent::setUp();
Plugin::activate();
Plugin::instance();
update_option( 'vrc_client_fixtures', [
'cars' => [
[
'car_uid'   => 'car_a',
'name'      => 'City',
'updated_at'=> gmdate( 'Y-m-d H:i:s' ),
],
],
] );
}

public function test_cars_endpoint_requires_auth() {
$request = new WP_REST_Request( 'GET', '/vrc-sync/v1/cars' );
$response = rest_get_server()->dispatch( $request );
$this->assertSame( 401, $response->get_status() );
}

public function test_cars_endpoint_returns_data_with_token() {
$settings = get_option( Plugin::OPTION_SETTINGS );
$request = new WP_REST_Request( 'GET', '/vrc-sync/v1/cars' );
$request->set_header( 'authorization', 'Bearer ' . $settings['secret'] );
$response = rest_get_server()->dispatch( $request );
$this->assertSame( 200, $response->get_status() );
$data = $response->get_data();
$this->assertNotEmpty( $data['items'] );
}
}
