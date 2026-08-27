# Installation

1. Copy (or clone) this plugin into `wp-content/plugins/wynko`.
2. Install PHP dependencies (the plugin loads its classes via the Composer
   PSR-4 autoloader, so this is required — not just for development):

   ```bash
   composer install --no-dev   # production
   # or: composer install       # includes the dev tooling + git hooks
   ```

3. Build the block assets:

   ```bash
   npm install
   npm run build
   ```

   This compiles `src/block` into `build/block` (block.json, index.js,
   index.asset.php) via `@wordpress/scripts`. The `build/` directory is not
   committed to version control, so this step is required after every fresh
   checkout.
4. Activate **Wynko** in wp-admin → Plugins.

---

Back to the [README](../README.md) · [All documentation](README.md)
