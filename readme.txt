=== Variation Converter for WooCommerce ===
Contributors: craigpestell
Tags: woocommerce, variable products, variations, merge products, simple to variable
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Merge near-duplicate simple products into variable products. Finds the groups for you, previews each one, and undoes in one click.

== Description ==

Lots of WooCommerce stores start out with one simple product per colour or size: "Tri-Rings Black", "Tri-Rings Blue", "Tri-Rings Red". Shoppers see three listings where there should be one, and you have three products to keep in sync.

Fixing that by hand means building a variable product, re-entering every SKU, price, stock level and image, and then hiding the old products. Variation Converter does it for you.

It scans your catalog, finds the simple products that are really one product in several variants, and shows you each group. You check it, adjust anything you like, and click once. You get one variable product with a variation for each original. The originals are hidden, not deleted, and Undo puts everything back the way it was.

= How it works =

1. Go to **Products > Variation Converter**. The plugin scans your published simple products.
2. Each proposed group shows its members, the variant label it read from each name ("Black", "Blue", "Red"), a guessed attribute (Colour, Size, Flavour, Scent or Volume) and a suggested parent SKU.
3. Untick any product that doesn't belong, and edit the product name, attribute or labels.
4. Click **Create variable product**. Or click **Not a collection** to dismiss the group so it stops appearing.
5. Changed your mind? The **Created** list has an **Undo** button for every product the plugin made.

= Features =

* **Finds the groups for you.** Products are grouped when their SKUs share a family (SE1421-01, SE1421-03) and their names share a beginning. You don't have to pick products one by one.
* **Review before anything changes.** Nothing is created until you approve a group. You can rename it, change the attribute, fix labels and leave products out.
* **Flags the things worth a second look:** different prices, different categories, duplicate labels, or labels long enough to suggest these aren't really variants.
* **Keeps your data.** Each variation takes its original's SKU, regular and sale price, sale dates, stock settings, weight, dimensions and image. The variable product takes the first member's description, images, categories, tags, tax and shipping class, plus every member's image in its gallery.
* **Non-destructive.** The original simple products are hidden from your shop and search, never deleted. Their orders, reviews and links stay as they were.
* **One-click Undo.** Undo removes the variable product and brings the originals back with the visibility they had before.
* **All or nothing.** If WooCommerce fails to save any part of a new product, the partial product is removed and your catalog is untouched.
* **Lightweight.** No settings page, no front-end code, no external services, and nothing stored beyond a few product meta fields.

= Coming next =

Splitting in the other direction: turning a variable product back into separate simple products. It's in development and will arrive in a future version.

= Who it's for =

* Stores imported from a supplier feed or another platform that created one product per variant.
* Shops that grew one product at a time and now want a tidier catalog.
* Developers cleaning up a client's catalog who want a safe, reversible tool instead of a spreadsheet.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. In your WordPress admin, go to **Plugins > Add New**, search for "Variation Converter", then install and activate it. Or upload the plugin folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.
3. Go to **Products > Variation Converter** to see the proposed groups.

You need a user role that can manage WooCommerce products (Shop Manager or Administrator).

We recommend taking a database backup before converting many products at once. Undo is there, but a backup is always good practice.

== Frequently Asked Questions ==

= Will this delete my products? =

No. The original simple products are set to hidden catalog visibility so they no longer show in your shop or search results. They are not deleted, and Undo makes them visible again.

= What happens to existing orders, reviews and links? =

They stay with the original simple products. Past orders still point to them, and old product URLs still open because hidden products can still be reached by their link. Reviews are not copied to the new variable product.

= How does it decide which products belong together? =

Two things must match: the SKUs share a family (for example SE1421-01 CL and SE1421-03 CL, or D1701-12 and D1701-14), and the product names start with the same words. Whatever follows the shared words becomes the variant label. You review every group before anything is created.

= My products don't have SKUs. Will it work? =

Not yet. Grouping relies on SKUs, so products without one are never proposed. Only published, visible simple products are scanned. Name-only matching is being considered for a future version.

= Why does each SKU now appear twice? =

Each variation reuses its original's SKU, and the hidden original keeps it too, so Undo can restore everything exactly. If you're sure you'll keep the variable product, you can clear the SKUs on the hidden originals or delete them yourself.

= I just changed some products and the list didn't update. =

Scan results are cached for 30 minutes. Click **Scan again** to refresh them.

= Can a product have more than one attribute, like colour and size? =

Not in this version. Each variable product gets one attribute. You can change its name before creating, for example from "Colour" to "Finish".

= It guessed the wrong attribute. Can I change it? =

Yes. The attribute is only a suggestion. Edit it in the group before you click Create.

= What if I undo after customers have ordered the new variable product? =

Undo deletes the variable product and its variations. Orders keep their line items and totals, but those lines will no longer link to a product. If the new product has already sold, it's usually better to keep it.

= Does it work with my search plugin? =

It reindexes changed products in Relevanssi automatically. Other search plugins will pick up the changes on their next reindex.

= Does it convert variable products into simple products? =

Not yet. That's the next feature in development.

== Screenshots ==

1. Proposed groups, each with its members, labels, attribute and notes.
2. Editing a group before creating the variable product.
3. The Created list with one-click Undo.

== Changelog ==

= 0.1.0 =
* First public version: detect groups of simple products, review them, merge each into a variable product, and undo.

== Upgrade Notice ==

= 0.1.0 =
First public version.
