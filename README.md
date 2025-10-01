# VRC Sync Plugins

This repository contains the **VRC Master Aggregator** and **VRC Client Connector** WordPress plugins.

## Packaging the plugins for WordPress upload

Use the provided helper script to generate correctly structured ZIP archives that can be uploaded through **Plugins → Add New → Upload Plugin**:

```bash
./package-plugins.sh
```

The script creates `dist/vrc-client-connector.zip` and `dist/vrc-master-aggregator.zip`. Each archive already has the expected top-level plugin directory (e.g. `vrc-client-connector/vrc-client-connector.php`), so WordPress will recognise the plugin headers during installation.

If you prefer to package manually, make sure you zip the plugin directory itself rather than the files inside it:

```bash
cd plugins
zip -r ../dist/vrc-client-connector.zip vrc-client-connector
zip -r ../dist/vrc-master-aggregator.zip vrc-master-aggregator
```

Uploading archives that contain an extra parent folder (for example, `plugins/vrc-client-connector/...`) will trigger the “No valid plugins were found” error. Always check the ZIP root before uploading; it should contain the plugin folder directly.
