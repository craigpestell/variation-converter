// Add to a combined product: search, tick a product the scan didn't group, add it to a product already combined.
//   harness/wp-harness browse tests/browser-add-to.mjs --out shots
// Needs a combined "Harbor Hoodie" (Charcoal, Sand) and a simple "Harbor Hoodie Forest Green" with another SKU.
export default async function ({ page, go, shot, problems }) {
	const fail = (what) => { throw new Error(what); };
	await go('edit.php?post_type=product&page=varconv&s=Harbor+Hoodie');
	await page.locator('.varconv-loose-table tr', { hasText: 'Forest Green' }).locator('.varconv-pick-loose').check();
	await shot('add-search', page.locator('#wpbody-content'));
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-do[value="add"]').last().click()]);
	console.log('find:', await page.locator('#varconv-find-into').inputValue());
	await shot('add-choose', page.locator('#wpbody-content'));
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-add-targets tr', { hasText: 'Harbor Hoodie' }).locator('a.button').click()]);
	const label = await page.locator('.varconv-add-table input[type="text"]').first().inputValue();
	console.log('label:', label);
	if (label !== 'Forest Green') fail('expected the label Forest Green');
	await shot('add-labels', page.locator('#wpbody-content'));
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-add-form .button-primary').click()]);
	const notice = await page.locator('.notice-success').first().innerText();
	console.log('notice:', notice);
	if (!/Added 1 product/.test(notice)) fail('no Added notice');
	await shot('add-done', page.locator('#wpbody-content'));
	if (problems.length) fail(problems.join('\n'));
}
