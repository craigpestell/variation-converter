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
