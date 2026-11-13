// The Split tab on the harness demo catalog:
//   harness/wp-harness reset && harness/wp-harness browse tests/browser-split.mjs --out shots
// Checks the list, making one variation a simple product from its own screen, and splitting the rest.
import { watch, check, until, text, open, settled, row, notice } from './lib/browser.mjs';

export default async function ({ page, go, shot, problems }) {
	watch(page, problems);
	await open(page, go, '&tab=split');
	const apron = row(page, 'Canvas Apron');
	check(/2 \(Color\)/.test(await text(apron)), 'the apron has two Color variations');
	await shot('split');
	await apron.locator('.varconv-row-title').click();
	const card = page.locator('.varconv-table__panel .varconv-card');
	await card.waitFor();
	const names = () => card.locator('tbody input[type="text"]').evaluateAll((els) => els.map((e) => e.value));
	await until(page, async () => (await names()).join() === 'Canvas Apron Sand,Canvas Apron Charcoal', 'each variation has a product name ready');
	await shot('split-card');

	// One variation on its own screen.
	await card.getByText('Make simple product').first().click();
	await page.getByRole('button', { name: 'Make simple product' }).waitFor();
	check(/make_simple=/.test(page.url()), 'Make simple product has its own URL');
	await settled(page);
	await shot('make-simple');
	await page.getByRole('button', { name: 'Make simple product' }).click();
	await until(page, async () => /simple product/.test(await text(page.locator('.varconv-notices'))), 'the variation is made a simple product');
	await open(page, go, '&tab=history&view=taken');
	check(/Canvas Apron Sand/.test(await text(page.locator('.varconv-table'))), 'History lists it under Made simple');

	// Split what is left.
	await open(page, go, '&tab=split');
	await row(page, 'Canvas Apron').locator('.varconv-row-title').click();
	await card.waitFor();
	await card.getByRole('button', { name: /^Split into/ }).click();
	await notice(page).waitFor();
	await until(page, async () => /Split|simple product/.test(await text(notice(page))), 'the product is split');
	await open(page, go, '&tab=history&view=split');
	check(/Canvas Apron/.test(await text(page.locator('.varconv-table'))), 'History lists the split');
	if (problems.length) throw new Error(problems.join('\n'));
}
