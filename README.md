# Variation Converter for WooCommerce

Finds groups of simple WooCommerce products that are really one product in several variants ("Tri-Rings Black", "Tri-Rings Blue") and merges each group you approve into one variable product. It also splits a variable product into one simple product per variation. The originals are hidden, not deleted, and Undo brings them back.

This is the development repo. The user-facing docs are in [`readme.txt`](readme.txt), which is also the wordpress.org listing.

## Status

- **Merge** (simple to variable): works, v0.1.0.
- **Split** (variable to simple): works, v0.1.0.

## Requirements

- WordPress 6.5+
- WooCommerce 8.0+
- PHP 7.4+

## How it works

| File | What it does |
|---|---|
| `variation-converter-for-woocommerce.php` | Plugin header, loads the includes, name-cleaning helpers |
| `includes/scan.php` | Pure matching functions, no WordPress calls besides translation: SKU families, shared name prefixes, attribute guess, notes, validation |
| `includes/create.php` | Catalog query, scan cache, creating the variable product, Undo |
| `includes/split.php` | Variable product list, splitting into simple products, Undo of a split |
| `includes/redirect.php` | 301 from a combined simple product's page to its variable product, with its option chosen; Undo ends it |
| `includes/admin.php` | The **Products > Variation Converter** screen, its tabs and form handlers |
| `includes/admin-combine.php` | The Combine tab as a list table: views (Ready, Needs review, Skipped), search, filters, sortable columns, bulk and row actions, the review card that opens under its row, Screen Options (groups per page) |
| `includes/admin-split.php` | The Split tab (variable products as a list table, card opening inline) and the History tab (one dated table with views, search and Undo) |
| `includes/admin-products.php` | Products > All Products bulk action "Combine into variable product": opens a card for the checked products, whatever their SKUs |
| `includes/ajax.php` | admin-ajax handlers so the Combine tab creates, undoes, skips and bulk-creates without reloading |
| `assets/admin.css`, `assets/admin.js` | Screen styles; opening review cards, Select by SKU, the row filter, drag and keyboard reorder, price preview, batched bulk create and the confirm before Undo. Every form also works without scripts |
| `uninstall.php` | Removes the dismissed list and cached scan on delete; products are left alone |
| `includes/cli.php` | WP-CLI commands `wp variation-converter combine` and `undo` |
| `tests/integration-bulk.php` | Bulk pieces against a real site: run lock, remembered unchecked members, chosen categories, `varconv_convert()` (`wp eval-file`, test site only) |
| `tests/test-scan.php` | Offline tests for `scan.php` |
| `tests/browser-combine.mjs` | Browser walkthrough of the Combine tab on the harness demo catalog; saves screenshots (`harness/wp-harness browse tests/browser-combine.mjs --out shots`) |
| `tests/test-split.php` | Offline tests for the pure helpers in `split.php` |
| `tests/integration-redirect.php` | Old product URLs after a combine, the filter, and Undo (`wp eval-file`, test site only) |
| `tests/integration-split.php` | Split, Undo and the merge interplay against a real site: `wp eval-file tests/integration-split.php` (makes and deletes its own products; use a test site) |

**Grouping.** Only published, visible simple products with a SKU are scanned. Products are grouped by SKU family (the SKU minus its last `-` segment and any short packaging suffix, so `SE1421-01 CL` gives `SE1421`), then split within a family by the longest run of leading name words two members share. A brand at the start of a name doesn't count as shared, so "Acme Plug" and "Acme Wand" stay apart: brands are the WooCommerce Brands terms plus any 1 to 3 word name start found in 5 or more SKU families. The rest of each name becomes the variant label, or the SKU when nothing is left. Products sharing one SKU are copies, not variants: the lowest ID is scanned and the rest are noted. The attribute (Color, Size, Flavor, Scent, Volume, else Option) is guessed from built-in word lists when most labels match. Results are cached in a transient for 30 minutes.

**Creating.** Builds the variable product from the first member (description, images, taxonomies, tax and shipping class) and one variation per member (SKU, prices, sale dates, stock, dimensions, image). WooCommerce's unique-SKU check is bypassed while saving, so each SKU then exists on both the variation and the hidden original. If any save fails, the partial product is deleted. Members are set to `hidden` catalog visibility, with their previous visibility and the parent ID stored in post meta.

**Splitting.** Makes one simple product per checked variation (SKU, prices, sale dates, stock, virtual/downloadable, dimensions, image), taking the variable product's descriptions, gallery, taxonomies, non-variation attributes, tax status and purchase note. Fields a variation leaves to its parent (weight, dimensions, tax class, shipping class, image) come from the parent. A variation that shares its parent's stock count gets no stock count of its own, so stock is not multiplied. Variations set to "Any" for an attribute can't be split. The variable product is set to `hidden`, with its previous visibility, the new IDs (`_varconv_split`) and the time (`_varconv_time`, also set on combined products for History) in post meta, and each new product records its source (`_varconv_split_from`); split products are left out of the merge scan. A product made by merging can't be split; it offers Undo instead.

**Undo.** Restores each member's stored visibility, removes the meta, and deletes the variable product and its variations.

If Relevanssi is active, changed products are reindexed (parent after variations, so variation SKUs are searchable).

There are no settings. The attribute word lists are hardcoded in `varconv_attribute()`.

## WP-CLI

```
wp variation-converter combine --all --dry-run          # list what the scan would create, with each group's key
wp variation-converter combine --all [--min-size=3] [--skip=price,cats] [--limit=50]
wp variation-converter combine <key>...                  # just these groups
wp variation-converter undo <id>... | --all [--yes]      # combined or split products
```

`combine` uses the proposed name, attribute, SKU and labels, leaves out members unchecked on the admin page, skips groups that need a label, and leaves a parent SKU empty when it is already in use. Only one bulk run can create products at a time (an option row taken with `INSERT IGNORE`, which also works on SQLite); a run that dies loses the lock after 10 minutes.

## Hooks

| Filter | Arguments | Use |
|---|---|---|
| `varconv_scan_products` | `array $products` (id, sku, name, title, price, stock, cats, brands) | Leave products out of the scan, such as testers or display units |
| `varconv_brand_names` | `string[] $names` (default: WooCommerce Brands terms) | Add or replace the brand names a product name can start with |
| `varconv_collections` | `array $collections`, `array $products` | Change the proposed groups after the scan: add members, drop groups. Skipped groups are kept apart for the Skipped view and are not passed in. Each group has key, family, name, brand, attribute, sku, members (with label) and notes (kind => text) |
| `varconv_redirect_url` | `string $url`, `int $id`, `int $parent_id` | Change where a combined simple product's page redirects (the variable product with its option chosen); return `''` to show the old page instead |

The scan result is cached for 30 minutes, so clear the `varconv_scan` transient (or click **Scan again**) after changing a filter.

## Development

```sh
php tests/test-scan.php       # scan tests, no WordPress needed
find . -name '*.php' -exec php -l {} \;
```

To try it in WordPress, `harness/wp-harness up` makes a throwaway WordPress + WooCommerce site on SQLite with this plugin active and a messy demo catalog, at http://localhost:8080 (admin / admin). Then:

```sh
harness/wp-harness test     # all of tests/, the integration ones with wp eval-file
harness/wp-harness check    # Plugin Check
harness/wp-harness shot 'edit.php?post_type=product&page=variation-converter' --out shots
harness/wp-harness down
```

It needs PHP with pdo_sqlite, or Docker when there's no PHP. See [harness/README.md](harness/README.md).

All functions, constants and CSS classes use the `varconv_` / `VARCONV_` / `varconv-` prefix.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Made by [Craig Pestell](https://craigpestell.com).
