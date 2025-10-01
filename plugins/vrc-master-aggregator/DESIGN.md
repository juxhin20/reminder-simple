# VRC Master Aggregator – Design Summary

## Architecture
- **Plugin bootstrap** wires a singleton `Plugin` that orchestrates CPT/cron/REST integrations.
- **Data persistence** uses custom tables (`vrc_prices`, `vrc_seasons`, `vrc_availability`) created via `dbDelta`.
- **Sync pipeline** iterates configured clients, pages through REST resources, and hands items to `Sync_Processor` for idempotent upserts.
- **Commission engine** (`Commission::apply`) stores both raw and marked-up prices, applying defaults or overrides.
- **Media sync** deduplicates attachments by hash before sideloading.
- **Admin UX** provides JSON configuration of clients and manual "Run sync" action.
- **CLI** command `wp vrc sync <resource>` enables automation.

## Data Flow
1. Cron fires (5 min availability / hourly pricing / daily full) → `run_sync()`.
2. `Rest_Client` authenticates against client connector and collects deltas (`updated_after`).
3. `Sync_Processor` upserts posts/tables, recalculates commission, and updates meta.
4. Status stored in `vrc_master_last_*` options for delta continuity.

## Extensibility
- Clients can disable resources individually via config.
- Additional price transformations can hook into `vrc_master_public_price` (see TODO in code) without schema changes.
- Media sync abstracts out dedupe to allow CDN integration.
