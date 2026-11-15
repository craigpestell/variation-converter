// Products > All Products: combined products as groups, on the harness demo catalog after a combine:
//   harness/wp-harness reset && harness/wp-harness wp varconv combine --all --limit=3
//   harness/wp-harness browse tests/browser-product-groups.mjs --out shots
// Checks the hidden originals are left out of the list, a combined product's toggle shows its variants beneath it,
// Expand all and Collapse all, and that turning grouping off lists the originals again.
import { watch, check, until } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	await go('edit.php?post_type=product&orderby=title&order=asc');
	const names = await page.locator('#the-list .column-name .row-title').allInnerTexts();
	check(names.includes('Classic Crew Tee'), 'the combined product is listed');
	check(!names.includes('Classic Crew Tee Black'), 'its hidden originals are not');
	const toggles = page.locator('#the-list .varconv-group-toggle');
	check(await toggles.count() >= 1, 'combined products have a variants toggle');
	await shot('groups-collapsed');

	const tee = page.locator('#the-list tr', { has: page.locator('.row-title', { hasText: /^Classic Crew Tee$/ }) });
	await tee.locator('.varconv-group-toggle').click();
	const children = page.locator('#the-list tr.varconv-child:not(.varconv-child-message)');
	await until(page, async () => (await children.count()) === 4, 'the tee shows its four variants');
	check(/Hidden original/.test(await children.first().innerText()), 'each variant links its hidden original');
	await page.waitForTimeout(300);
	await shot('groups-one-open');

	await page.locator('.varconv-expand-all').click();
	await until(page, async () => (await page.locator('#the-list tr.varconv-open').count()) === (await toggles.count()), 'Expand all opens every group');
	await until(page, async () => (await page.locator('#the-list tr.varconv-loading').count()) === 0, 'every group loads');
	await page.waitForTimeout(300);
	await shot('groups-all-open');

	await page.locator('.varconv-collapse-all').click();
	check((await page.locator('#the-list tr.varconv-child').count()) === 0, 'Collapse all closes every group');

	// Grouping off lists the originals again, then back on.
	await page.locator('.varconv-group-switch').click();
	await page.waitForLoadState('load');
	await go('edit.php?post_type=product&orderby=title&order=asc');
	const all = await page.locator('#the-list .column-name .row-title').allInnerTexts();
	check(all.includes('Classic Crew Tee Black'), 'with grouping off, originals are listed');
	await page.locator('.varconv-group-switch').click();
	await page.waitForLoadState('load');
	await go('edit.php?post_type=product&orderby=title&order=asc');
	check(!(await page.locator('#the-list .column-name .row-title').allInnerTexts()).includes('Classic Crew Tee Black'), 'grouping back on');

	await page.setViewportSize({ width: 390, height: 844 });
	await go('edit.php?post_type=product&orderby=title&order=asc');
	await page.locator('.varconv-expand-all').click();
	await until(page, async () => (await page.locator('#the-list tr.varconv-loading').count()) === 0 && (await children.count()) > 0, 'phone: groups load');
	await page.waitForTimeout(300);
	await shot('groups-phone');
}
