// wordpress.org listing screenshots 2-5 (1 is the demo GIF from clips/), matching the readme captions. Needs the syrup groups:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php
//   harness/wp-harness wp option update blogname 'Demo Store'
//   harness/wp-harness browse .wordpress-org/screenshots.mjs --out .wordpress-org
export default async function ({ page, go, shot }) {
	const tidy = () => page.addStyleTag({ content: '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account) { display: none !important; } #wpbody-content { padding-top: 0 !important; }' });
	const settled = () => page.waitForFunction(() => document.querySelector('#varconv-root .varconv-tabs') && !document.querySelector('#varconv-root .is-loading, #varconv-root .components-spinner, #varconv-root .is-busy'));
	const calm = async () => { await settled(); await tidy(); await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); }); await page.mouse.move(700, 16); await page.waitForTimeout(300); };
	await go('edit.php?post_type=product&page=varconv&s=Barista+Syrup');
	await page.locator('.varconv-groups .varconv-row-title').nth(1).waitFor();
	for (const box of await page.locator('.varconv-groups tbody .varconv-table__check input').all()) await box.check();
	await page.locator('.varconv-loose tbody tr', { hasText: 'Hazelnut' }).locator('input[type="checkbox"]').check();
	await calm();
	await shot('screenshot-2');
	await page.getByRole('button', { name: 'Combine into one product' }).first().click();
	const card = page.locator('.varconv-card');
	await card.waitFor();
	// The readme's example calls the syrups' attribute Flavor; the scan guesses Color.
	await card.locator('.varconv-fields__attribute input').first().fill('Flavor');
	await calm();
	await shot('screenshot-3', page.locator('.varconv-table').first());
	await card.getByRole('button', { name: 'Create variable product' }).click();
	await card.waitFor({ state: 'detached' });
	await go('edit.php?post_type=product&page=varconv&tab=split');
	await page.locator('.varconv-table .varconv-row-title', { hasText: 'Canvas Apron' }).click();
	await page.locator('.varconv-table__panel input[type="text"]').first().waitFor();
	await calm();
	await shot('screenshot-4');
	await page.getByRole('button', { name: /^Split into/ }).click();
	await page.locator('.varconv-notices .varconv-notice').first().waitFor();
	await go('edit.php?post_type=product&page=varconv&tab=history');
	await page.locator('.varconv-table tbody tr').nth(1).waitFor();
	await calm();
	// Hover the row so its Undo row action shows.
	await page.locator('.varconv-table tbody tr', { hasText: 'Barista Syrup' }).hover();
	await shot('screenshot-5');
}
