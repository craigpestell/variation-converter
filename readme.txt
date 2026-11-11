=== Variation Converter – Simple to Variable Products for WooCommerce ===
Contributors: craigpestell
Tags: simple to variable, variable products, product variations, merge products, combine products
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert simple products to variable products in WooCommerce, in bulk: merge one-per-color or one-per-size products into variations. Undo anytime.

== Description ==

Many WooCommerce stores end up with one simple product per color or size: "Classic Tee Black", "Classic Tee Blue", "Classic Tee Red". Shoppers scroll past three listings where there should be one, and you keep three products in sync by hand.

Variation Converter converts simple to variable products in WooCommerce. It merges them into one product, "Classic Tee", with a Black, Blue and Red variation, and each variation keeps its original's SKU, price, stock and image. The originals are hidden, not deleted, and Undo puts everything back.

It finds the products that belong together for you, so you can combine products into variations one at a time or bulk convert a whole catalog, such as one imported from a supplier feed or CSV with a separate product for every size and color.

It works the other way too: convert a variable product to simple products, with the same preview and the same Undo.


= How it works =

1. Go to **Products > Variation Converter** and search for a product name or SKU, such as "Classic Tee".
2. Tick the groups the plugin suggests, and any matching products listed under **Products not in a group**.
3. Click **Combine into one product**, check the product name, attribute and labels, and click **Create variable product**.

Nothing changes in your store until that last click. The **History** tab can undo every conversion.

= Features =

**Finding the products**

* **Search first.** Type a name or SKU to see every suggested group and every loose product that matches.
* **Groups suggested for you.** The plugin scans your catalog and groups products whose SKUs share a family (SE1421-01, SE1421-03) and whose names start with the same words.
* **Flags what needs a look:** different prices, different categories, duplicate labels, or labels long enough to suggest the products aren't really variants.
* **Thousands of products?** Work down the list of suggestions, or tick many groups and click **Create a product for each group**. Large batches run in the background, so you can leave the page.

**Combining**

* **One or two attributes.** Combine products into one attribute, such as Color, or several groups into two, such as Flavor and Size, with a grid of the pairs that exist.
* **You review everything first.** Rename the product, change the attribute, edit labels, reorder variants and leave products out.
* **Keeps your data.** Each variation takes its original's SKU, GTIN, prices, sale dates, stock settings, weight, dimensions, image and description. The variable product takes the first product's description, categories, tags and images, plus every product's image in its gallery. It has no SKU of its own.
* **Sizes in size order.** 250 ml before 1 L, 90 cm before 1.2 m, S before XL.
* **Old links keep working.** A combined product's old page sends shoppers and search engines (301) to the variable product with that option already chosen.
* **Private and draft products too.** They show with their status. A variable product takes the status of its most visible product, and private or draft products become disabled variations, so nothing unpublished goes on sale.

**Safe to try**

* **Nothing is deleted.** The products you convert from are hidden from your shop and search. Their orders, reviews and links stay as they were.
* **One-click Undo,** one conversion at a time or many at once from **History**.
* **All or nothing.** If WooCommerce fails to save any part of a variable product, the partial product is removed and your catalog is left as it was.

**Splitting**

* **Variable to simple.** Turn each variation into a simple product with its SKU, prices, stock and image, plus the variable product's descriptions, categories and tags. Undo brings the variable product back.

**Lightweight**

* No settings page, no scripts or styles on your storefront, no external services and no tracking.

= Combining groups with two attributes =

Say the scan found "Syrup Vanilla" (250ml, 750ml) and "Syrup Caramel" (250ml, 750ml, 1L) as two groups. Search for "Syrup", tick both groups and click **Combine into one product**. You get one variable product, "Syrup", with two attributes, Flavor and Size. A grid shows which pairs exist; a pair no product has, such as Vanilla 1L, isn't sold. The swap button sets which attribute comes first on the product page, and either attribute can be removed.

= Splitting a variable product =

Open the **Split** tab, or click **Split into simple products** under any variable product in **Products > All Products**. Each variation gets a suggested name ("Classic Tee Black") that you can edit. Click **Split** and each checked variation becomes a simple product. The variable product is hidden, not deleted, and Undo in **History** brings it back.

A variable product the plugin made offers Undo instead of Split, so you get your original products back rather than new copies.

= Who it's for =

* Stores imported from a supplier feed, a CSV or another platform such as Shopify that made a separate product for every variant.
* Shops that grew one product at a time and now want a tidier catalog.
* Developers cleaning up a client's catalog who want a reversible tool instead of a spreadsheet.

= For developers =

* WP-CLI: `wp varconv combine` creates the suggested groups (with `--dry-run`), and `wp varconv undo` reverses a conversion. Run `wp help varconv` for the options.
* Filters: `varconv_scan_products`, `varconv_brand_names`, `varconv_collections`, `varconv_variation_meta`, `varconv_redirect_url` and `varconv_quick_edit_variant_stock` (turn the Quick Edit variant stock box on for other variable products, or off).
* Source, issues and pull requests on [GitHub](https://github.com/craigpestell/variation-converter).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. In your WordPress admin, go to **Plugins > Add New**, search for "Variation Converter", then install and activate it.
3. Go to **Products > Variation Converter**. Search for a product on the **Combine** tab, or open the **Split** tab.

You need a role that can manage WooCommerce, such as Shop Manager or Administrator.

Undo covers every conversion, but take a database backup before converting many products at once. It's good practice.

== Frequently Asked Questions ==

= How do I convert simple products to a variable product in WooCommerce? =

Install this plugin, go to **Products > Variation Converter**, search for the product name, tick the simple products that belong together and click **Combine into one product**. Check the attribute (such as Color or Size) and the label of each variation, then click **Create variable product**. Each simple product becomes a variation of the new variable product.

= How do I change a simple product to a variable product without losing its SKU, price or stock? =

The usual WooCommerce way is to change the product type to Variable and type every variation in by hand. This plugin does it from the products you already have: each simple product becomes one variation and keeps its SKU, price, stock and image. Undo in the **History** tab puts the simple products back.

= Can I bulk convert simple products to variable products? =

Yes. The plugin scans your catalog and suggests every group of products that look like variants of one product. Tick as many groups as you like and click **Create a product for each group**. Large batches run in the background. With WP-CLI, `wp varconv combine --all` does the same.

= Will this delete my products? =

No. The products you convert from are hidden from your shop and search results, not deleted, and Undo shows them again. Deleting the plugin doesn't change your products either.

Once you're sure you'll keep a combined product, you can choose **Trash originals** on its History row to move the hidden originals to the Trash. Their reviews move to the variable product and their old links keep redirecting. Undo restores them while they're in the Trash; after the Trash is emptied, that combine can no longer be undone.

= What happens to existing orders, reviews and links? =

Past orders still point to the original products. An original's old URL redirects (301) to its variation's own link: the variable product's URL with that variation's options added, such as `?attribute_color=Black`. WooCommerce uses those to preselect the options on the product page, so shoppers usually land on the right variant, though some themes ignore them. Reviews stay on the original products and aren't copied, until you use **Trash originals**, which moves them to the variable product.

If you later make every variation of a variable product simple, the variable product moves to the Trash and its old link, and each variant link, redirects to the new simple products.

= What if I undo after customers have ordered the variable product? =

Undo deletes the variable product and its variations. Orders keep their line items and totals, but those lines no longer link to a product. If the variable product has already sold, it's usually better to keep it. The same goes for undoing a split.

= How does it decide which products belong together? =

Two things must match: the SKUs share a family (SE1421-01 CL and SE1421-03 CL, or D1701-12 and D1701-14), and the product names start with the same words. Whatever follows the shared words becomes the variant label, such as "Black". You review every group before anything is created.

= It missed a product. How do I add it? =

Search for it on the **Combine** tab. It shows under **Products not in a group**, and you can tick it along with the group it belongs to.

If you already combined the group, tick the product and click **Add to a combined product**, then choose the variable product and its labels. In **Products > All Products** the bulk action of the same name works for any simple product, even one without a SKU. Undo in History gives it back with the others.

= It grouped products that aren't variants. =

Mark the group **Skip, not variants** and it stops appearing. Skipped groups can be restored from the **Skipped** view.

= My products don't have SKUs. Will it work? =

Not yet. The plugin only works with simple products that have a SKU. Matching by name alone is being considered for a future version.

= Which products does it look at? =

Simple products with a SKU that are published, private, pending or draft, and not hidden from the catalog. Scheduled and trashed products are left out.

= Can a product have two attributes, like flavor and size? =

Yes. Tick two or more groups and click **Combine into one product** to get a variable product with two attributes. See "Combining groups with two attributes" above. More than two attributes isn't supported.

= It guessed the wrong attribute. Can I change it? =

Yes. The attribute name and every label are only suggestions. Edit them before you click **Create variable product**.

= Why are there two products with the same SKU after a conversion? =

The originals are kept, hidden, so Undo can restore everything exactly. Each SKU is on the hidden original and on its variation. Once you're sure you'll keep the variable product, you can delete the hidden originals yourself.

= How do I change a variation's stock in Quick Edit? =

Each variation keeps its own stock, taken from its original. WooCommerce's own Quick Edit stock fields set a count on the variable product, which none of its variations sell from, so on variable products the plugin made, Quick Edit shows a **Variant stock** box instead, with one number per variation. Change a number and click **Update**. The **Stock** column shows the variations' total; hover over it to see each one's count. You can also edit stock on the product's **Variations** tab.

= I changed some products and the list didn't update. =

Scan results are kept for 30 minutes. Click **Scan again** to refresh them.

= Can I convert a variable product back to simple products? =

Yes. Use the **Split** tab, or the **Split into simple products** link under a variable product in **Products > All Products**. Each variation becomes a simple product with its own SKU, price, stock and image.

= Can I make just one variation a simple product? =

Yes. On the product's **Variations** tab, click **Make simple product** on that variation, or check it on the **Split** tab and click **Make checked simple, keep the rest**. You can give the new product its own name and categories. The rest of the variable product stays as it is. The variation is disabled, not deleted, and its old link sends shoppers to the new product. If Combine made the variation, its original simple product comes back instead of a new one. When you make the last variation for sale simple, the variable product has nothing left to sell, so it moves to the Trash and its old link sends shoppers to the new products. Undo is in **History**, and it restores the variable product too.

= Why can't I split some variations? =

A variation set to "Any" for an attribute (for example "Any Size") isn't one specific product, so there's nothing to turn into a simple product. Give it a specific value first.

= Does it work with my search plugin? =

It reindexes changed products in Relevanssi automatically. Other search plugins pick up the changes on their next reindex.

= Does it send data anywhere? =

No. It makes no remote calls and has no tracking. Everything happens in your own WordPress database.

= Where can I get help or report a bug? =

Ask questions in the support forum here on wordpress.org. Bug reports and pull requests are welcome on [GitHub](https://github.com/craigpestell/variation-converter).

== Screenshots ==

1. Six coffee products combined into one variable product: the attributes renamed to Grind and Weight, a label shortened, the order changed, then picking Espresso in 1kg from the dropdowns in the shop, which switches the product picture.
2. Search on the Combine tab: the suggested groups that match, products not in a group, and Combine into one product.
3. Reviewing a variable product with two attributes, Flavor and Volume, with the grid of pairs that exist.
4. Splitting a variable product into simple products, with a suggested name for each.
5. The History tab, where every conversion can be undone.

== Changelog ==

= 1.7.1 =
* The plugin is now called "Variation Converter – Simple to Variable Products for WooCommerce", which says what it does. Its slug and settings are unchanged. New FAQ: changing a simple product to a variable product without losing its SKU, price or stock.

= 1.7.0 =
* Add to a combined product: add simple products the scan didn't group, including ones with another SKU or no SKU at all, as new variations of a variable product you already combined. Use the button on the Combine tab or the bulk action in Products > All Products, choose the variable product, and check the labels. The added products are hidden, their old pages redirect to their variations, and Undo in History gives them back with the others.
* Developers: action `varconv_added_to`.

= 1.6.0 =
* History: Undo and Move originals to Trash on more than 10 conversions now run on the server in the background, like a bulk create, so a large selection no longer times out. The notice shows progress on every tab with a Stop button, the job keeps going if you leave the page, and the page shows the summary when it is done.

= 1.5.1 =
* History: after checking every row on a page, "Select all N conversions" applies Undo or Move originals to Trash to the whole list, on every page, for the current view and search. Move originals to Trash skips rows with nothing to move instead of reporting them as errors.

= 1.5.0 =
* Trash originals: once you are sure you will keep a combined product, move its hidden original simple products to the Trash from its History row, or check several rows and use the Bulk action. Their reviews move to the variable product, and their old links keep redirecting (301) to the variation, even after the Trash is emptied. Undo and Make simple restore them while they are in the Trash.
* Undo refuses a combine whose originals were deleted for good, because deleting the variations would lose those products.
* An original restored from the Trash by hand keeps the status it had, instead of coming back as a draft.
* Developers: action `varconv_originals_trashed`.

= 1.4.4 =
* The demo screenshot now picks Espresso in 1kg in the shop, so you can see the product picture change, and shows the gallery thumbnails.

= 1.4.3 =
* The first screenshot on the plugin page is now an animated demo: six coffee products combined into one variable product with Grind and Weight attributes.

= 1.4.2 =
* The Live Preview demo store gives each of its 32 products its own image, so combined products show a different picture for each variation.
* The Combine tab lists groups A to Z by name. Click the Products column to sort by group size, largest first.

= 1.4.1 =
* FAQ and docs: the plugin redirects an old product URL to its variation's link, and WooCommerce preselects the options from that link (some themes don't). The FAQ also covers old links when every variation is made simple.

= 1.4.0 =
* Make simple works on every variation: when no variation is left for sale, the variable product moves to the Trash and its old link, and each variant link, sends shoppers to the new simple products. Undo in History brings it back. This also works on products made by Combine.

= 1.3.2 =
* The Live Preview demo store has 32 products, including tees in color and size and coffee in weight and grind, so you can try combining products with two attributes.

= 1.3.1 =
* The plugin page on wordpress.org gets a Live Preview: a demo store in WordPress Playground with 20 simple products ready to combine.

= 1.3.0 =
* Make simple: turn chosen variations of any variable product, including combined ones, into simple products while the rest stays variable. Use the **Make simple product** link on a variation in the product's Variations panel, or check variations on the Split tab. You choose the new product's name and categories.
* A variation Combine made brings back its original simple product, with the variation's current price and stock. Any other variation gets a new simple product. The variation is disabled, not deleted, and its old variant URL redirects to the simple product. Undo is in History.
* WP-CLI: `wp varconv make-simple <variation-id>...`; `wp varconv undo` also undoes these.
* Developers: actions `varconv_made_simple` and `varconv_made_simple_undone`, and filter `varconv_made_simple_redirect_url`.

= 1.2.0 =
* Quick Edit on a variable product the plugin made shows a Variant stock box to set each variation's stock. The Stock column shows the variations' total instead of an unused count on the variable product, and saving clears that unused count.
* Developers: the `varconv_quick_edit_variant_stock` filter turns the box on for other variable products, or off.

= 1.1.0 =
* Fix: a variation made from a product that has a price but no regular price (common on imported products) now gets that price. Before, it had no price, so it couldn't be bought, and a variable product with only such variations showed "Read more" in the shop. A $0 price is kept as a price.
* Combine warns "No price" about a group when one of its products has no price at all, since its variation couldn't be bought. The warnings filter and `wp varconv combine --skip=noprice` can leave such groups out.
* Split and Undo also fall back to the active price when a product has no regular price.

= 1.0.1 =
* A combined product's old page now redirects (301) to its variation's own URL, with every option chosen. If that variation is disabled or has an "Any" option, it redirects to the variable product.

= 1.0.0 =
* First stable release. The code is the same as 0.17.1, and this is the first wordpress.org release since 0.14.0, so it includes everything from 0.15.0 to 0.17.1 below.
* Rewrote the plugin description and FAQ, and moved changelog entries before 0.12.0 to changelog.txt.
* New listing screenshots.

= 0.17.1 =
* Wording: the product made by combining is called a variable product throughout, as WooCommerce calls it.

= 0.17.0 =
* Private, pending and draft products show on Combine, Split and History, with their status. A combined product is as visible as its most visible member, and members less visible than that become disabled variations. Split products keep the variable product's status, so nothing private or unfinished goes on sale.

= 0.16.0 =
* Combine, Split and History share one list-table toolbar. History has Bulk actions > Undo and an Undo row action, Split has Can be split and Combined here views and row actions, and Combine's bottom bar repeats the Combine and Create buttons.

= 0.15.0 =
* The Attribute spelling setting, its Rename button and `wp varconv spelling` are gone. Attributes keep the names you type.

= 0.14.0 =
* When combining groups into one product with two attributes, either attribute can be removed, leaving a product with the other alone. Create waits until every checked product has its own label.
* Variants that start with a number go in number order in every group, including ones you combine from search: decimal commas ("1,5 L"), fractions ("1/2 in") and mixed units ("500 ml" before "1 L", "90 cm" before "1.2 m", "250 g" before "1 kg"). Thanks to Joan Llorca for the idea.
* History has checkboxes and an Undo checked button, to undo many conversions at once. One that can't be undone is reported and the rest carry on. Thanks to Joan Llorca for the idea.

= 0.13.0 =
* Each variation also takes its simple product's description, GTIN (UPC, EAN, ISBN), low stock threshold, virtual and downloadable settings, tax and shipping class where they differ, and its public custom fields. Split copies the GTIN, low stock threshold, download limits and public fields back.
* Fix: after combining or splitting, a variation and its hidden original can be edited and saved again. WooCommerce refused them as duplicates because they share a SKU and GTIN; a duplicate on any other product is still refused. Thanks to Joan Llorca for finding this.

= 0.12.3 =
* After a whole group is combined, the Combine tab reloads with the notice above the list and the group gone from it.

= 0.12.2 =
* The attribute swap button now swaps the two attribute names and each product's labels in place. The fields are labeled First attribute and Second attribute, and the first one comes first on the product page.

= 0.12.1 =
* The attribute swap button also swaps the two attribute columns in the card's product list, so they read in the same order as the fields.

= 0.12.0 =
* Removed Set one name (added in 0.9.0). Combine into one product already sets the new product's name and each variation's label, so renaming the simple products first is not needed.

Older versions are listed in changelog.txt, in the plugin's folder.

== Upgrade Notice ==

= 1.0.0 =
First stable release. Since 0.14.0: one toolbar across Combine, Split and History with bulk Undo; private and draft products can be converted without being published; the Attribute spelling setting is removed, so attributes keep the names you type.

= 0.12.0 =
Set one name is removed; Combine into one product names the product and its variations. New variable products get no SKU of their own, and a swap button sets which of two attributes comes first.

= 0.7.0 =
The Combine tab starts with a search. Combine into one product and Create a product for each group are now buttons above the list, not Bulk actions.

= 0.3.0 =
The WP-CLI command is renamed to `wp varconv`. Update any scripts that call `wp variation-converter`.
