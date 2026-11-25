// Shared by the clip scripts: a recording browser context with a drawn pointer, the admin tidied for video, and
// helpers that move the pointer before each click so viewers can follow it.
export const W = 1100, H = 700;

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

export const tidyCss = '#wpbody-content > .notice:not([class*=varconv]), .woocommerce-layout__header, #woocommerce-embedded-root, .woocommerce-store-alerts, #screen-meta-links, #wp-admin-bar-sqlite-db-integration, #wp-admin-bar-woocommerce-site-visibility-badge, #wp-admin-bar-top-secondary > li:not(#wp-admin-bar-my-account), #wpadminbar #wp-admin-bar-comments, #wpadminbar #wp-admin-bar-new-content { display: none !important; } #wpbody-content { padding-top: 0 !important; }';
// The storefront without the admin bar, as a shopper sees it.
const shopCss = '#wpadminbar{display:none!important} html{margin-top:0!important}';

export async function recorder({ browser, context, base, out, css = '' }) {
	const ctx = await browser.newContext({
		viewport: { width: W, height: H },
		storageState: await context.storageState(),
		recordVideo: { dir: out, size: { width: W, height: H } },
	});
	await ctx.addInitScript(cursor);
	// Fold the admin menu and hide notices before the first paint, so no frame shows them.
	// On the storefront, `css` styles the page before its scripts size anything (the product gallery measures itself).
	await ctx.addInitScript(([admin, front]) => {
		const style = document.createElement('style');
		if (location.pathname.startsWith('/wp-admin/')) {
			style.textContent = admin;
			document.documentElement.appendChild(style);
			addEventListener('DOMContentLoaded', () => document.body.classList.add('folded'));
		} else {
			style.textContent = front;
			addEventListener('DOMContentLoaded', () => document.head.appendChild(style));
		}
	}, [tidyCss, shopCss + css]);
	// WordPress keeps the folded admin menu in this per-user settings cookie.
	await ctx.addCookies([{ name: 'wp-settings-1', value: 'mfold=f', url: base }]);
	const page = await ctx.newPage();
	await page.mouse.move(W / 2, H / 2);
	const pause = (ms) => page.waitForTimeout(ms);
	const tidy = async () => {
		await page.addStyleTag({ content: tidyCss });
		await page.evaluate(() => document.body.classList.add('folded'));
	};
	// The plugin page is an app: wait until its list or card has loaded before moving on.
	const settled = () => page.waitForFunction(() => document.querySelector('#varconv-root .varconv-tabs') && !document.querySelector('#varconv-root .is-loading, #varconv-root .components-spinner, #varconv-root .is-busy'));
	const go = async (path) => { await page.goto(base + '/wp-admin/' + path); await tidy(); await settled(); };
	const shop = async (path) => { if (path) await page.goto(base + path); await page.addStyleTag({ content: shopCss }); };
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
	const scrollTo = (y, ms = 500) => page.evaluate((y) => window.scrollTo({ top: y, behavior: 'smooth' }), y).then(() => pause(ms));
	return { ctx, page, pause, tidy, settled, go, shop, point, click, type, scrollTo };
}
