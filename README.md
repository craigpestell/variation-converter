# Variation Converter for WooCommerce

Finds groups of simple WooCommerce products that are really one product in several variants ("Tri-Rings Black", "Tri-Rings Blue") and merges each group you approve into one variable product. The originals are hidden, not deleted, and Undo brings them back.

This is the development repo. The user-facing docs are in [`readme.txt`](readme.txt), which is also the wordpress.org listing.

## Status

- **Merge** (simple to variable): works, v0.1.0.
- **Split** (variable to simple): in development.

## Requirements

- WordPress 6.2+
- WooCommerce
- PHP 7.4+

## How it works

| File | What it does |
|---|---|
| `variation-converter.php` | Plugin header, loads the includes, name-cleaning helpers |
| `includes/scan.php` | Pure matching functions, no WordPress calls: SKU families, shared name prefixes, attribute guess, notes, validation |
| `includes/create.php` | Catalog query, scan cache, creating the variable product, Undo |
| `includes/admin.php` | The **Products > Variation Converter** screen and its form handlers |
| `tests/test-scan.php` | Offline tests for `scan.php` |

**Grouping.** Only published, visible simple products with a SKU are scanned. Products are grouped by SKU family (the SKU minus its last `-` segment and any short packaging suffix, so `SE1421-01 CL` gives `SE1421`), then split within a family by the longest run of leading name words two members share. The rest of each name becomes the variant label. The attribute (Color, Size, Flavor, Scent, Volume, else Option) is guessed from built-in word lists when most labels match. Results are cached in a transient for 30 minutes.

**Creating.** Builds the variable product from the first member (description, images, taxonomies, tax and shipping class) and one variation per member (SKU, prices, sale dates, stock, dimensions, image). WooCommerce's unique-SKU check is bypassed while saving, so each SKU then exists on both the variation and the hidden original. If any save fails, the partial product is deleted. Members are set to `hidden` catalog visibility, with their previous visibility and the parent ID stored in post meta.

**Undo.** Restores each member's stored visibility, removes the meta, and deletes the variable product and its variations.

If Relevanssi is active, changed products are reindexed (parent after variations, so variation SKUs are searchable).

There are no public hooks or settings yet. The attribute word lists are hardcoded in `varconv_attribute()`.

## Development

```sh
php tests/test-scan.php       # scan tests, no WordPress needed
find . -name '*.php' -exec php -l {} \;
```

To try it in WordPress, symlink or copy the folder into `wp-content/plugins/` on a site with WooCommerce and some simple products whose SKUs share a family.

All functions, constants and CSS classes use the `varconv_` / `VARCONV_` / `varconv-` prefix.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Made by [Craig Pestell](https://craigpestell.com).
