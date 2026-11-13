# Project profile

Every role reads this file first. It holds everything specific to the variation converter, so the shared role agents from [company-kit](https://github.com/craigpestell/company-kit) stay generic. `talent-lead` keeps it current, with `engineering-lead` for the technical sections.

## Product

- **Name:** Variation Converter for WooCommerce (slug `variation-converter-for-woocommerce`).
- **What it is:** a WordPress plugin that finds groups of simple WooCommerce products that are really one product in several variants and combines each approved group into one variable product, or splits a variable product into simple ones. Originals are hidden, not deleted, and Undo restores them.
- **Owner:** the founder's own product. Free on wordpress.org.
- **Stage:** live in the [wordpress.org plugin directory](https://wordpress.org/plugins/variation-converter-for-woocommerce/), version in the plugin header (0.17.1 on `main` as of 2026-11-03; 0.14.0 is the latest published release).

## Stack

- PHP 7.4+ (WordPress 6.5+, WooCommerce 8.0+), no Composer dependencies. The admin page is a React app built with `@wordpress/scripts` from WordPress's own components; it uses the React that ships with WordPress, and the compiled `build/` is committed.
- Code map: `variation-converter-for-woocommerce.php` (plugin header and bootstrap), `includes/` (scanner, converter, undo, redirects, WP-CLI, the REST routes in `rest*.php`, and the hooks into WordPress's own screens in `admin*.php`), `src/components/` (the plugin's React components, each with a `Name.stories.js`), `src/admin/` (the screens of the admin page), `build/` (the compiled app WordPress loads; committed), `.storybook/` (the component style guide, published at variation-converter.bizrocket.ca/components/), `assets/` (JS and CSS for Quick Edit and the Variations panel), `uninstall.php`, `tests/` (offline `test-*.php`, `integration-*.php` run inside WordPress, `browser-*.mjs` Playwright checks), `harness/` (throwaway WordPress + WooCommerce site), `site/` (GitHub Pages marketing site), `.wordpress-org/` (directory banners, icons, screenshots, and `blueprints/blueprint.json` for the Live Preview), `bin/svn-release.sh`.

## Commands

| What | Command |
| --- | --- |
| Make a test site (plugin active, demo catalog) | `harness/wp-harness up` |
| Lint | `harness/wp-harness lint` |
| Tests (offline and integration) | `harness/wp-harness test` |
| Plugin Check (wordpress.org rules) | `harness/wp-harness check` |
| Screenshots and browser scripts | `harness/wp-harness shot <path>`, `harness/wp-harness browse tests/browser-*.mjs` |
| Delete the test site | `harness/wp-harness down` |
| Build the admin app after changing `src/` (commit `build/` with the change) | `npm install` once, then `npm run build` |
| Lint the admin app | `npm run lint:js` |
| Component style guide | `npm run storybook` (local), `npm run build-storybook` (into `site/components`, which the Site workflow does on merge) |
| What ships | `git archive HEAD \| tar -t` (`.gitattributes` `export-ignore` decides) |

## Hosting and deploys

- **Host:** the wordpress.org plugin directory (SVN), and the project site on GitHub Pages. Company skill: `wordpress-plugin-release`.
- **How a change reaches users:** merging to `main` changes nothing for users. A release is a version bump PR, then `bin/svn-release.sh --dry-run`, then the founder publishes with `bin/svn-release.sh --password-file <file>`. The Site workflow republishes `site/` and its zip on merges that touch the plugin or site.
- **Rollback:** release a new patch version with the fix or the revert. wordpress.org serves the version in `Stable tag`, so an older tag can also be made stable again by changing `Stable tag` in a release.
- **Where secrets live:** the SVN password is in the founder's own password file, never in the repo (`.gitignore` covers it) or in CI.

## Rules every role keeps

- Never delete a merchant's products or data on the plugin's own initiative. Conversions hide originals and must stay undoable. The one exception is Trash originals, which the merchant chooses per combine: it moves originals to the Trash (never deletes for good, and refuses when the Trash is off), keeps their reviews and redirects, and Undo works until the Trash is emptied.
- The admin page is built from WordPress's components (`@wordpress/components`) first and the plugin's own (`src/components`) second; a new component of our own gets a story. Screens that WordPress or WooCommerce draw (Products list, Quick Edit, the Variations panel) stay PHP.
- Every admin action checks `manage_woocommerce` and a nonce (the REST nonce for the app's routes); input is sanitized and output escaped. Plugin Check must pass.
- No remote calls, tracking or analytics from the plugin.
- Keep PHP 7.4 and WordPress 6.5 compatibility unless a release raises the minimums on purpose.
- Only plugin files ship; tests, harness, site and listing assets are `export-ignore`.
- Parent variable products the plugin creates have no SKU.
- American English in the plugin UI, docs and listing (store attribute labels a merchant enters are left as entered).
- Never touch the live Ultralove store. Test on the harness or on dev.ultralove.ca only.

## Working threads and merging

The founder runs the project in a Claude project with one standing thread per area. Each maps onto the roster:

| Thread | Roles |
| --- | --- |
| Plugin code development | `engineering-lead`, `design-lead` |
| Code review and publishing | `qa-engineer`, `security-compliance`; owns versioning and release prep |
| Plugin docs and listing copy | `growth-lead` (all writing: readme.txt, docs, listing) |
| Plugin website | `growth-lead` for copy, `engineering-lead` for `site/` |

PRs may merge to `main` without asking the founder once the Code review and publishing thread has reviewed them and the checks below pass. Open PRs early.

## Deploy gate

Keep checks proportionate; the founder dislikes long test runs.

- **Every PR:** `php -l` on changed PHP files and the offline tests (`php tests/test-*.php`) pass on the exact commit, with results posted on the PR. A PR that changes `src/` also passes `npm run lint:js` and commits the `build/` that `npm run build` makes from it. (No CI test workflow yet; adding one with the `ci-github-actions` skill is planned.)
- **Larger UI or behavior changes, and every release:** also `harness/wp-harness test` and `harness/wp-harness check`, plus screenshots or browser checks for admin UI changes.
- `qa-engineer` (the review thread) has verified the change.
- `security-compliance` has reviewed changes to capabilities, nonces, AJAX or REST handlers, redirects, uninstall, or anything that writes products.
- No role approves its own work.

## What only the founder approves

- Spending money, publishing anything, and contacting anyone outside the company (including support forum replies)
- Every wordpress.org release (`svn-release.sh` without `--dry-run`) and listing changes
- Raising the minimum WordPress, WooCommerce or PHP versions

## Analytics

None in the plugin, and none may be added. The GitHub Pages site may use the `google-analytics` skill if the founder approves it.
