// Products a split made can be combined again:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-split-combine.php
//   harness/wp-harness browse tests/browser-split-combine.mjs --out shots
// Checks that search lists the split products as products not in a group, that the card says what combining them
// means for the split, and that History then keeps the split without Undo until the new product is undone.
import { watch, check, until, text, open, settled, notice, row } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	const loose = page.locator('.varconv-loose');
	await open(page, go);
	await page.locator('.varconv-search-panel input').fill('Linen Apron');
	await page.locator('.varconv-search-panel').getByRole('button', { name: 'Search', exact: true }).click();
	await until(page, async () => await loose.locator('tbody .varconv-table__check input').count() === 3, 'search lists the three split products');
	await shot('split-search');

	for (const box of await loose.locator('tbody .varconv-table__check input').all()) await box.check();
	await page.getByRole('button', { name: 'Combine into one product' }).first().click();
	const card = page.locator('.varconv-card');
	await card.waitFor();
	await settled(page);
	check(/made by splitting Linen Apron/.test(await text(page.locator('#varconv-root'))), 'the card says they were split from Linen Apron');
	check(!/left out/.test(await text(page.locator('#varconv-root'))), 'none is left out');
	await shot('split-card');
	await card.getByRole('button', { name: 'Create variable product' }).click();
	await notice(page).waitFor();
	await until(page, async () => /Created/i.test(await text(page.locator('.varconv-notices'))), 'the variable product is created');

	await open(page, go, '&tab=history');
	const split = page.locator('.varconv-table > tbody > tr', { hasText: 'Made into 3 simple products' }).first();
	await split.waitFor();
	check(/Undo waits until that is undone/.test(await text(split)), 'the split row says Undo waits for the new product');
	await split.hover();
	check(await split.getByRole('button', { name: 'Undo' }).count() === 0, 'the split row has no Undo');
	await shot('split-history');

	// Undo the new product, and the split can be undone again.
	const combined = page.locator('.varconv-table > tbody > tr', { hasText: 'Made from 3 simple products' }).first();
	await combined.hover();
	await combined.getByRole('button', { name: 'Undo' }).click();
	await page.getByRole('dialog').getByRole('button', { name: 'Undo' }).click();
	await until(page, async () => /Undid 1 conversion/.test(await text(page.locator('.varconv-notices'))), 'the new product is undone');
	await settled(page);
	await split.hover();
	await until(page, async () => await split.getByRole('button', { name: 'Undo' }).count() === 1, 'the split row offers Undo again');
	if (problems.length) throw new Error(problems.join('\n'));
}
