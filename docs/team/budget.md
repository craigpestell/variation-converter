# Budget and compensation

<!-- budget-summary: finance-lead updates these four lines whenever the ledger changes -->
```
Period:    2026-10 (month to date)
Budget:    not set
Spent:     $0.00
Remaining: not set
```

Every role is "paid" in the usage its work consumes. This file holds this project's budget, each role's pay grade and allocation, the cost ledger, and the monthly value reviews. `finance-lead` keeps it; `talent-lead` checks it before every hire. Each project in company-kit keeps its own books.

## Budget

**Not set yet.** The founder sets it; until then roles still record costs so the first monthly review has data. It is a notional budget used to measure and compare roles. Real money is only spent with the founder's approval.

## Pay grades

A role's grade sets the model it runs on. The `company-full` agents ship with these models; a project that needs a different grade for a role overrides it with an agent of the same name in `.claude/agents/`.

| Grade | Model | For | Price per million tokens, input / output |
|---|---|---|---|
| Senior | Opus | Production code, security and compliance | Check the Anthropic pricing page |
| Standard | Sonnet | Specs, tests, design, growth, hiring, audits | Check the Anthropic pricing page |
| Junior | Haiku | Bookkeeping and other routine work | Check the Anthropic pricing page |

Fill in the prices with the date you checked them.

## Allocations

| Role | Grade | Monthly allocation |
|---|---|---|
| `engineering-lead` | Senior | |
| `security-compliance` | Senior | |
| `product-lead` | Standard | |
| `qa-engineer` | Standard | |
| `design-lead` | Standard | |
| `growth-lead` | Standard | |
| `talent-lead` | Standard | |
| `agent-auditor` | Standard | |
| `finance-lead` | Junior | |
| Reserve for new hires and overruns | | |
| **Total** | | |

## Real expenses

Money actually paid (hosting, domains, services), kept apart from the notional budget. Each one needs the founder's approval.

| Since | Item | Price | Approved | Notes |
|---|---|---|---|---|

## Ledger

| Date | Role | Work | Tokens | Cost | Est.? |
|---|---|---|---|---|---|

## Monthly reviews
