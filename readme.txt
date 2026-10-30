=== Variation Converter for WooCommerce ===
Contributors: craigpestell
Tags: woocommerce, variable products, merge products, simple to variable, variable to simple
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search for a product, tick its variants and combine them into one variable product. Or split a variable product into simple ones. Undo anytime.

== Description ==

Lots of WooCommerce stores start out with one simple product per color or size: "Classic Tee Black", "Classic Tee Blue", "Classic Tee Red". Shoppers see three listings where there should be one, and you have three products to keep in sync.

Fixing that by hand means building a variable product, re-entering every SKU, price, stock level and image, and then hiding the old products. Variation Converter does it for you.

Search for the product, for example "Classic Tee". Variation Converter shows the groups it has already found for that name, and any matching products it didn't group. Tick the ones that are one product and click **Combine into one product**. You check the new product before anything is created, then get one variable product with a variation for each original. The originals are hidden, not deleted, and Undo puts everything back the way it was.

Groups that are colors or flavors of the same product combine too. Tick "Syrup Vanilla" (250ml, 750ml) and "Syrup Caramel" (250ml, 750ml, 1L) and you get one Syrup with two attributes, Flavor and Size.

Got thousands of products? The plugin scans your whole catalog and suggests every group up front, so you can also work down the list, or tick many groups and click **Create a product for each group**.

It works the other way too. If a variable product would sell better as separate listings, or you're moving to a setup that needs one product per variant, Variation Converter splits it into simple products the same way: preview, one click, and Undo.

= How it works =

1. Go to **Products > Variation Converter**. On the **Combine** tab, type a product name or SKU in **Find the products to combine** and click **Search**.
2. The results show the suggested groups that match and, below them, **Products not in a group**: matching products the scan didn't group.
3. Tick the groups and products that are one product, and click **Combine into one product**. Products, or one group with extra products, become a product with one attribute, such as Color. Two or more groups become a product with two attributes, such as Flavor and Size.
4. Check the product name, the attribute and the label of each variant ("Black", "Blue", "Red"). Untick anything that doesn't belong, then click **Create variable product**.
5. Changed your mind? The **History** tab has an **Undo** button for every product the plugin made.

Without a search, the Combine tab lists every suggested group, with **Ready** and **Needs review** views. Open one to check it, tick several and click **Create a product for each group**, or mark a group **Skip, not variants** so it stops appearing.

= Features =

* **Both directions.** Merge simple products into a variable product, or split a variable product into simple products.
* **Search first.** Type a product name and see every group and stray product that matches, ready to tick and combine.
* **Suggests the groups for you.** Products are grouped when their SKUs share a family (SE1421-01, SE1421-03) and their names share a beginning, so most of the picking is already done.
* **One or two attributes.** Combine products into one attribute (Color), or whole groups into two (Flavor and Size), with a grid of the pairs that exist.
* **Review before anything changes.** Nothing is created until you approve a group. You can rename it, change the attribute, fix labels and leave products out.
* **Flags the things worth a second look:** different prices, different categories, duplicate labels, or labels long enough to suggest these aren't really variants.
* **Keeps your data.** Each variation takes its original's SKU, regular and sale price, sale dates, stock settings, weight, dimensions and image. The variable product takes the first member's description, images, categories, tags, tax and shipping class, plus every member's image in its gallery. It has no SKU of its own; the variations keep theirs.
* **Non-destructive.** The products you convert from are hidden from your shop and search, never deleted. Their orders, reviews and links stay as they were.
* **One-click Undo.** Undo removes what the plugin made and brings the originals back with the visibility they had before.
* **All or nothing.** If WooCommerce fails to save any part of a new product, the partial product is removed and your catalog is untouched.
* **Lightweight.** No settings page, no front-end code, no external services, and nothing stored beyond a few product meta fields.

= Splitting a variable product =

Going the other way works too. On the **Split** tab, or from the **Split into simple products** link under any variable product in Products > All Products, pick a variable product. You get one row per variation with a suggested name ("Classic Tee Black"), which you can edit. Click **Split** and each checked variation becomes a simple product with its SKU, prices, stock, weight, dimensions and image, plus the variable product's descriptions, categories, tags and other attributes. The variable product is hidden, not deleted, and Undo in **History** brings it back and removes the simple products.

A variable product the plugin made by merging offers Undo instead of a split, so you get your original products back rather than new copies.

= Who it's for =

* Stores imported from a supplier feed or another platform that created one product per variant.
* Shops that grew one product at a time and now want a tidier catalog.
* Developers cleaning up a client's catalog who want a safe, reversible tool instead of a spreadsheet.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. In your WordPress admin, go to **Plugins > Add New**, search for "Variation Converter", then install and activate it. Or upload the `variation-converter-for-woocommerce` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.
3. Go to **Products > Variation Converter** and search for a product on the **Combine** tab, or open the **Split** tab to split a variable product.

You need a user role that can manage WooCommerce products (Shop Manager or Administrator).

We recommend taking a database backup before converting many products at once. Undo is there, but a backup is always good practice.

== Frequently Asked Questions ==

= Will this delete my products? =

No. The original simple products are set to hidden catalog visibility so they no longer show in your shop or search results. They are not deleted, and Undo makes them visible again.

= What happens to existing orders, reviews and links? =

They stay with the original simple products. Past orders still point to them, and old product URLs still open because hidden products can still be reached by their link. Reviews are not copied to the new variable product.

= How does it decide which products belong together? =

Two things must match: the SKUs share a family (for example SE1421-01 CL and SE1421-03 CL, or D1701-12 and D1701-14), and the product names start with the same words. Whatever follows the shared words becomes the variant label. You review every group before anything is created.

If it missed a product, search for it. It shows up under **Products not in a group**, and you can tick it along with the group it belongs to.

= My products don't have SKUs. Will it work? =

Not yet. Grouping relies on SKUs, so products without one are never proposed. Only published, visible simple products are scanned. Name-only matching is being considered for a future version.

= Why are there two products with the same SKU after a conversion? =

The originals are kept, hidden, so Undo can restore everything exactly. Each SKU exists on the hidden original and on the new product. Once you're sure you'll keep the new product, you can delete the hidden one yourself.

= I just changed some products and the list didn't update. =

Scan results are cached for 30 minutes. Click **Scan again** to refresh them.

= Can a product have more than one attribute, like color and size? =

Yes, two. Each group the scan finds gets one attribute, say Size. When each group is one flavor or color of the same product (Syrup Vanilla in 250ml and 750ml, Syrup Caramel in 250ml, 750ml and 1L), search for "Syrup", tick the groups and any matching products not in a group (such as Syrup Hazelnut 750ml), and click **Combine into one product**. You get one variable product with two attributes, Flavor and Size, and a variation for each product. A grid shows which pairs exist; pairs no product has, such as Vanilla 1L, are not sold.

= It guessed the wrong attribute. Can I change it? =

Yes. The attribute is only a suggestion. Edit it in the group before you click Create.

= What if I undo after customers have ordered the new variable product? =

Undo deletes the variable product and its variations. Orders keep their line items and totals, but those lines will no longer link to a product. If the new product has already sold, it's usually better to keep it.

= My store has both Color and Colour. Can it fix that? =

Yes. On the History tab, under Attribute spelling, choose how your store spells them (it starts with British and Canadian spelling for English sites other than US English). New products use that spelling. If some products already use the other one, Rename changes their attribute name and their variations, so shoppers see one spelling and the variations still match; orders keep the name they were placed with. It renames custom attributes made on each product; two global attributes in Products > Attributes are only reported, since WooCommerce manages those. Developers can add pairs with the `varconv_spelling_pairs` filter.

= Does it work with my search plugin? =

It reindexes changed products in Relevanssi automatically. Other search plugins will pick up the changes on their next reindex.

= Does it convert variable products into simple products? =

Yes. Use the Split tab, or the "Split into simple products" link under a variable product in your product list. Undo deletes the new simple products and shows the variable product again; as with merging, any orders already placed for those simple products keep their line items but lose the product link.

= Why can't I split some variations? =

A variation set to "Any" for an attribute (for example "Any Size") isn't one specific product, so there's nothing to turn into a simple product. Give it a specific value first.

= Where can I report a bug or contribute? =

Questions about using the plugin go in the support forum here on wordpress.org. Bug reports and pull requests are welcome on [GitHub](https://github.com/craigpestell/variation-converter).

== Screenshots ==

1. Searching the Combine tab: the suggested groups that match, products not in a group, and Combine into one product.
2. Two groups and a product combined into one product with two attributes, with the grid of pairs that exist.
3. Splitting a variable product into simple products.
4. The History tab with one-click Undo.

== Changelog ==

= 0.13.0 =
* Each variation also takes its simple product's description, GTIN (UPC, EAN, ISBN), low stock threshold, virtual and downloadable settings, tax and shipping class where they differ, and its public custom fields. Split copies the GTIN, low stock threshold, download limits and public fields back.
* Fix: after combining or splitting, a variation and its hidden original can be edited and saved again. WooCommerce refused them as duplicates because they share a SKU and GTIN; a duplicate on any other product is still refused. Thanks to Joan Llorca for finding this.
* Variants that start with a number go in number order in every group, including ones you combine from search: decimal commas ("1,5 L"), fractions ("1/2 in") and mixed units ("500 ml" before "1 L", "90 cm" before "1.2 m", "250 g" before "1 kg"). Thanks to Joan Llorca for the idea.

= 0.12.3 =
* After a whole group is combined, the Combine tab reloads with the notice above the list and the group gone from it.

= 0.12.2 =
* The attribute swap button now swaps the two attribute names and each product's labels in place. The fields are labeled First attribute and Second attribute, and the first one comes first on the product page.

= 0.12.1 =
* The attribute swap button also swaps the two attribute columns in the card's product list, so they read in the same order as the fields.

= 0.12.0 =
* Removed Set one name (added in 0.9.0). Combine into one product already sets the new product's name and each variation's label, so renaming the simple products first is not needed.

= 0.11.0 =
* Combining groups into one product with two attributes: a swap button between the two attribute fields picks which one the product page shows first.

= 0.10.0 =
* Variable products made by the plugin no longer get a SKU of their own; each variation keeps its original's SKU. The Parent SKU field is gone from the combine card, and the list's SKU column is now called SKU family.

= 0.9.0 =
* Set one name: tick products on the Combine tab (or choose "Set one name for variants" in Products > All Products) and give them one shared name, each followed by its color, size or other variant, so they are listed together and can be combined into one variable product. The new names are previewed as you type, URLs stay the same, and Undo rename puts the old names back.

= 0.8.0 =
* Attribute names in one spelling: choose British and Canadian (Colour, Flavour) or American (Color, Flavor) on the History tab, and products made here use it whatever was typed. Rename puts products already spelled the other way into that spelling, with their variations. Also `wp varconv spelling`.

= 0.7.0 =
* The Combine tab puts search first, and search also finds products that are in no group, so you can combine them by hand.
* New buttons above the list: Combine into one product, and Create a product for each group. They replace those two Bulk actions entries.

= 0.6.0 =
* New bulk action, Combine groups into one product: several groups become one variable product with two attributes (for example Flavor and Size), with a grid of the pairs that exist.

= 0.5.1 =
* Fix: Stop on a bulk create now stops it, after the group being created.

= 0.5.0 =
* The Combine list's warnings filter lets you pick several warnings, and shows only the groups with exactly those warnings.

= 0.4.0 =
* Bulk create runs on the server, through WooCommerce's Action Scheduler, so it finishes even if you leave the page. Its progress shows on every tab, with a Stop button.
* The plugin's version shows beside the page title.

= 0.3.1 =
* Combine keeps every product's gallery images on the new variable product, not just the first product's.

= 0.3.0 =
* The WP-CLI command is now `wp varconv` (was `wp variation-converter`). The admin page address is now `page=varconv`; old links redirect.
* The Combine list explains its Ready, Needs review and Skipped views, with tips on hover.
* Clearer example product names in the description and empty list.

= 0.2.4 =
* Undo brings back current prices and stock: sales made while products were combined or split now carry over to the restored products.

= 0.2.3 =
* The plugin's slug and text domain are now variation-converter-for-woocommerce, to match its wordpress.org address.

= 0.2.2 =
* Split tab: sort variable products by number of variations.

= 0.2.1 =
* Hover over or focus a product thumbnail in a Combine or Split card to see a larger image.

= 0.2.0 =
* Combine list: a sortable, filterable table with Ready, Needs review and Skipped views, an inline card per group, and Select by SKU.
* Combine from the Products list: select products and choose "Combine into variable product".
* Create many groups at once, from the list or with WP-CLI (`wp varconv combine` and `undo`).
* Split and History tabs are list tables too.
* Better grouping: sizes in size order, "12 oz" and "12oz" treated alike, bracketed labels unwrapped, clearer notes about duplicates, prices and categories.
* A free parent SKU is suggested when the group's SKU is taken.
* Old product URLs now 301 to the variable product with the option chosen (filter: `varconv_redirect_url`). Undo stops it.
* Fixes: cards on phones, the next group after a partial create, works without mbstring.

= 0.1.0 =
* First public version: detect groups of simple products, review them, merge each into a variable product, split variable products into simple products, and undo either.

== Upgrade Notice ==

= 0.12.0 =
Set one name is removed; Combine into one product names the product and its variations. New variable products get no SKU of their own, and a swap button sets which of two attributes comes first.

= 0.7.0 =
The Combine tab starts with a search. Combine into one product and Create a product for each group are now buttons above the list, not Bulk actions.

= 0.6.0 =
Combine several groups into one variable product with two attributes, such as Flavor and Size.

= 0.4.0 =
Bulk create now keeps running in the background when you leave the page.

= 0.3.0 =
The WP-CLI command is renamed to `wp varconv`. Update any scripts that call `wp variation-converter`.

= 0.2.0 =
Bulk combine, a Products-list action, WP-CLI, and redirects from old product URLs.

= 0.1.0 =
First public version.
