// Example browse script: wp-harness browse harness/examples/walkthrough.mjs [--out DIR]
// Gets a logged-in Playwright page. go(path) opens a wp-admin path (or "/front-end/path" or a full URL),
// shot(name[, locator]) saves a screenshot, and problems collects JS errors, failed requests and PHP notices.
export default async function ({ page, go, shot, problems }) {
	await go('edit.php?post_type=product');
	console.log('products listed:', await page.locator('#the-list tr').count());
	await shot('products');

	await go('/shop/');
	await shot('shop');

	await go('/product/canvas-apron/');
	await page.selectOption('select#color', 'Charcoal');
	await page.locator('.woocommerce-variation-price').waitFor();
	await shot('apron-charcoal');

	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
