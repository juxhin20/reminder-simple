# VRC Master Aggregator

This plugin aggregates Vik Rent Car inventory from remote client connectors.

## Features
- Registers the `vrc_car` post type and `vrc_client` taxonomy.
- Creates custom tables for prices, seasons, and availability.
- Pulls deltas from client connectors every 5 minutes (availability) and hourly (prices/seasons).
- Applies a configurable commission markup (default +20%).
- Provides a shortcode `[vrc_car_list]` to display synchronized cars.
- Includes a WP-CLI command `wp vrc sync <resource>` for manual execution.

## Setup
1. Install on the master WordPress site.
2. Configure clients via **Settings → VRC Sync**. Use JSON like:
   ```json
   {
     "client_1": {
       "name": "Client One",
       "site_url": "https://client1.example",
       "enabled": true,
       "auth": {"type": "app_pass", "user": "sync", "pass": "secret"},
       "commission": 0.18
     }
   }
   ```
3. Ensure the corresponding client sites run the Client Connector plugin.
4. Grant administrators the `manage_vrc` capability to access settings.

## Tests
Run `wp scaffold plugin-tests` for the site and then execute:
```
phpunit --testsuite vrc-master-aggregator
```
