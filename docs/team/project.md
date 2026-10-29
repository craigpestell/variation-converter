# Project profile

Every role reads this file first. It holds everything specific to the variation converter, so the shared role agents from [company-kit](https://github.com/craigpestell/company-kit) stay generic. `talent-lead` keeps it current, with `engineering-lead` for the technical sections.

## Product

- **Name:** Variation Converter for WooCommerce (slug `variation-converter-for-woocommerce`).
- **What it is:** a WordPress plugin that finds groups of simple WooCommerce products that are really one product in several variants and combines each approved group into one variable product, or splits a variable product into simple ones. Originals are hidden, not deleted, and Undo restores them.
- **Owner:** the founder's own product. Free on wordpress.org.
- **Stage:** live in the [wordpress.org plugin directory](https://wordpress.org/plugins/variation-converter-for-woocommerce/), version in the plugin header (0.4.0 as of 2026-10-29).

## Stack

- PHP 7.4+ (WordPress 6.5+, WooCommerce 8.0+), no build step, no Composer dependencies.
- Code map: `variation-converter-for-woocommerce.php` (plugin header and bootstrap), `includes/` (scanner, converter, undo, redirects, admin page, WP-CLI), `assets/` (admin JS and CSS), `uninstall.php`, `tests/` (offline `test-*.php`, `integration-*.php` run inside WordPress, `browser-*.mjs` Playwright checks), `harness/` (throwaway WordPress + WooCommerce site), `site/` (GitHub Pages marketing site), `.wordpress-org/` (directory banners, icons, screenshots), `bin/svn-release.sh`.

## Commands

| What | Command |
| --- | --- |
| Make a test site (plugin active, demo catalog) | `harness/wp-harness up` |
| Lint | `harness/wp-harness lint` |
| Tests (offline and integration) | `harness/wp-harness test` |
| Plugin Check (wordpress.org rules) | `harness/wp-harness check` |
| Screenshots and browser scripts | `harness/wp-harness shot <path>`, `harness/wp-harness browse tests/browser-*.mjs` |
| Delete the test site | `harness/wp-harness down` |
| What ships | `git archive HEAD \| tar -t` (`.gitattributes` `export-ignore` decides) |

## Hosting and deploys

- **Host:** the wordpress.org plugin directory (SVN), and the project site on GitHub Pages. Company skill: `wordpress-plugin-release`.
- **How a change reaches users:** merging to `main` changes nothing for users. A release is a version bump PR, then `bin/svn-release.sh --dry-run`, then the founder publishes with `bin/svn-release.sh --password-file <file>`. The Site workflow republishes `site/` and its zip on merges that touch the plugin or site.
- **Rollback:** release a new patch version with the fix or the revert. wordpress.org serves the version in `Stable tag`, so an older tag can also be made stable again by changing `Stable tag` in a release.
- **Where secrets live:** the SVN password is in the founder's own password file, never in the repo (`.gitignore` covers it) or in CI.

## Rules every role keeps

- Never delete a merchant's products or data. Conversions hide originals and must stay undoable.
- Every admin action checks `manage_woocommerce` and a nonce; input is sanitized and output escaped. Plugin Check must pass.
- No remote calls, tracking or analytics from the plugin.
- Keep PHP 7.4 and WordPress 6.5 compatibility unless a release raises the minimums on purpose.
- Only plugin files ship; tests, harness, site and listing assets are `export-ignore`.

## Deploy gate

- `harness/wp-harness lint`, `harness/wp-harness test` and `harness/wp-harness check` pass on the exact commit, with results posted on the PR. (There is no CI test workflow yet; adding one with the `ci-github-actions` skill is planned.)
- `qa-engineer` has verified the change, including the browser checks for admin UI changes.
- `security-compliance` has reviewed changes to capabilities, nonces, AJAX or REST handlers, redirects, uninstall, or anything that writes products.
- No role approves its own work.

## What only the founder approves

- Spending money, publishing anything, and contacting anyone outside the company (including support forum replies)
- Every wordpress.org release (`svn-release.sh` without `--dry-run`) and listing changes
- Raising the minimum WordPress, WooCommerce or PHP versions

## Analytics

None in the plugin, and none may be added. The GitHub Pages site may use the `google-analytics` skill if the founder approves it.
