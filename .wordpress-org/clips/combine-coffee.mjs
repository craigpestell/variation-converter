// Demo clip: the six House Blend Coffee products (3 grinds x 2 weights) combined into one variable product,
// renaming the attributes, swapping their order and dragging rows before Create. Uses the Live Preview demo data:
//   harness/wp-harness up --no-seed && harness/wp-harness eval <blueprint runPHP code>   (see clips/README.md)
//   harness/wp-harness browse .wordpress-org/clips/combine-coffee.mjs /tmp/clips
// Writes combine-coffee.webm to that folder; clips/make.sh turns it into a GIF and an MP4.
import { renameSync } from 'node:fs';
import { join } from 'node:path';

const W = 1100, H = 700;

// A visible pointer, since recorded video has none, plus a ring on each click.
const cursor = () => {
	const add = () => {
		if (document.getElementById('clip-cursor')) return;
		const c = document.createElement('div');
		c.id = 'clip-cursor';
		c.style.cssText = 'position:fixed;left:0;top:0;width:22px;height:22px;z-index:2147483647;pointer-events:none;transform:translate(-3px,-2px);background:url("data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22"><path d="M3 2l14 9-6.5 1.2L14 19l-2.6 1.2-3.4-6.6L3 18z" fill="#111" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>') + '") no-repeat';
		const last = JSON.parse(sessionStorage.getItem('clip-xy') || '[550,350]');
		c.style.left = last[0] + 'px'; c.style.top = last[1] + 'px';
		document.documentElement.appendChild(c);
		addEventListener('mousemove', (e) => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; sessionStorage.setItem('clip-xy', JSON.stringify([e.clientX, e.clientY])); }, true);
		addEventListener('mousedown', (e) => {
			const r = document.createElement('div');
			r.style.cssText = `position:fixed;left:${e.clientX - 14}px;top:${e.clientY - 14}px;width:28px;height:28px;border-radius:50%;border:3px solid #2271b1;z-index:2147483646;pointer-events:none;transition:all .35s ease-out;opacity:.9`;
			document.documentElement.appendChild(r);
			requestAnimationFrame(() => { r.style.transform = 'scale(1.6)'; r.style.opacity = '0'; });
			setTimeout(() => r.remove(), 450);
		}, true);
	};
	document.readyState === 'loading' ? addEventListener('DOMContentLoaded', add) : add();
};

const tidyCss = '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account), #wpadminbar #wp-admin-bar-comments, #wpadminbar #wp-admin-bar-new-content { display: none !important; } #wpbody-content { padding-top: 0 !important; }';

export default async function ({ browser, context, base, args }) {
	const out = args[0] || 'clips';
	const ctx = await browser.newContext({
		viewport: { width: W, height: H },
		storageState: await context.storageState(),
		recordVideo: { dir: out, size: { width: W, height: H } },
	});
	await ctx.addInitScript(cursor);
	// Fold the admin menu and hide notices before the first paint, so no frame shows them.
	await ctx.addInitScript((css) => {
		if (!location.pathname.startsWith('/wp-admin/')) return;
		const style = document.createElement('style');
		style.textContent = css;
		document.documentElement.appendChild(style);
		addEventListener('DOMContentLoaded', () => document.body.classList.add('folded'));
	}, tidyCss);
	// WordPress keeps the folded admin menu in this per-user settings cookie.
	await ctx.addCookies([{ name: 'wp-settings-1', value: 'mfold=f', url: base }]);
	const page = await ctx.newPage();
	await page.mouse.move(W / 2, H / 2);
	const pause = (ms) => page.waitForTimeout(ms);
	const tidy = async () => {
		await page.addStyleTag({ content: tidyCss });
		await page.evaluate(() => document.body.classList.add('folded'));
	};
	const go = async (path) => { await page.goto(base + '/wp-admin/' + path); await tidy(); };
	const point = async (loc) => {
		await loc.scrollIntoViewIfNeeded();
		const b = await loc.boundingBox();
		await page.mouse.move(b.x + Math.min(b.width / 2, 40), b.y + b.height / 2, { steps: 18 });
		await pause(150);
	};
	const click = async (loc) => { await point(loc); await loc.click(); };
	const type = async (loc, text) => {
		await point(loc);
		await loc.click({ clickCount: 3 });
		await pause(150);
		await loc.pressSequentially(text, { delay: 85 });
		await pause(250);
	};
	const scrollTo = (y) => page.evaluate((y) => window.scrollTo({ top: y, behavior: 'smooth' }), y).then(() => pause(500));

	// Search.
	await go('edit.php?post_type=product&page=varconv');
	await pause(700);
	await type(page.locator('#varconv-search'), 'House Blend Coffee');
	await point(page.locator('.varconv-find .button-primary'));
	await Promise.all([page.waitForNavigation(), page.locator('.varconv-find .button-primary').click()]);
	await tidy();
	await pause(900);

	// Tick both groups, combine into one product.
	const picks = page.locator('tr.varconv-group-row .varconv-pick');
	for (let i = 0; i < await picks.count(); i++) { await click(picks.nth(i)); await pause(200); }
	await pause(400);
	const combine = page.locator('.varconv-do[value="combine"]').first();
	await point(combine);
	await Promise.all([page.waitForNavigation(), combine.click()]);
	await tidy();
	await pause(1200);

	// Rename the attributes the scan guessed.
	const card = page.locator('form.varconv-card[data-grid]');
	await type(card.locator('[name="group_attribute"]'), 'Weight');
	await page.keyboard.press('Escape');
	await type(card.locator('[name="attribute"]'), 'Grind');
	await page.keyboard.press('Escape');
	await card.locator('[name="attribute"]').evaluate((el) => el.blur());
	await pause(500);

	// Shorten a label.
	const tbodyTop = await card.locator('.varconv-members').evaluate((el) => el.getBoundingClientRect().top + window.scrollY);
	await scrollTo(tbodyTop - 260);
	const espresso = card.locator('.varconv-members tbody tr', { hasText: 'CF1000-ES' }).locator('input[type=text]').nth(1);
	await type(espresso, 'Espresso');
	const espresso2 = card.locator('.varconv-members tbody tr', { hasText: 'CF340-ES' }).locator('input[type=text]').nth(1);
	await type(espresso2, 'Espresso');
	await pause(400);

	// Grind first: swap the two attributes.
	await scrollTo(tbodyTop - 330);
	await click(card.locator('.varconv-swap'));
	await pause(1300);

	// Drag the 340g bags above the 1kg ones, so 340g comes first in the Weight dropdown.
	const drag = async (fromSku, toSku) => {
		const from = card.locator('.varconv-members tbody tr', { hasText: fromSku }).locator('td.handle, .handle').first();
		const to = card.locator('.varconv-members tbody tr', { hasText: toSku }).first();
		await point(from);
		await page.mouse.down();
		const tb = await to.boundingBox();
		const fb = await from.boundingBox();
		await page.mouse.move(fb.x + fb.width / 2, tb.y + 6, { steps: 30 });
		await pause(250);
		await page.mouse.move(fb.x + fb.width / 2, tb.y + 2, { steps: 4 });
		await pause(200);
		await page.mouse.up();
		await pause(500);
	};
	await scrollTo(tbodyTop - 120);
	await drag('CF340-WB', 'CF1000-ES');
	await pause(800);

	// Create.
	await scrollTo(tbodyTop - 330);
	const create = card.locator('.varconv-create');
	await point(create);
	await pause(300);
	await Promise.all([page.waitForNavigation(), create.click()]);
	await tidy();
	await scrollTo(0);
	await pause(1800);

	// The product in the shop.
	const view = page.locator('.notice a', { hasText: /View/i }).first();
	let url = (await view.count()) ? await view.getAttribute('href') : null;
	if (!url) url = await page.evaluate(async (base) => (await (await fetch(base + '/wp-json/wc/store/v1/products?search=House+Blend+Coffee')).json()).find((p) => p.type === 'variable')?.permalink, base);
	if (url) {
		await page.goto(url);
		await page.addStyleTag({ content: '#wpadminbar{display:none!important} html{margin-top:0!important}' });
		await pause(600);
		const sel = page.locator('form.variations_form select').first();
		if (await sel.count()) {
			await sel.scrollIntoViewIfNeeded();
			await scrollTo(Math.max(0, (await sel.evaluate((el) => el.getBoundingClientRect().top + window.scrollY)) - 300));
			const selects = page.locator('form.variations_form select');
			await point(selects.nth(0)); await selects.nth(0).selectOption({ index: 1 }); await pause(700);
			await point(selects.nth(1)); await selects.nth(1).selectOption({ index: 1 }); await pause(1800);
		}
	}

	const video = page.video();
	await ctx.close();
	renameSync(await video.path(), join(out, 'combine-coffee.webm'));
	console.log('wrote', join(out, 'combine-coffee.webm'));
}
