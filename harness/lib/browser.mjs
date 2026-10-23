// Playwright against the harness site, logged in as admin. Called by `wp-harness shot` and `wp-harness browse`.
//   node browser.mjs shot PATH... [--out DIR] [--width N] [--height N] [--full]
//   node browser.mjs run SCRIPT.mjs [ARGS...]
// A browse script exports a default async function that gets { page, context, browser, base, shot, go, args, problems }.
import { createRequire } from 'node:module';
import { existsSync, mkdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const base = process.env.HARNESS_URL || 'http://localhost:8080';

function loadPlaywright() {
	const require = createRequire(import.meta.url);
	const paths = (process.env.NODE_PATH || '').split(':').filter(Boolean);
	for (const name of ['playwright', 'playwright-core']) {
		try {
			return require(require.resolve(name, { paths: [process.cwd(), ...paths] }));
		} catch {}
	}
	console.error('Playwright not found. Install it once: npm i -g playwright && npx playwright install chromium');
	process.exit(1);
}

async function launch(chromium) {
	try {
		return await chromium.launch();
	} catch (e) {
		// The cloud image ships one Chromium build; use it when the bundled version doesn't match.
		const exe = process.env.HARNESS_CHROMIUM || '/opt/pw-browsers/chromium';
		if (!existsSync(exe)) throw e;
		return chromium.launch({ executablePath: exe, args: ['--headless=new'], ignoreDefaultArgs: ['--headless=old'] });
	}
}

function options(argv) {
	const o = { out: 'screenshots', width: 1280, height: 900, full: false, rest: [] };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		if (a === '--out') o.out = argv[++i];
		else if (a === '--width') o.width = Number(argv[++i]);
		else if (a === '--height') o.height = Number(argv[++i]);
		else if (a === '--full') o.full = true;
		else o.rest.push(a);
	}
	return o;
}

function fileName(path) {
	const name = path.replace(/^\/+/, '').replace(/^wp-admin\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');
	return (name || 'home').slice(0, 80) + '.png';
}

async function main() {
	const [mode, ...argv] = process.argv.slice(2);
	const o = options(argv);
	const { chromium } = loadPlaywright();
	const browser = await launch(chromium);
	const context = await browser.newContext({ viewport: { width: o.width, height: o.height } });
	const page = await context.newPage();

	// Problems worth knowing about in any click-through: JS errors, failed requests, PHP notices.
	const problems = [];
	page.on('dialog', (d) => d.accept());
	page.on('pageerror', (e) => problems.push(`JS error: ${e.message}`));
	// Only this site's requests: outside hosts (Gravatar, CDNs) are often blocked in sandboxes.
	const ours = (url) => url.startsWith(base);
	page.on('console', (m) => m.type() === 'error' && !m.text().startsWith('Failed to load resource') && problems.push(`console: ${m.text()}`));
	page.on('response', (r) => r.status() >= 400 && ours(r.url()) && !r.url().includes('favicon') && problems.push(`${r.status()} ${r.url()}`));
	// ERR_ABORTED is a request cut off by the next navigation, not a failure.
	page.on('requestfailed', (r) => ours(r.url()) && !/ERR_ABORTED/.test(r.failure()?.errorText) && problems.push(`failed: ${r.url()} ${r.failure()?.errorText || ''}`));

	await page.goto(`${base}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin');
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	if (page.url().includes('wp-login.php')) throw new Error('Login failed (use localhost, not 127.0.0.1, so the cookie sticks)');

	const out = resolve(o.out);
	mkdirSync(out, { recursive: true });
	const go = async (path) => {
		await page.goto(path.startsWith('http') ? path : base + (path.startsWith('/') ? path : `/wp-admin/${path}`));
		await page.waitForLoadState('networkidle').catch(() => {});
		const notice = await page.locator('text=/(Fatal error|Warning|Notice|Deprecated):/').first().textContent().catch(() => null);
		if (notice) problems.push(`PHP on ${path}: ${notice.trim().slice(0, 200)}`);
	};
	const shot = async (name, target = page) => {
		const file = join(out, name.endsWith('.png') ? name : `${name}.png`);
		await target.screenshot({ path: file, ...(target === page ? { fullPage: o.full } : {}) });
		console.log(file);
		return file;
	};

	let status = 0;
	try {
		if (mode === 'shot') {
			for (const path of o.rest.length ? o.rest : ['index.php']) {
				await go(path);
				await shot(fileName(path));
			}
		} else if (mode === 'run') {
			const [script, ...args] = o.rest;
			const mod = await import(pathToFileURL(resolve(script)).href);
			await mod.default({ page, context, browser, base, shot, go, args, problems });
		} else {
			throw new Error(`unknown mode ${mode}`);
		}
	} catch (e) {
		console.error(e.stack || String(e));
		status = 1;
	} finally {
		for (const p of problems) console.error(`problem: ${p}`);
		await browser.close();
	}
	process.exit(status);
}

main();
