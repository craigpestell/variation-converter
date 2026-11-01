// History's Bulk actions > Undo: Apply with no action says so; then check all on the page, choose Undo, confirm (the
// harness accepts the dialog), and every conversion is undone. Needs at least two conversions in History:
//   harness/wp-harness browse tests/browser-undo-checked.mjs --out shots
export default async function ({ page, go, shot, problems }) {
	await go('edit.php?post_type=product&page=varconv&tab=history');
	const rows = await page.locator('.varconv-history tbody input[name="products[]"]').count();
	if (rows < 2) throw new Error('needs at least two conversions in History');
	await page.locator('#varconv-history-all').check();
	const checked = await page.locator('.varconv-history tbody input[name="products[]"]:checked').count();
	if (checked !== rows) throw new Error(`check all checked ${checked} of ${rows}`);
	await Promise.all([page.waitForNavigation(), page.locator('.tablenav.top .varconv-bulk-apply').click()]);
	const missing = await page.locator('.notice').first().innerText();
	if (!/Choose a bulk action first/.test(missing)) throw new Error(`no "choose an action" notice: ${missing}`);
	await page.locator('#varconv-history-all').check();
	await page.locator('.tablenav.bottom select[name="bulk_action2"]').selectOption('undo');
	await Promise.all([page.waitForNavigation(), page.locator('.tablenav.bottom .varconv-bulk-apply').click()]);
	const notice = await page.locator('.notice').first().innerText();
	console.log('notice:', notice);
	await shot('undo-checked');
	if (!new RegExp(`^Undid ${rows} conversions`).test(notice)) throw new Error('no success notice');
	if (await page.locator('.varconv-history tbody input[name="products[]"]').count()) throw new Error('History still lists conversions');
	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
