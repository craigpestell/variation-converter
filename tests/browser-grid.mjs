// Browser walkthrough of "Combine into one product" on two groups, saving screenshots for PRs and the docs. Needs two groups
// that are one product in two attributes, which the demo catalog doesn't have; add them first:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php
//   harness/wp-harness browse tests/browser-grid.mjs --out shots
// Checks the suggested names and labels, the grid of pairs (with a pair not sold), a relabel, the create and Undo.
export default async function ({ page, go, shot, problems }) {
	const row = (name) => page.locator('tr.varconv-group-row', { hasText: name }).first();
	await go('edit.php?post_type=product&page=varconv');
	await row('Barista Syrup Vanilla').locator('.varconv-pick').check();
	await row('Barista Syrup Caramel').locator('.varconv-pick').check();
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-do[value="combine"]').first().click()]);
	console.log('url:', page.url().replace(/.*\?/, '?'));
	const card = page.locator('form.varconv-card[data-grid]');
	const values = async (sel) => card.locator(sel).evaluateAll(els => els.map(e => e.value));
	console.log('name:', await card.locator('[name="name"]').inputValue(), '| attributes:', await card.locator('[name="group_attribute"]').inputValue(), 'x', await card.locator('[name="attribute"]').inputValue());
	console.log('groups:', (await values('.varconv-group-label')).join(', '), '| labels:', (await values('input[name$="[label]"]')).join(', '));
	const grid = card.locator('.varconv-grid');
	await grid.waitFor();
	console.log('grid:', (await grid.locator('tr').allInnerTexts()).map(s => s.replace(/\s+/g, ' ').trim()).join(' / '));
	console.log('missing cells:', await grid.locator('td.is-missing').count(), '|', await grid.locator('p').innerText());
	await card.locator('[name="group_attribute"]').fill('Flavour');
	await shot('grid-card', page.locator('.varconv-list-table'));
	// A pair used twice shows in the grid, and Create refuses it.
	const first = card.locator('input[name$="[label]"]').first();
	const was = await first.inputValue();
	await first.fill('750ml');
	console.log('twice cells:', await grid.locator('td.is-twice').count());
	await card.locator('.varconv-create').click();
	await card.locator('.notice-error').waitFor();
	console.log('error:', await card.locator('.notice-error').innerText());
	await first.fill(was);
	await Promise.all([page.waitForNavigation(), card.locator('.varconv-create').click()]);
	console.log('created:', (await page.locator('.varconv-created').innerText()).split('\n')[0], '| grid card gone:', 0 === await page.locator('form.varconv-card[data-grid]').count());
	const link = await page.locator('.varconv-created a').first().getAttribute('href');
	const id = new URL(link).searchParams.get('post');
	// The product page offers both attributes.
	await go('/?p=' + id);
	console.log('product page selects:', (await page.locator('form.variations_form select').evaluateAll(els => els.map(e => e.name + '=' + [...e.options].map(o => o.value).filter(Boolean).join('|')))).join(' ; '));
	await shot('grid-product', page.locator('form.variations_form'));
	// Undo from History.
	await go('edit.php?post_type=product&page=varconv&tab=history');
	const undo = page.locator('form.varconv-undo').first();
	await Promise.all([page.waitForNavigation(), undo.locator('button').click()]);
	console.log('after undo:', (await page.locator('.notice').first().innerText()).trim());
	if (problems.length) throw new Error(problems.join('\n'));
}
