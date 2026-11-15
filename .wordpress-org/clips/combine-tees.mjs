// Demo clip: the nine Everyday Tee products (3 colors x 3 sizes) in the shop, combined into one variable product with
// the fanned "all colors" picture as its main image, then the shop again, where one Everyday Tee takes its A to Z
// place, and its product page listing the nine variations. Uses the Live Preview demo store (see clips/README.md):
//   harness/wp-harness browse .wordpress-org/clips/combine-tees.mjs /tmp/clips
// Writes combine-tees.webm to that folder; clips/make.sh turns it into a GIF and an MP4.
import { renameSync } from 'node:fs';
import { join } from 'node:path';
import { W, H, recorder } from './lib.mjs';

export default async function ({ browser, context, base, args }) {
	const out = args[0] || 'clips';
	// A narrower gallery and one-line variant rows, so the product page fits the 1100px frame.
	const css = '.wp-block-columns:has(.woocommerce-product-gallery)>.wp-block-column:first-child{flex:0 0 40%!important} .wp-block-columns:has(.woocommerce-product-gallery)>.wp-block-column:last-child{flex:1 1 0!important} .demo-variants td{white-space:nowrap;font-size:15px;padding:.35em .45em!important} .demo-variants img{width:40px!important;height:40px!important} .demo-variants .button{padding:.4em .9em!important;font-size:14px!important} .demo-variants input.qty{width:3em!important;padding:.25em!important}';
	const { ctx, page, pause, point, click, scrollTo, settled, go, shop } = await recorder({ browser, context, base, out, css });
	const top = (loc) => loc.evaluate((el) => el.getBoundingClientRect().top + window.scrollY);

	// Before: the shop lists every color and size of the Everyday Tee as its own product.
	await shop('/');
	await pause(700);
	const tees = page.locator('li.product', { hasText: 'Everyday Tee' });
	await tees.first().waitFor();
	await scrollTo((await top(tees.first())) - 120, 1100);
	await point(tees.first().locator('img').first());
	await pause(1400);

	// Tick the three Everyday Tee groups on the Combine tab.
	await go('edit.php?post_type=product&page=varconv');
	await pause(600);
	const groups = page.locator('.varconv-groups > tbody > tr', { hasText: 'Everyday Tee' });
	await scrollTo((await top(groups.first())) - 300);
	for (let i = 0; i < await groups.count(); i++) { await click(groups.nth(i).locator('.varconv-table__check input')); await pause(200); }
	await pause(300);
	await click(page.getByRole('button', { name: 'Combine into one product' }).first());
	const card = page.locator('.varconv-card');
	await card.waitFor();
	await settled();
	await scrollTo((await top(card)) - 140);
	await pause(1400);

	// Main image: the picture of every color, from the media library.
	await click(card.getByRole('button', { name: 'Choose image' }));
	const modal = page.locator('.media-modal:visible');
	await modal.waitFor();
	const fan = modal.locator('.attachments .attachment[aria-label="Everyday Tee, all colors"]');
	// A first visit opens on Upload files: switch to the library.
	const library = modal.locator('.media-menu-item', { hasText: 'Media Library' });
	if (await library.isVisible()) { await pause(500); await click(library); }
	await fan.waitFor();
	await pause(900);
	await click(fan);
	await pause(900);
	await click(modal.locator('.media-button-select'));
	await modal.waitFor({ state: 'hidden' });
	await pause(500);
	await point(card.locator('.varconv-main-image__preview'));
	await pause(1300);

	// Create.
	const create = card.getByRole('button', { name: 'Create variable product' });
	await scrollTo((await top(create)) - 420, 900);
	await pause(500);
	await point(create);
	await pause(300);
	await create.click();
	await page.locator('.varconv-notices .varconv-notice').first().waitFor();
	await settled();
	await scrollTo(0);
	await pause(1400);

	// After: one Everyday Tee in the shop, in its A to Z place, with the all-colors picture.
	await shop('/');
	await pause(500);
	const tee = page.locator('li.product', { hasText: 'Everyday Tee' }).first();
	await tee.waitFor();
	await scrollTo((await top(tee)) - 330, 1100);
	await point(tee.locator('img').first());
	await pause(1800);

	// Its product page: the all-colors picture first, then each color in the gallery, and the nine variations in the card's order.
	await click(tee.locator('a').first());
	await page.waitForLoadState('load');
	await pause(1000);
	const title = page.locator('h1').first();
	await scrollTo(Math.max(0, (await top(title)) - 20), 1000);
	await pause(900);
	const thumbs = page.locator('.flex-control-thumbs li img');
	if (await thumbs.count() > 1) {
		await click(thumbs.nth(1));
		await pause(1300);
		await click(thumbs.nth(0));
		await pause(600);
	}
	const rows = page.locator('.demo-variants tr');
	if (await rows.count()) {
		await point(rows.last().locator('td').nth(1));
		await pause(2200);
	}

	const video = page.video();
	await ctx.close();
	renameSync(await video.path(), join(out, 'combine-tees.webm'));
	console.log('wrote', join(out, 'combine-tees.webm'));
}
