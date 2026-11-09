// History > select all on every page, then Bulk actions > Move originals to Trash: the header box checks this page,
// a banner offers every conversion in the list, and the action reaches combines on later pages too, as a background
// job with progress and Stop. Needs more
// conversions than one page shows (25):
//   harness/wp-harness eval tests/seed-history.php
//   harness/wp-harness browse tests/browser-history-select-all.mjs --out shots
export default async function ({ page, go, shot, problems }) {
	await go('edit.php?post_type=product&page=varconv&tab=history&view=combined');
	const banner = page.locator('.varconv-select-all');
	if (!(await banner.count())) throw new Error('no select-all banner: needs more than one page of combines');
	if (await banner.isVisible()) throw new Error('banner shown before anything is checked');
	await page.locator('#varconv-history-all').check();
	if (!(await banner.isVisible())) throw new Error('banner not shown with the whole page checked');
	await shot('page-selected');
	await page.locator('.varconv-select-matching').click();
	if ((await page.locator('#varconv-undo-checked .varconv-all-matching').inputValue()) !== '1') throw new Error('not marked as all matching');
	await shot('all-selected');
	// Unchecking a row goes back to just what is checked.
	await page.locator('.varconv-history tbody input[name="products[]"]').first().uncheck();
	if (await banner.isVisible()) throw new Error('banner still shown after unchecking a row');
	if ((await page.locator('#varconv-undo-checked .varconv-all-matching').inputValue()) !== '') throw new Error('still all matching after unchecking');
	await page.locator('#varconv-history-all').check();
	await page.locator('.varconv-select-matching').click();
	const total = Number((await page.locator('.tablenav.top .displaying-num').innerText()).replace(/\D/g, ''));
	await page.locator('.tablenav.top select[name="bulk_action"]').selectOption('trash_originals');
	await Promise.all([page.waitForNavigation(), page.locator('.tablenav.top .varconv-bulk-apply').click()]);
	// More than one batch runs on the server: progress with Stop, then the page reloads with the summary.
	const running = page.locator('.varconv-job.is-running');
	if (!(await running.count())) throw new Error('no background job progress shown');
	console.log('progress:', await running.innerText());
	if (!(await running.locator('.varconv-job-stop').count())) throw new Error('no Stop button');
	await shot('trash-running');
	// The page loads again once the job is done.
	await page.waitForEvent('load', { timeout: 120000 });
	await page.locator('.varconv-job:not(.is-running)').waitFor();
	const notice = await page.locator('.varconv-job').innerText();
	console.log('notice:', notice);
	await shot('after-trash');
	const moved = Number((notice.match(/Moved (\d+)/) || [])[1]);
	if (!(moved >= 2 * (total - 1))) throw new Error(`moved ${moved}, expected about ${2 * total} for ${total} combines`);
	// Nothing hidden is left on any page.
	await go('edit.php?post_type=product&page=varconv&tab=history&view=combined&paged=2');
	if (await page.locator('.row-actions .trash').count()) throw new Error('a combine on page 2 still has hidden originals');
	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
