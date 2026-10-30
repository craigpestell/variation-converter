// History's Undo checked: check all on the page, confirm (the harness accepts the dialog), and every conversion is undone. Needs at least two
// conversions in History:
//   harness/wp-harness browse tests/browser-undo-checked.mjs --out shots
export default async function ({ page, go, shot, problems }) {
	await go('edit.php?post_type=product&page=varconv&tab=history');
	const rows = await page.locator('.varconv-history tbody input[name="products[]"]').count();
	if (rows < 2) throw new Error('needs at least two conversions in History');
	await page.locator('#varconv-history-all').check();
	const checked = await page.locator('.varconv-history tbody input[name="products[]"]:checked').count();
	if (checked !== rows) throw new Error(`check all checked ${checked} of ${rows}`);
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-undo-checked button').click()]);
	const notice = await page.locator('.notice').first().innerText();
	console.log('notice:', notice);
	await shot('undo-checked');
	if (!new RegExp(`^Undid ${rows} conversions`).test(notice)) throw new Error('no success notice');
	if (await page.locator('.varconv-history tbody input[name="products[]"]').count()) throw new Error('History still lists conversions');
	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
