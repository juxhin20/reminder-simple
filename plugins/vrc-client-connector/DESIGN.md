# VRC Client Connector – Design Summary

## Overview
- Provides REST resources that normalize Vik Rent Car entities (cars, availability, prices, seasons).
- Uses a shared-secret bearer token for authentication plus optional WordPress capabilities.
- Responses include rate limiting, ETag/304 support, and HMAC signatures consumed by the master site.

## Data Access
- `Data` helper encapsulates read-only access. In production this would query Vik Rent Car tables; in tests it relies on option-based fixtures.
- `updated_after` filtering and pagination allow delta harvesting.

## Operations
- Admin page reveals generated secret and allows configuring webhook target for change notifications.
- When local data changes, call `do_action( 'vrc_client_maybe_webhook', $resource, $payload );` to notify the master.

## Security
- Bearer token must match stored secret; rate limiting prevents abuse.
- Responses are signed via `hash_hmac` to guarantee integrity.
