// Website images that the listing screenshots don't cover: the one-attribute card and the Combine list.
// Run on a reset site (demo catalog only), then crop as noted:
//   harness/wp-harness reset && harness/wp-harness wp option update blogname 'Demo Store'
//   harness/wp-harness browse site/screenshots.mjs --out <dir>
// combine-card.webp is the card as shot. combine-list.png (the social image) is 1120x588 from (160, 32) and
// combine-list.webp 1120x738 from the same corner. The rest are the listing screenshots (.wordpress-org/screenshots.mjs)
// cropped from (160, 32) at 1120 wide: search.webp from screenshot-2 (828 high), split.webp from screenshot-4 (780),
// history.webp from screenshot-5 (438); combine-grid.webp is screenshot-3 as shot. Update the width and height in
// index.html when a size changes.
export default async function ({ page, go, shot }) {
	const tidy = () => page.addStyleTag({ content: '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account) { display: none !important; } #wpbody-content { padding-top: 0 !important; }' });
	await go('edit.php?post_type=product&page=varconv');
	await page.locator('.varconv-table .varconv-row-title').first().waitFor();
	await tidy();
	await shot('combine-list');
	await page.locator('.varconv-table .varconv-row-title', { hasText: 'Classic Crew Tee' }).click();
	await page.getByRole('button', { name: 'Create variable product' }).waitFor();
	await tidy();
	await page.evaluate(() => document.activeElement && document.activeElement.blur());
	await shot('combine-card', page.locator('.varconv-card').first());
}
