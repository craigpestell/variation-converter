// The bulk create job keeps going when the admin changes tabs, and shows its progress on every tab:
//   harness/wp-harness reset && harness/wp-harness browse tests/browser-job.mjs --out shots
// Needs a catalog with many groups (more than one batch of 10). Action Scheduler's async runner may finish the job
// before the next page loads; filter action_scheduler_allow_async_request_runner to false to watch it count.
export default async function ({ page, go, shot, problems }) {
	const job = page.locator('.varconv-job');
	const done = async () => Number(((await job.innerText()).match(/(\d+) of \d+/) || [])[1]);
	await go('edit.php?post_type=product&page=varconv');
	await page.locator('.varconv-pick-all, .varconv-pick-page').first().check();
	await page.selectOption('[name="bulk_action"]', 'create');
	await page.locator('.varconv-bulk-apply').first().click();
	await job.waitFor();
	console.log('combine:', await job.innerText());
	await shot('job-combine');

	// Another tab: the notice is there, and the job is still counting or already done in the background.
	await go('edit.php?post_type=product&page=varconv&tab=history');
	console.log('history notice:', await job.innerText());
	await shot('job-history');
	if (!(await job.evaluate((el) => el.classList.contains('is-running')))) {
		if (!/^Created \d+ variable products/.test(await job.innerText())) throw new Error('no progress or summary on the History tab');
		console.log('Finished in the background before History loaded; run again with the async runner off to watch it count.');
		if (problems.length) throw new Error(`${problems.length} problems on the way`);
		return;
	}
	const first = await done();
	let later = first;
	for (let i = 0; i < 20 && later === first; i++) {
		await page.waitForTimeout(1000);
		later = await done();
	}
	console.log('history:', first, '->', later);
	if (!(later > first)) throw new Error('progress stopped on the History tab');

	// Stop it, then start again from Combine and let it finish on the Split tab.
	await job.locator('.varconv-job-stop').click();
	await page.locator('.varconv-job:not(.is-running)').waitFor();
	console.log('stopped:', await job.innerText());
	await shot('job-stopped');
	await go('edit.php?post_type=product&page=varconv&tab=split');
	console.log('split after stop, notice shown again:', await job.count());
	await go('edit.php?post_type=product&page=varconv');
	await page.locator('.varconv-pick-all, .varconv-pick-page').first().check();
	await page.selectOption('[name="bulk_action"]', 'create');
	await page.locator('.varconv-bulk-apply').first().click();
	await job.waitFor();
	await go('edit.php?post_type=product&page=varconv&tab=split');
	await page.locator('.varconv-job:not(.is-running)').waitFor({ timeout: 120000 });
	console.log('split, finished:', await job.innerText());
	await shot('job-done');

	if (problems.length) throw new Error(`${problems.length} problems on the way`);
}
