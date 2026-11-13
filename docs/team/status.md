# Team status log

Every role adds an entry here when it finishes a piece of work. Newest first. Anything that needs the founder's approval starts with **Needs founder:**.

Entry format:

```
## YYYY-MM-DD · <agent> · <short title>
- Did: what was done
- Decided: calls made and assumptions taken, with the reason
- Blocked: what is stuck and on whom, or "nothing"
- Cost: tokens and $ at the role's grade price, or an estimate marked est.
- Links: PR, issue, or files
```

---

## 2026-11-13 · engineering-lead · Admin page rebuilt as a React app
- Did: the founder asked for React components in place of PHP templates, on every page, with a place to see the components. Products > Variation Converter is now one React app (`src/admin`) on WordPress's components plus 22 of our own (`src/components`), talking to new REST routes (`includes/rest*.php`, namespace `varconv/v1`) that wrap the same functions as before. The PHP templates, `admin-post`/`admin-ajax` handlers and `assets/admin.js`/`admin.css` are gone. A Storybook style guide has a story for every component and is published with the website at `/components/`. Browser tests rewritten (`tests/browser-{combine,search,split,history,links}.mjs`).
- Decided: Storybook over a page in wp-admin, so components can be seen without a WordPress site, and it costs the plugin zip nothing. Our own list table instead of DataViews, which would have to be bundled, has no rows that open, and is not in WordPress 6.5. `build/` is committed because releases are `git archive` and the dev site pulls `main` with no build step. Hooks into WordPress's own screens stay PHP. URLs are unchanged, so links from the Products list and bookmarks still work. The page now needs JavaScript (the old no-JavaScript fallback is gone).
- Found on the way: WooCommerce refuses to create a product over REST while another holds its SKU (a guard for its own API). Conversions move SKUs under the bulk lock, so the plugin's routes turn that guard off for themselves. WordPress 6.5 has no `react-jsx-runtime` script; `varconv_jsx_runtime()` makes one there.
- Blocked: nothing. Needs qa-engineer and security-compliance review (new REST routes that write products). Listing screenshots, website images and the demo clip show the old page and should be retaken at the next release.
- Cost: est. 900k tokens.
- Links: this PR.

## 2026-11-03 · talent-lead · Refresh the project profile
- Did: brought `project.md` up to date: current version, the founder's standing rules (no parent SKU, American English, never touch the live Ultralove store), the working threads mapped onto the roster, the merge policy, and a proportionate deploy gate (php -l and offline tests for every PR; harness, Plugin Check and screenshots for larger UI changes and releases).
- Decided: wrote down how the project already runs rather than changing it.
- Blocked: **Needs founder:** set the monthly budget in `budget.md`. Fixed: the company agents weren't loading in cloud sessions because the private company-kit repo wasn't in the project's repositories, so the marketplace clone at session start failed. company-kit is now a project repository; new threads install the plugins from `.claude/settings.json` (checked with `claude plugin install company-full@company-kit`).
- Cost: est. 15k tokens, Standard.
- Links: this PR.

## 2026-10-30 · engineering-lead · Combine groups into one product with two attributes
- Did: new Combine bulk action, "Combine groups into one product". Two or more groups become one variable product with two attributes (one from the groups, the groups' own), one variation per product; a grid on the card shows the pairs and which are not sold. `varconv_grid()`/`varconv_validate_grid()` in scan.php, `varconv_create_grid()` over a shared `varconv_make()` in create.php. Offline tests, `tests/integration-grid.php`, `tests/browser-grid.mjs` (with `tests/seed-grid.php`).
- Decided: the founder picked the two-attribute grid over one longer single-attribute list. Pairs nobody sells get no variation (WooCommerce shows them as unavailable) rather than placeholder variations. At most 20 groups at once.
- Blocked: nothing. Not yet reviewed by qa-engineer or security-compliance (writes products; same nonce and capability checks as Create).
- Cost: est. 150k tokens.
- Links: PR to follow.

## 2026-10-29 · talent-lead (set up from company-kit) · Join the agent company
- Did: onboarded the repo to company-kit's Full team. Added `.claude/settings.json` (company-skills and company-full plugins), `CLAUDE.md`, `docs/team/project.md` (stack, harness commands, wordpress.org release, rules, deploy gate, founder-only list), this handbook, the status log and the budget.
- Decided: Full size. The deploy gate uses the harness commands, since the repo has no CI test workflow yet.
- Blocked: **Needs founder:** set the monthly budget in `budget.md`. Next work: add a CI workflow (lint, tests, Plugin Check) with the `ci-github-actions` skill.
- Cost: est. 30k tokens, Standard.
- Links: company-kit PR #1.
