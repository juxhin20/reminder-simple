# VRC Client Connector

Expose Vik Rent Car inventory via normalized REST endpoints consumed by the master aggregator.

## Features
- Authenticated REST endpoints under `/wp-json/vrc-sync/v1/*` for cars, availability, prices, and seasons.
- Optional webhook pings when local data changes (trigger `do_action( 'vrc_client_maybe_webhook', $resource, $payload )`).
- Rate limiting, ETag caching, and HMAC signatures for responses.
- Admin settings screen showing the generated shared secret and webhook URL configuration.

## Fixtures
Populate sample data for testing by updating the `vrc_client_fixtures` option:
```php
update_option( 'vrc_client_fixtures', [
    'cars' => [
        [ 'car_uid' => 'car_1', 'name' => 'Compact', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
    ],
] );
```

## Authentication
Send a `Bearer` token header using the shared secret displayed in the settings page.

## Tests
Run PHPUnit with the WordPress test suite initialized:
```
phpunit --testsuite vrc-client-connector
```
