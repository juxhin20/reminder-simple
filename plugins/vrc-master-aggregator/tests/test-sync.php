<?php
use VRC_Master_Aggregator\Plugin;
use VRC_Master_Aggregator\Sync_Processor;
use VRC_Master_Aggregator\Commission;

class VRC_Master_Sync_Test extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        Plugin::instance();
        update_option( Plugin::OPTION_CLIENTS, [ 'test' => [ 'enabled' => true, 'site_url' => 'https://example.test' ] ] );
    }

    public function test_commission_math() {
        $this->assertSame( 120.0, Commission::apply( 100, 0.2 ) );
    }

    public function test_car_import_creates_post() {
        $processor = new Sync_Processor( 'test', [ 'enabled' => true ], [ 'default_commission' => 0.2 ] );
        $result = $processor->process( 'cars', [
            'items' => [
                [
                    'car_uid'   => 'car_1',
                    'name'      => 'Compact',
                    'description' => 'Auto',
                    'updated_at' => gmdate( 'Y-m-d H:i:s' ),
                    'images'    => [],
                ],
            ],
        ] );
        $this->assertSame( 1, $result['processed'] );
        $posts = get_posts( [ 'post_type' => Plugin::CPT_CAR, 'meta_key' => '_vrc_car_uid', 'meta_value' => 'car_1' ] );
        $this->assertCount( 1, $posts );
    }

    public function test_price_processing_applies_commission() {
        $processor = new Sync_Processor( 'test', [ 'enabled' => true ], [ 'default_commission' => 0.25 ] );
        $processor->process( 'cars', [
            'items' => [
                [
                    'car_uid' => 'car_2',
                    'name'    => 'SUV',
                ],
            ],
        ] );
        $processor->process( 'prices', [
            'items' => [
                [
                    'car_uid'   => 'car_2',
                    'amount'    => 100,
                    'type'      => 'base',
                    'currency'  => 'EUR',
                ],
            ],
        ] );
        $car_id = get_posts( [
            'post_type'  => Plugin::CPT_CAR,
            'meta_key'   => '_vrc_car_uid',
            'meta_value' => 'car_2',
            'fields'     => 'ids',
        ] )[0];
$public_price = (float) get_post_meta( $car_id, '_vrc_public_price', true );
$this->assertSame( 125.0, $public_price );
}
}
