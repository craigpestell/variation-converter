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
| `variation-converter.php` | Plugin header, loads the includes, name-cleaning helpers |
| `includes/scan.php` | Pure matching functions, no WordPress calls besides translation: SKU families, shared name prefixes, attribute guess, notes, validation |
| `includes/create.php` | Catalog query, scan cache, creating the variable product, Undo |
| `includes/split.php` | Variable product list, splitting into simple products, Undo of a split |
| `includes/admin.php` | The **Products > Variation Converter** screen and its form handlers |
| `assets/admin.css`, `assets/admin.js` | Screen styles, and the confirm before Undo |
| `uninstall.php` | Removes the dismissed list and cached scan on delete; products are left alone |
| `tests/test-scan.php` | Offline tests for `scan.php` |
| `tests/test-split.php` | Offline tests for the pure helpers in `split.php` |
| `tests/integration-split.php` | Split, Undo and the merge interplay against a real site: `wp eval-file tests/integration-split.php` (makes and deletes its own products; use a test site) |

**Grouping.** Only published, visible simple products with a SKU are scanned. Products are grouped by SKU family (the SKU minus its last `-` segment and any short packaging suffix, so `SE1421-01 CL` gives `SE1421`), then split within a family by the longest run of leading name words two members share. The rest of each name becomes the variant label. The attribute (Color, Size, Flavor, Scent, Volume, else Option) is guessed from built-in word lists when most labels match. Results are cached in a transient for 30 minutes.

**Creating.** Builds the variable product from the first member (description, images, taxonomies, tax and shipping class) and one variation per member (SKU, prices, sale dates, stock, dimensions, image). WooCommerce's unique-SKU check is bypassed while saving, so each SKU then exists on both the variation and the hidden original. If any save fails, the partial product is deleted. Members are set to `hidden` catalog visibility, with their previous visibility and the parent ID stored in post meta.

**Splitting.** Makes one simple product per checked variation (SKU, prices, sale dates, stock, virtual/downloadable, dimensions, image), taking the variable product's descriptions, gallery, taxonomies, non-variation attributes, tax status and purchase note. Fields a variation leaves to its parent (weight, dimensions, tax class, shipping class, image) come from the parent. A variation that shares its parent's stock count gets no stock count of its own, so stock is not multiplied. Variations set to "Any" for an attribute can't be split. The variable product is set to `hidden`, with its previous visibility and the new IDs in post meta (`_varconv_split`), and each new product records its source (`_varconv_split_from`); split products are left out of the merge scan. A product made by merging can't be split; it offers Undo instead.

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
