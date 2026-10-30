// wordpress.org listing screenshots 1-4, matching the readme captions. Needs the syrup groups:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php
//   harness/wp-harness wp option update blogname 'Demo Store'
//   harness/wp-harness browse .wordpress-org/screenshots.mjs --out .wordpress-org
export default async function ({ page, go, shot }) {
	const tidy = () => page.addStyleTag({ content: '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account) { display: none !important; } #wpbody-content { padding-top: 0 !important; }' });
	await go('edit.php?post_type=product&page=varconv');
	await tidy();
	await page.locator('#varconv-search').fill('Barista Syrup');
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-find .button-primary').click()]);
	await tidy();
	await page.locator('tr.varconv-group-row .varconv-pick').nth(0).check();
	await page.locator('tr.varconv-group-row .varconv-pick').nth(1).check();
	await page.locator('.varconv-loose-table tr', { hasText: 'Hazelnut' }).locator('.varconv-pick-loose').check();
	await shot('screenshot-1');
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-do[value="combine"]').first().click()]);
	await tidy();
	const card = page.locator('form.varconv-card[data-grid]');
	// The readme's example calls the syrups' attribute Flavor; the scan guesses Color.
	await card.locator('[name="group_attribute"]').fill('Flavor');
	await page.keyboard.press('Escape');
	await card.locator('[name="group_attribute"]').evaluate(el => el.blur());
	await page.evaluate(() => window.scrollTo(0, 0));
	await page.waitForTimeout(300);
	await shot('screenshot-2', page.locator('.varconv-list-table'));
	await card.locator('.varconv-create').click();
	await card.locator('.varconv-card-message .notice-success').waitFor();
	await go('edit.php?post_type=product&page=varconv&tab=split');
	await tidy();
	const srow = page.locator('tr', { hasText: 'Canvas Apron' }).first();
	await srow.locator('a').first().click();
	await page.waitForLoadState('networkidle');
	await tidy();
	await shot('screenshot-3');
	const split = page.locator('button, input[type=submit]', { hasText: /^Split/ }).first();
	if (await split.count()) { await Promise.all([page.waitForNavigation(), split.click()]); }
	await go('edit.php?post_type=product&page=varconv&tab=history');
	await tidy();
	await shot('screenshot-4');
}
