// Shared by the tests/browser-*.mjs walkthroughs of the React admin.

/** Fails the run on a script error or a REST error the test did not ask for. */
export function watch(page, problems) {
	page.on('pageerror', (e) => problems.push('script error: ' + e.message));
	page.on('response', async (r) => {
		if (r.url().includes('varconv') && r.url().includes('wp-json') && r.status() >= 500) {
			problems.push(`REST ${r.status()} ${r.url()}`);
		}
	});
}

export const fail = (what) => { throw new Error(what); };
export const check = (ok, what) => { if (!ok) fail(what); console.log('ok:', what); };
export const text = async (loc) => (await loc.innerText()).replace(/\s+/g, ' ').trim();

/** The plugin page at a tab or screen, once its first list has loaded. */
export async function open(page, go, query = '') {
	await go('edit.php?post_type=product&page=varconv' + query);
	await settled(page);
}

/** Waits for the app to be on screen with no list or button still loading. */
export async function settled(page) {
	await page.locator('#varconv-root .varconv-tabs').waitFor();
	await page.waitForFunction(() => !document.querySelector('#varconv-root .is-loading, #varconv-root .components-spinner, #varconv-root .is-busy'));
}

/** A list row by the name in it. */
export const row = (page, name) => page.locator('.varconv-table > tbody > tr:not(.varconv-table__panel)', { hasText: name }).first();

/** The notice above the tabs. */
export const notice = (page) => page.locator('.varconv-notices .varconv-notice').first();

/** Waits until test() is true, for what a change shows a moment later; fails with `what` when it never is. */
export async function until(page, test, what) {
	for (let i = 0; i < 100; i++) {
		if (await test()) { console.log('ok:', what); return; }
		await page.waitForTimeout(100);
	}
	fail(what);
}
