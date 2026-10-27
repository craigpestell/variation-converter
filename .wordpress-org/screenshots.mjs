// wordpress.org listing screenshots 1-4, matching the readme captions.
export default async function ({ page, go, shot }) {
	const tidy = () => page.addStyleTag({ content: '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account) { display: none !important; } #wpbody-content { padding-top: 0 !important; }' });
	await go('edit.php?post_type=product&page=varconv');
	await tidy();
	await shot('screenshot-1');
	const row = page.locator('tr.varconv-group-row', { hasText: 'Classic Crew Tee' }).first();
	await row.locator('.row-title').click();
	const card = page.locator('#varconv-' + await row.getAttribute('data-key'));
	await card.scrollIntoViewIfNeeded();
	await row.evaluate(el => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 60));
	await shot('screenshot-2');
	await card.locator('.varconv-create').click();
	await page.waitForTimeout(4000);
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
