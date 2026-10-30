// Browser walkthrough of the Combine tab on the harness demo catalog, saving screenshots for PRs and the docs:
//   harness/wp-harness reset && harness/wp-harness browse tests/browser-combine.mjs --out shots
// Checks labels and notes, a partial create, a whole create, no parent SKU field, bulk create and phone width.
export default async function ({ page, go, shot, problems }) {
	const row = (name) => page.locator('tr.varconv-group-row', { hasText: name }).first();
	await go('edit.php?post_type=product&page=varconv');
	console.log('groups:', (await page.locator('tr.varconv-group-row .row-title').allTextContents()).map(s => s.replace(/\s+/g, ' ').trim()).join(' | '));
	await shot('combine');
	// Labels, size order, duplicate and category notes
	for (const name of ['Organic V-Neck Tee', 'Enamel Camp Mug', 'House Blend Coffee', 'Soy Candle']) {
		const r = row(name);
		await r.locator('.row-title').click();
		const card = page.locator('#varconv-' + await r.getAttribute('data-key'));
		const labels = await card.locator('.col-label input').evaluateAll(els => els.map(e => e.value));
		const notes = (await card.locator('.varconv-notes, .notice').allTextContents()).map(s => s.trim()).filter(Boolean);
		console.log(name, '| labels:', labels.join(', '), '| parent SKU field:', await card.locator('[name="sku"]').count(), '| notes:', notes.join(' / '));
		if (name === 'Organic V-Neck Tee') await shot('vneck');
		await r.locator('.row-title').click();
	}
	// Partial create on the candles: first two only
	let r = row('Soy Candle');
	let key = await r.getAttribute('data-key');
	await r.locator('.row-title').click();
	let card = page.locator('#varconv-' + key);
	const boxes = card.locator('.varconv-use');
	await boxes.nth(2).uncheck(); await boxes.nth(3).uncheck();
	await Promise.all([page.waitForNavigation(), card.locator('.varconv-create').click()]);
	console.log('after subset url:', page.url().replace(/.*\?/, '?'));
	console.log('notice:', (await page.locator('.varconv-created').first().innerText()).split('\n')[0]);
	const open = page.locator('tr.varconv-review:not([hidden])');
	console.log('open cards:', await open.count(), '| rows:', await open.locator('.varconv-members tbody tr').count(), '| bands:', (await open.locator('.varconv-band-pick').allTextContents()).join(', '), '| notes:', (await open.locator('.varconv-notes').allTextContents()).join(' / '));
	await shot('after-subset');
	// Create the rest: whole group, then the list reloads with the notice above it and without the group
	await Promise.all([page.waitForNavigation(), open.locator('.varconv-create').click()]);
	console.log('whole group notice:', (await page.locator('.varconv-created').first().innerText()).split('\n')[0]);
	console.log('candle group gone:', 0 === await page.locator('tr.varconv-group-row', { hasText: 'Soy Candle' }).count(), '| open cards:', await page.locator('tr.varconv-review:not([hidden])').count());
	// Bulk create with JS ends on the notice
	await go('edit.php?post_type=product&page=varconv');
	await row('Northwind Tote').locator('.varconv-pick').check();
	await Promise.all([page.waitForNavigation({ timeout: 20000 }), page.locator('.varconv-do[value="create"]').first().click()]);
	console.log('bulk notice:', await page.locator('.notice-success, .notice-warning').first().innerText());
	console.log('scan again on history:', await (async () => { await go('edit.php?post_type=product&page=varconv&tab=history'); return page.locator('.varconv-rescan').count(); })());
	console.log('combine count on history:', await page.locator('.nav-tab .count').first().innerText());
	// Phone width
	await page.setViewportSize({ width: 390, height: 800 });
	await go('edit.php?post_type=product&page=varconv');
	console.log('phone: open cards', await page.locator('tr.varconv-review:not([hidden])').count(), '| visible', await page.locator('tr.varconv-review').evaluateAll(els => els.filter(e => e.offsetHeight > 0).length), '| scroll', await page.evaluate(() => document.documentElement.scrollWidth));
	await page.locator('.row-title').first().click();
	console.log('phone open: scroll', await page.evaluate(() => document.documentElement.scrollWidth), '| card width', await page.locator('tr.varconv-review:not([hidden]) .varconv-review-inner').evaluate(e => e.getBoundingClientRect().width));
	await shot('phone', undefined);
	if (problems.length) throw new Error(`${problems.length} problems on the way: ${problems.join('; ')}`);
}
