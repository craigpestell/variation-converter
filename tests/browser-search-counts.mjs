// The search on every tab, and view counts that follow it:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-grid.php && harness/wp-harness eval tests/seed-split-combine.php
//   harness/wp-harness browse tests/browser-search-counts.mjs --out shots
// Checks every tab starts with the same search panel, that a search makes each view's count count only what it
// finds (the count a view shows is the number of rows it lists), that switching view keeps the search, and that
// clearing the search brings the full counts back.
import { watch, check, until, text, open, settled } from './lib/browser.mjs';

const counts = async (page) => {
	const out = {};
	for (const item of await page.locator('.varconv-views button').all()) {
		const m = (await text(item)).match(/^(.*) \((\d+)\)$/);
		out[m[1]] = Number(m[2]);
	}
	return out;
};
const search = async (page, s) => {
	await page.locator('.varconv-search-panel input').fill(s);
	await page.locator('.varconv-search-panel').getByRole('button', { name: 'Search', exact: true }).click();
	await page.waitForURL(s ? new RegExp('s=' + encodeURIComponent(s).replace(/%20/g, '(\\+|%20)')) : /^((?!s=).)*$/);
	await settled(page);
};
const rows = (list) => list.locator('.varconv-row-title').count();
const view = async (page, label) => {
	await page.locator('.varconv-views button', { hasText: label }).first().click();
	await settled(page);
};

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);

	// Combine.
	await open(page, go);
	const before = await counts(page);
	check(before.All >= 3, 'Combine lists suggested groups: ' + JSON.stringify(before));
	await search(page, 'Barista Syrup');
	const found = await counts(page);
	check(found.All === 2 && found.All < before.All, 'All counts the two groups the search finds: ' + JSON.stringify(found));
	check(found.Ready + found['Needs review'] === found.All, 'Ready and Needs review add up to All');
	check(found.All === await rows(page.locator('.varconv-groups')), 'All matches the rows listed');
	check(new RegExp('Combine\\D*' + before.All + '\\b').test(await text(page.locator('.varconv-tabs'))), 'the Combine tab still counts every group: ' + before.All);
	await shot('combine-search');
	for (const [label, key] of [['Ready', 'ready'], ['Needs review', 'review']]) {
		await view(page, label);
		check(/s=Barista/.test(page.url()) && new RegExp('view=' + key).test(page.url()), label + ' keeps the search');
		const n = (await counts(page))[label];
		check(n === found[label] && n === await rows(page.locator('.varconv-groups')), label + ' lists as many groups as it counts: ' + n);
	}
	await page.locator('.varconv-search-panel').getByRole('button', { name: 'Clear search' }).click();
	await until(page, async () => !/s=/.test(page.url()), 'Clear search drops the search');
	await settled(page);
	check(JSON.stringify(await counts(page)).length > 0 && (await counts(page)).Ready === before.Ready, 'the full counts come back');

	// Split and History: the same panel in the same place, and counts that follow the search.
	for (const [tab, term] of [['split', 'zzz-no-such-product'], ['history', 'zzz-no-such-product']]) {
		await open(page, go, '&tab=' + tab);
		const first = page.locator('#varconv-root .varconv-search-panel');
		check(await first.count() === 1, tab + ' starts with the search panel');
		const panelY = (await first.boundingBox()).y;
		const viewsY = (await page.locator('.varconv-views').boundingBox()).y;
		check(panelY < viewsY, tab + ': the search sits above the views');
		await search(page, term);
		const c = await counts(page);
		check(Object.values(c).every((n) => n === 0), tab + ': a search that finds nothing counts 0 in every view: ' + JSON.stringify(c));
		await shot(tab + '-search');
	}
	if (problems.length) throw new Error(problems.join('\n'));
}
