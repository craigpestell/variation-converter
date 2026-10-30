// Website images that the listing screenshots don't cover: the one-attribute card and the Combine list.
// Run on a reset site (demo catalog only), then crop as noted:
//   harness/wp-harness reset && harness/wp-harness wp option update blogname 'Demo Store'
//   harness/wp-harness browse site/screenshots.mjs --out <dir>
// combine-card.webp is the card as shot. combine-list.png is 1120x588 from the left edge of #wpbody-content.
// search.webp is .wordpress-org/screenshot-1.png cropped to 1120x828 from (160, 32), and combine-grid.webp is
// screenshot-2 as shot.
export default async function ({ page, go, shot }) {
	const tidy = () => page.addStyleTag({ content: '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account) { display: none !important; } #wpbody-content { padding-top: 0 !important; }' });
	await go('edit.php?post_type=product&page=varconv');
	await tidy();
	await shot('combine-list');
	await page.locator('#wpbody-content').getByRole('link', { name: 'Classic Crew Tee', exact: true }).click();
	await page.getByRole('button', { name: 'Create variable product' }).waitFor();
	await tidy();
	await page.evaluate(() => document.activeElement && document.activeElement.blur());
	await shot('combine-card', page.locator('form.varconv-card').first());
}
