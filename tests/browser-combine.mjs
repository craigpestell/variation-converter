// Browser walkthrough of the Combine tab on the harness demo catalog, saving screenshots for PRs and the docs:
//   harness/wp-harness reset && harness/wp-harness browse tests/browser-combine.mjs --out shots
// Checks the list, a card's labels and notes, a partial create and its Undo, a whole create, skip and restore,
// Create a product for each group, the filters and phone width.
import { watch, check, until, text, open, settled, row, notice } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	const titles = () => page.locator('.varconv-table .varconv-row-title').allInnerTexts();
	const card = page.locator('.varconv-table__panel .varconv-card');
	await open(page, go);
	check((await titles()).length === 7, 'the demo catalog has 7 suggested groups');
	await shot('combine');

	// A card: labels in size order, notes, no parent SKU field.
	await row(page, 'Organic V-Neck Tee').locator('.varconv-row-title').click();
	await card.waitFor();
	const labels = await card.locator('.varconv-pick-table tbody input[type="text"]').evaluateAll((els) => els.map((e) => e.value));
	check(labels.join(',') === 'Small,Medium,Large,XL', 'the V-Neck labels are in size order');
	check(/Prices differ/.test(await text(card.locator('.varconv-card__notes'))), 'the card says prices differ');
	await shot('card');
	await row(page, 'Organic V-Neck Tee').locator('.varconv-row-title').click();
	check(await card.count() === 0, 'clicking the name again closes the card');

	// Partial create on the candles: the first two only. The card stays open on what is left.
	await row(page, 'Soy Candle').locator('.varconv-row-title').click();
	await card.waitFor();
	const boxes = card.locator('.varconv-pick-table tbody input[type="checkbox"]');
	await boxes.nth(2).uncheck();
	await boxes.nth(3).uncheck();
	await card.getByRole('button', { name: 'Create variable product' }).click();
	await notice(page).waitFor();
	await settled(page);
	check(/Created/.test(await text(notice(page))), 'creating two of four says so: ' + (await text(notice(page))).slice(0, 80));
	check(await card.locator('.varconv-pick-table tbody tr').count() === 2, 'the card stays open with the two products left');
	await shot('after-subset');

	// Undo from the notice puts the four back.
	await notice(page).getByRole('button', { name: 'Undo conversion' }).click();
	await page.getByRole('dialog').getByRole('button', { name: 'Undo conversion' }).click();
	await page.waitForFunction(() => /Undone/.test(document.querySelector('.varconv-notices')?.innerText || ''));
	await settled(page);
	if (!(await card.count())) await row(page, 'Soy Candle').locator('.varconv-row-title').click();
	await card.waitFor();
	check(await card.locator('.varconv-pick-table tbody tr').count() === 4, 'Undo puts the four candles back in the group');

	// The whole group (unticking is remembered, so tick the two again): the list comes back without it.
	await boxes.nth(2).check();
	await boxes.nth(3).check();
	await card.getByRole('button', { name: 'Create variable product' }).click();
	await page.waitForFunction(() => /Created/.test(document.querySelector('.varconv-notices')?.innerText || ''));
	await settled(page);
	await until(page, async () => !(await titles()).some((t) => /Soy Candle/.test(t)), 'the candle group left the list');
	check(await card.count() === 0, 'no card is open');
	await until(page, async () => /Combine 6/.test(await text(page.locator('.varconv-tabs'))), 'the Combine tab counts 6');

	// Skip from the row, then restore from the Skipped view.
	await row(page, 'Northwind Tote').hover();
	await row(page, 'Northwind Tote').getByRole('button', { name: 'Skip, not variants' }).click();
	await settled(page);
	await until(page, async () => !(await titles()).some((t) => /Northwind/.test(t)), 'the skipped group left the list');
	await page.locator('.varconv-views').getByText('Skipped').click();
	await settled(page);
	await until(page, async () => (await titles()).some((t) => /Northwind/.test(t)), 'it is in the Skipped view');
	await row(page, 'Northwind Tote').locator('input[type="checkbox"]').check();
	await page.locator('.varconv-bulk-actions select').first().selectOption('restore');
	await page.locator('.varconv-bulk-actions').first().getByRole('button', { name: 'Apply' }).click();
	await settled(page);
	await page.locator('.varconv-views').getByText('All').click();
	await settled(page);
	await until(page, async () => (await titles()).some((t) => /Northwind/.test(t)), 'Restore puts it back');

	// Create a product for each group: two checked groups.
	await row(page, 'Northwind Tote').locator('input[type="checkbox"]').check();
	await row(page, 'Classic Crew Tee').locator('input[type="checkbox"]').check();
	check(/Selected: 2 groups/.test(await text(page.locator('.varconv-combine-buttons').first())), 'the toolbar counts the checked groups');
	await page.getByRole('button', { name: 'Create a product for each group' }).first().click();
	await page.waitForFunction(() => /Created 2/.test(document.querySelector('.varconv-notices')?.innerText || ''));
	await settled(page);
	await until(page, async () => (await titles()).length === 4, 'four groups are left');
	await shot('after-bulk');

	// Filters: the view and the warnings filter narrow the list and are in the URL.
	await page.locator('.varconv-views').getByText('Needs review').click();
	await settled(page);
	check(/view=review/.test(page.url()), 'the view is in the URL');
	await until(page, async () => (await titles()).length === 3, 'Needs review lists the three groups with a warning');
	await page.goBack();
	await until(page, async () => (await titles()).length === 4, 'Back returns to every group');

	// Phone width: the list keeps its names and actions.
	await page.setViewportSize({ width: 390, height: 800 });
	await open(page, go);
	check((await titles()).length === 4, 'the list shows at phone width');
	check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no sideways scroll at phone width');
	await shot('combine-phone');
	if (problems.length) throw new Error(problems.join('\n'));
}
