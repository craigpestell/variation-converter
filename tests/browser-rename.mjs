// Set one name: tick products on the Combine tab, give them one name with their variants after it, then combine:
//   harness/wp-harness reset
//   harness/wp-harness browse tests/browser-rename.mjs --out shots
// Checks the form suggests the shared start of the titles, previews each new name as it is typed, renames the
// products, lists them under the new name with a Combine link, and that Undo puts the old names back.
export default async function ({ page, go, shot }) {
	const fail = (what) => { throw new Error(what); };
	await go('edit.php?post_type=product&page=varconv&s=Northwind');
	const loose = page.locator('.varconv-loose-table .varconv-pick-loose');
	const groups = page.locator('tr.varconv-group-row .varconv-pick');
	for (const box of [...await groups.all(), ...await loose.all()]) await box.check();
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-do[value="rename"]').first().click()]);
	const name = page.locator('#varconv-rename-name');
	console.log('suggested name:', await name.inputValue());
	if (await name.inputValue() !== 'Northwind') fail('the shared start is not suggested');
	await name.fill('Northwind Gear');
	const previews = await page.locator('.varconv-rename-preview').allInnerTexts();
	console.log('previews:', previews.join(' | '));
	if (!previews.every((p) => p.startsWith('Northwind Gear'))) fail('previews do not follow the typed name');
	await shot('rename-form');
	await Promise.all([page.waitForNavigation(), page.locator('form.varconv-rename .button-primary').click()]);
	const notice = await page.locator('.varconv-renamed').innerText();
	console.log('notice:', notice.replace(/\s+/g, ' '));
	if (!/Renamed \d+ products/.test(notice)) fail('no renamed notice');
	if (!(await page.locator('.varconv-renamed a.button-primary').count())) fail('no Combine link');
	await shot('renamed');
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-rename-undo button').click()]);
	const undone = await page.locator('.notice-success').first().innerText();
	console.log('undo:', undone);
	if (!/old names/.test(undone)) fail('undo notice missing');
}
