// Search first, a two-attribute card, and Add to a combined product:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php
//   harness/wp-harness browse tests/browser-search.mjs --out shots
// Checks the search lists groups and products not in a group, the two-attribute card and its grid, swapping the
// attributes, that creating keeps the search, and that the loose product joins the new product from the Add screen.
import { watch, check, until, text, open, settled, notice } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	const groups = page.locator('.varconv-groups');
	const loose = page.locator('.varconv-loose');
	const values = (loc) => loc.evaluateAll((els) => els.map((e) => e.value));
	await open(page, go);
	await page.locator('.varconv-search-panel input').fill('Barista Syrup');
	await page.locator('.varconv-search-panel').getByRole('button', { name: 'Search', exact: true }).click();
	await until(page, async () => await groups.locator('.varconv-row-title').count() === 2, 'the search finds the two syrup groups');
	check(/s=Barista/.test(page.url()), 'the search is in the URL');
	check(/Hazelnut/.test(await text(loose)), 'Hazelnut is listed as a product not in a group');
	await shot('search-results');

	// Both groups into one product with two attributes.
	for (const box of await groups.locator('tbody .varconv-table__check input').all()) await box.check();
	check(/Selected: 2 groups/.test(await text(page.locator('.varconv-combine-buttons').first())), 'the toolbar counts two groups');
	await page.getByRole('button', { name: 'Combine into one product' }).first().click();
	const card = page.locator('.varconv-card');
	await card.waitFor();
	check(/grid=/.test(page.url()), 'the card has its own URL');
	const fields = card.locator('.varconv-fields__attribute input');
	const before = await values(fields);
	check(before.length === 2, 'the card has two attributes: ' + before.join(' and '));
	const grid = card.locator('.varconv-pair-grid');
	check(/Not sold/.test(await text(grid)), 'the grid marks the pair no product has');
	await shot('grid-card');
	await card.locator('.varconv-fields__swap button, button.varconv-fields__swap').first().click();
	check((await values(fields)).join() === before.slice().reverse().join(), 'the swap button swaps the two attributes');
	await card.locator('.varconv-fields__swap button, button.varconv-fields__swap').first().click();
	await card.getByRole('button', { name: 'Create variable product' }).click();
	await notice(page).waitFor();
	await until(page, async () => await card.count() === 0, 'the card closes after creating');
	check(/s=Barista/.test(page.url()) && !/grid=/.test(page.url()), 'the search is kept for the next one');
	await until(page, async () => await groups.locator('.varconv-row-title').count() === 0, 'the two groups left the list');

	// The loose product joins it.
	await loose.locator('tbody .varconv-table__check input').first().check();
	await page.getByRole('button', { name: 'Add to a combined product' }).first().click();
	const targets = page.locator('.varconv-add-targets');
	await targets.waitFor();
	check(/Barista Syrup/.test(await text(targets)), 'the new product is offered as the one to add to');
	await shot('add-choose');
	await targets.getByRole('button', { name: 'Choose' }).first().click();
	const table = page.locator('.varconv-add-table');
	await table.waitFor();
	await settled(page);
	const guess = await values(table.locator('tbody input[type="text"]'));
	check(guess.includes('Hazelnut') && guess.includes('750ml'), 'the labels are guessed from the name: ' + guess.join(', '));
	await shot('add-labels');
	await page.getByRole('button', { name: /^Add as/ }).click();
	await until(page, async () => /Added 1 product/.test(await text(page.locator('.varconv-notices'))), 'the product is added as a variation');
	if (problems.length) throw new Error(problems.join('\n'));
}
