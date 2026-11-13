// Demo clip: the six House Blend Coffee products (3 grinds x 2 weights) combined into one variable product,
// renaming the attributes, swapping their order and dragging a row before Create. Uses the Live Preview demo data:
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
	// The page is an app: wait until its list or card has loaded before moving on.
	const settled = () => page.waitForFunction(() => document.querySelector('#varconv-root .varconv-tabs') && !document.querySelector('#varconv-root .is-loading, #varconv-root .components-spinner, #varconv-root .is-busy'));
	const go = async (path) => { await page.goto(base + '/wp-admin/' + path); await tidy(); await settled(); };
	const point = async (loc) => {
		await loc.scrollIntoViewIfNeeded();
		const b = await loc.boundingBox();
		await page.mouse.move(b.x + Math.min(b.width / 2, 40), b.y + b.height / 2, { steps: 14 });
		await pause(150);
	};
	const click = async (loc) => { await point(loc); await loc.click(); };
	const type = async (loc, text) => {
		await point(loc);
		await loc.click({ clickCount: 3 });
		await pause(150);
		await loc.pressSequentially(text, { delay: 55 });
		await pause(250);
	};
	const scrollTo = (y) => page.evaluate((y) => window.scrollTo({ top: y, behavior: 'smooth' }), y).then(() => pause(500));

	// The Combine tab lists the suggested groups A to Z: tick the two House Blend Coffee groups.
	await go('edit.php?post_type=product&page=varconv');
	await pause(600);
	const coffee = page.locator('.varconv-groups > tbody > tr', { hasText: 'House Blend Coffee' });
	const firstTop = await coffee.first().evaluate((el) => el.getBoundingClientRect().top + window.scrollY);
	await scrollTo(firstTop - 260);
	for (let i = 0; i < await coffee.count(); i++) { await click(coffee.nth(i).locator('.varconv-table__check input')); await pause(200); }
	await pause(300);
	const combine = page.getByRole('button', { name: 'Combine into one product' }).first();
	await point(combine);
	await combine.click();
	const card = page.locator('.varconv-card');
	await card.waitFor();
	await settled();
	await pause(1200);

	// Rename the attributes the scan guessed.
	const attributes = card.locator('.varconv-fields__attribute input');
	await type(attributes.nth(0), 'Weight');
	await page.keyboard.press('Escape');
	await type(attributes.nth(1), 'Grind');
	await page.keyboard.press('Escape');
	await attributes.nth(1).evaluate((el) => el.blur());
	await pause(500);

	// Shorten a label.
	const tbodyTop = await card.locator('.varconv-pick-table').evaluate((el) => el.getBoundingClientRect().top + window.scrollY);
	await scrollTo(tbodyTop - 260);
	const espresso = card.locator('.varconv-pick-table tbody tr', { hasText: 'CF1000-ES' }).locator('input[type=text]').nth(1);
	await type(espresso, 'Espresso');
	const espresso2 = card.locator('.varconv-pick-table tbody tr', { hasText: 'CF340-ES' }).locator('input[type=text]').nth(1);
	await type(espresso2, 'Espresso');
	await pause(400);

	// Grind first: swap the two attributes.
	await scrollTo(tbodyTop - 330);
	await click(card.locator('.varconv-fields__swap'));
	await pause(1300);

	// Drag a 340g bag above the 1kg ones, so 340g comes first in the Weight dropdown.
	const drag = async (fromSku, toSku) => {
		const from = card.locator('.varconv-pick-table tbody tr', { hasText: fromSku }).locator('.varconv-pick-table__grip').first();
		const to = card.locator('.varconv-pick-table tbody tr', { hasText: toSku }).first();
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
	await drag('CF340-ES', 'CF1000-ES');
	await pause(800);

	// Create.
	await scrollTo(tbodyTop - 330);
	const create = card.getByRole('button', { name: 'Create variable product' });
	await point(create);
	await pause(300);
	await create.click();
	await page.locator('.varconv-notices .varconv-notice').first().waitFor();
	await settled();
	await scrollTo(0);
	await pause(1200);

	// The product in the shop.
	const view = page.locator('.varconv-notices a', { hasText: /view/i }).first();
	let url = (await view.count()) ? await view.getAttribute('href') : null;
	if (!url) url = await page.evaluate(async (base) => (await (await fetch(base + '/wp-json/wc/store/v1/products?search=House+Blend+Coffee')).json()).find((p) => p.type === 'variable')?.permalink, base);
	if (url) {
		await page.goto(url);
		// A smaller main image, so the gallery thumbnails fit in the frame too.
		await page.addStyleTag({ content: '#wpadminbar{display:none!important} html{margin-top:0!important} .woocommerce-product-gallery{max-width:330px!important}' });
		await pause(600);
		const sel = page.locator('form.variations_form select').first();
		if (await sel.count()) {
			await sel.scrollIntoViewIfNeeded();
			const gallery = page.locator('.woocommerce-product-gallery').first();
			await scrollTo(Math.max(0, (await gallery.evaluate((el) => el.getBoundingClientRect().top + window.scrollY)) - 90));
			const selects = page.locator('form.variations_form select');
			// A native dropdown's list isn't in a recording, so draw it open: a list under the select with every option.
			const choose = async (select, index) => {
				await point(select);
				await page.mouse.down(); await page.mouse.up();
				await select.evaluate((el) => {
					const r = el.getBoundingClientRect();
					const list = document.createElement('div');
					list.id = 'clip-options';
					list.style.cssText = `position:absolute;left:${r.left + window.scrollX}px;top:${r.bottom + window.scrollY + 2}px;min-width:${r.width}px;background:#fff;border:1px solid #8c8f94;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);z-index:99999;padding:4px 0;font:15px/1.2 system-ui,sans-serif;color:#1e1e1e`;
					for (const o of el.options) {
						const item = document.createElement('div');
						item.textContent = o.text;
						item.dataset.value = o.value;
						item.style.cssText = 'padding:7px 14px;white-space:nowrap' + (o.value ? '' : ';color:#757575');
						list.appendChild(item);
					}
					document.body.appendChild(list);
				});
				await pause(900);
				const item = page.locator('#clip-options > div').nth(index);
				const b = await item.boundingBox();
				await page.mouse.move(b.x + 30, b.y + b.height / 2, { steps: 14 });
				await item.evaluate((el) => { el.style.background = '#2271b1'; el.style.color = '#fff'; });
				await pause(450);
				await page.mouse.down();
				await page.evaluate(() => document.getElementById('clip-options').remove());
				await select.selectOption({ index });
				await page.mouse.up();
				await pause(800);
			};
			// Espresso in 1kg: not the first product, so the gallery switches to that variation's picture.
			await choose(selects.nth(0), 1);
			await choose(selects.nth(1), 2);
			await pause(1600);
		}
	}

	const video = page.video();
	await ctx.close();
	renameSync(await video.path(), join(out, 'combine-coffee.webm'));
	console.log('wrote', join(out, 'combine-coffee.webm'));
}
