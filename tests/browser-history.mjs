// The History tab with 30 combines:
//   harness/wp-harness reset && harness/wp-harness eval tests/seed-history.php
//   harness/wp-harness browse tests/browser-history.mjs --out shots
// Checks Undo on one row, Move originals to Trash for checked rows, and "Select all" with Undo for every conversion,
// which runs as a background job with its progress above the tabs.
import { watch, check, until, text, open, settled, row } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	const count = async () => Number(((await text(page.locator('.varconv-views'))).match(/All \((\d+)\)/) || [])[1]);
	const confirm = (name) => page.getByRole('dialog').getByRole('button', { name }).click();
	const notices = page.locator('.varconv-notices');
	const bulk = async (action) => {
		await page.locator('.varconv-bulk-actions select').first().selectOption(action);
		await page.locator('.varconv-bulk-actions').first().getByRole('button', { name: 'Apply' }).click();
	};
	await open(page, go, '&tab=history');
	check(await count() === 30, 'History has the 30 seeded combines');
	await shot('history');

	// One row.
	const first = row(page, 'VC History Mug 30');
	await first.hover();
	await first.getByRole('button', { name: 'Undo' }).click();
	await confirm('Undo');
	await until(page, async () => /Undid 1 conversion/.test(await text(notices)), 'Undo on a row undoes it');
	await until(page, async () => await count() === 29, 'the list counts 29');

	// Checked rows.
	await row(page, 'VC History Mug 29').locator('input[type="checkbox"]').check();
	await row(page, 'VC History Mug 28').locator('input[type="checkbox"]').check();
	await bulk('trash_originals');
	await confirm('Move to Trash');
	await until(page, async () => /Moved 4 original simple products to the Trash/.test(await text(notices)), 'the checked combines move their originals to the Trash');
	await settled(page);
	check(/in the Trash/.test(await text(row(page, 'VC History Mug 29'))), 'the row says its originals are in the Trash');

	// Every conversion, past the page that shows.
	await page.locator('.varconv-table thead input[type="checkbox"]').check();
	const banner = page.locator('.varconv-select-all');
	await banner.getByRole('button', { name: /Select all 29/ }).click();
	check(/All 29/.test(await text(banner)), 'Select all picks all 29');
	await shot('history-select-all');
	await bulk('undo');
	await confirm('Undo');
	await until(page, async () => /Undid 29|Undoing|29 of 29/.test(await text(page.locator('#varconv-root'))), 'Undo for all runs, as a job when it is many');
	await shot('history-job');
	for (let i = 0; i < 600 && await count() !== 0; i++) await page.waitForTimeout(200);
	check(await count() === 0, 'History is empty when it has finished');
	await open(page, go);
	check(/Combine \d+/.test(await text(page.locator('.varconv-tabs'))), 'the Combine tab counts the groups again');
	if (problems.length) throw new Error(problems.join('\n'));
}
