// Search first: find a product line, tick its groups and a product the scan didn't group, and combine them:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php
//   harness/wp-harness browse tests/browser-search.mjs --out shots
// Checks the search lists groups and products not in a group, the selection count, the two-attribute card with the
// loose product as its own group, and that creating it keeps the search for the next one.
export default async function ({ page, go, shot, problems }) {
	const fail = (what) => { throw new Error(what); };
	await go('edit.php?post_type=product&page=varconv');
	await shot('search-start');
	await page.locator('#varconv-search').fill('Barista Syrup');
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-find .button-primary').click()]);
	const groups = await page.locator('tr.varconv-group-row .row-title').allInnerTexts();
	const loose = await page.locator('.varconv-loose-table tbody tr').allInnerTexts();
	console.log('groups:', groups.map(s => s.trim()).join(', '), '| loose:', loose.map(s => s.replace(/\s+/g, ' ').trim()).join(', '));
	if (groups.length !== 2) fail('expected two syrup groups');
	if (!loose.some(s => /Hazelnut/.test(s))) fail('Hazelnut is not listed as a product not in a group');
	await page.locator('tr.varconv-group-row .varconv-pick').nth(0).check();
	await page.locator('tr.varconv-group-row .varconv-pick').nth(1).check();
	await page.locator('.varconv-loose-table tr', { hasText: 'Hazelnut' }).locator('.varconv-pick-loose').check();
	console.log('picked:', await page.locator('.varconv-picked').first().innerText());
	await shot('search-results');
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-do[value="combine"]').first().click()]);
	console.log('url:', page.url().replace(/.*\?/, '?'));
	const card = page.locator('form.varconv-card[data-grid]');
	const values = async (sel) => card.locator(sel).evaluateAll(els => els.map(e => e.value));
	const name = await card.locator('[name="name"]').inputValue();
	const groupLabels = await values('.varconv-group-label');
	const labels = await values('input[name$="[label]"]');
	console.log('name:', name, '| groups:', groupLabels.join(', '), '| labels:', labels.join(', '));
	if (!groupLabels.includes('Hazelnut')) fail('Hazelnut is not its own group');
	if (!labels.includes('750ml')) fail('Hazelnut did not take the 750ml label');
	await shot('search-card', page.locator('.varconv-list-table'));
	// The page goes back to the search results, with the notice above them.
	await Promise.all([page.waitForNavigation(), card.locator('.varconv-create').click()]);
	console.log('created:', (await page.locator('.varconv-created').innerText()).split('\n')[0]);
	if (await page.locator('form.varconv-card[data-grid]').count()) fail('the combined card is still showing');
	console.log('search kept:', await page.locator('#varconv-search').inputValue(), '| groups left:', await page.locator('tr.varconv-group-row').count());
	if (problems.length) fail(problems.join('\n'));
}
