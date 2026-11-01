// Every tab's toolbar: views, a bar above and below the table, Bulk actions where rows have checkboxes, row actions,
// and History's Undo row action. Needs a combined product and a split one in History:
//   harness/wp-harness browse tests/browser-toolbars.mjs --out shots
const tab = (t) => `edit.php?post_type=product&page=varconv${t ? `&tab=${t}` : ''}`;

export default async function ({ page, go, shot, problems }) {
	const expect = async (what, ok) => {
		if (!(await ok)) throw new Error(what);
	};
	for (const [name, bulk, checkboxes] of [['', true, true], ['split', false, false], ['history', true, true]]) {
		await go(tab(name));
		const label = name || 'combine';
		await expect(`${label}: views`, page.locator('.subsubsub li').count().then((n) => n >= 3));
		await expect(`${label}: bar above and below`, Promise.all([page.locator('.tablenav.top').count(), page.locator('.tablenav.bottom').count()]).then(([t, b]) => t === 1 && b >= 1));
		await expect(`${label}: count in both bars`, page.locator('.tablenav .displaying-num').count().then((n) => n >= 2));
		await expect(`${label}: Bulk actions above and below`, page.locator('.tablenav select[name="bulk_action"], .tablenav select[name="bulk_action2"]').count().then((n) => n === (bulk ? 2 : 0)));
		await expect(`${label}: checkboxes`, page.locator('table.wp-list-table thead .check-column input').count().then((n) => (n > 0) === checkboxes));
		await expect(`${label}: row actions`, page.locator('table.wp-list-table tbody .row-actions').count().then((n) => n > 0));
		await shot(label);
	}

	// Split's views filter the list.
	await go(tab('split') + '&view=combined');
	await expect('split: Combined here view lists only combined products', page.locator('.varconv-split-list tbody .column-note').allInnerTexts().then((t) => t.length > 0 && t.every((x) => /Combined here/.test(x))));

	// History's Undo row action undoes one conversion.
	await go(tab('history'));
	const before = await page.locator('.varconv-history tbody input[name="products[]"]').count();
	await page.locator('.varconv-history tbody tr').first().hover();
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-history tbody .row-actions .varconv-undo-link').first().click()]);
	const notice = await page.locator('.notice').first().innerText();
	console.log('notice:', notice);
	await expect('history: one fewer row after Undo', page.locator('.varconv-history tbody input[name="products[]"]').count().then((n) => n === before - 1));
	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
