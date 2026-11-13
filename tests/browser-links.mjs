// The ways into the app from WordPress's own screens, on the harness demo catalog:
//   harness/wp-harness reset && harness/wp-harness browse tests/browser-links.mjs --out shots
// Checks Products > All Products "Combine into variable product" for checked products, the row action "Split into
// simple products", and "Make simple product" on a variation of the product edit screen, which goes back there.
import { watch, check, until, text, settled } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	// Two products from the Products list.
	await go('edit.php?post_type=product&s=Northwind+Tote');
	await page.locator('#the-list tr input[name="post[]"]').nth(0).check();
	await page.locator('#the-list tr input[name="post[]"]').nth(1).check();
	await page.locator('#bulk-action-selector-top').selectOption('varconv_combine');
	await page.locator('#doaction').click();
	await page.locator('.varconv-card').waitFor();
	await settled(page);
	check(/combine=/.test(page.url()), 'the bulk action opens the Combine card for the checked products');
	check(await page.locator('.varconv-card .varconv-pick-table tbody tr').count() === 2, 'the card lists the two products');
	await shot('links-combine');

	// The row action on a variable product.
	await go('edit.php?post_type=product&s=Canvas+Apron');
	await page.locator('#the-list tr').first().hover();
	await page.locator('#the-list .row-actions a', { hasText: 'Split into simple products' }).first().click();
	await page.locator('.varconv-table__panel .varconv-card').waitFor();
	check(/tab=split/.test(page.url()) && /product=/.test(page.url()), 'the row action opens the product on the Split tab');

	// A variation on the product edit screen.
	const id = new URL(page.url()).searchParams.get('product');
	await go(`post.php?post=${id}&action=edit`);
	await page.locator('.variations_tab a, a[href="#variable_product_options"]').first().click();
	const link = page.locator('.woocommerce_variation a', { hasText: 'Make simple product' }).first();
	await link.waitFor();
	await link.click();
	await page.getByRole('button', { name: 'Make simple product' }).waitFor();
	check(/make_simple=/.test(page.url()) && /back=edit/.test(page.url()), 'the variation link opens the Make simple screen');
	await settled(page);
	await page.getByRole('button', { name: 'Make simple product' }).click();
	await page.waitForURL(/post\.php/);
	await until(page, async () => /simple product/.test(await text(page.locator('#wpbody-content'))), 'it goes back to the product edit screen with its notice');
	if (problems.length) throw new Error(problems.join('\n'));
}
