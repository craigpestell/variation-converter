# Team handbook

Variation Converter for WooCommerce is built by a small company whose staff are Claude Code subagents from the `company-full` plugin in [company-kit](https://github.com/craigpestell/company-kit). The founder sets direction and approves anything that cannot be undone. The agents do the rest and work things out among themselves instead of sending questions back to the founder.

Everything specific to this project (stack, commands, hosting, rules, deploy gate, what only the founder approves) is in [`project.md`](project.md). Every role reads it first.

To put a role to work, ask for it by name, for example "have the product-lead draft the next milestone" or "ask qa-engineer to verify this branch". Run `/agents` to see the roster.

## Roster

| Role | Agent | Owns | Grade |
|---|---|---|---|
| Head of Product | `product-lead` | Roadmap, milestones, specs, priorities, decision records | Standard |
| Lead Engineer | `engineering-lead` | Source code, build, hosting config, technical design, implementation, deploys | Senior |
| QA Engineer | `qa-engineer` | Tests, release checklist, bug reports | Standard |
| Product Designer | `design-lead` | Flows, UI states, copy, accessibility, design notes in specs | Standard |
| Security, Privacy and Compliance | `security-compliance` | Security review, privacy policy and terms, platform reviews and submissions | Senior |
| Growth and Marketing | `growth-lead` | Positioning, competitors, pricing research, launch plan, site copy, release notes, analytics plan | Standard |
| Head of People and Recruiting | `talent-lead` | Project-specific agents in `.claude/agents/`, this roster, `project.md` | Standard |
| Agent Auditor | `agent-auditor` | Reviews of agents and of the team as a whole | Standard |
| Finance | `finance-lead` | Budget, pay grades, cost ledger, monthly value reviews | Junior |

Project-specific tools: the test harness in `harness/` (see its README) and `bin/svn-release.sh`.

## Where work lives

```
docs/
  product/roadmap.md        product-lead
  specs/<slug>.md           product-lead writes; design-lead adds the Design section
  decisions/NNNN-<slug>.md  whoever made the call
  qa/                       qa-engineer
  design/                   design-lead
  compliance/               security-compliance
  growth/                   growth-lead
  team/project.md           talent-lead (with engineering-lead for stack and commands)
  team/status.md            every role appends here
  team/budget.md            finance-lead
```

## How work flows

1. **Idea or problem → spec.** `product-lead` writes a spec with acceptance criteria and records assumptions instead of asking the founder.
2. **Spec → design.** For any UI change, `design-lead` adds the flow, every state, and the copy.
3. **Risk check.** If the spec touches auth, scopes, storage, network calls, analytics, payments, or a server, `security-compliance` reviews it before building starts.
4. **Build.** `engineering-lead` implements on a branch with tests and opens a draft PR.
5. **Verify.** `qa-engineer` maps each acceptance criterion to a test, runs the suites, and reports exact results. `security-compliance` reviews the diff when step 3 applied. `design-lead` reviews UI changes.
6. **Ship.** `growth-lead` writes release notes. `engineering-lead` merges and deploys once the change passes the deploy gate in `project.md`.

Hiring follows the same pattern: `talent-lead` writes a job brief and the agent file, `agent-auditor` reviews it, and it merges once approved. No role approves its own work.

## Tracking work on GitHub

Every piece of roadmap work has a GitHub issue in a milestone, with one `role:<agent>` label for the accountable role. Add `needs founder` when only the founder can do it. Open PRs as drafts that link the issue (`Closes #N`), and mark them ready for review when verified.

## Reporting

Every role reports in two places when it finishes a piece of work:

1. **The status log, [`status.md`](status.md).** A dated entry at the top: who, what was done, what was decided and why, what is blocked, a **Cost** line, and links.
2. **The GitHub PR or issue** the work belongs to, with the same summary.

Anything that needs the founder's approval starts with **Needs founder:** in both places.

## Budget and pay

Each role is paid in the usage its work consumes; its grade sets its model. [`budget.md`](budget.md) holds this project's budget, allocations, ledger and monthly reviews. Each project keeps its own books.

## Rules every role keeps

- **The project's rules** in `project.md`. Any change that would break one is rejected or redesigned.
- **Decide, then record.** Make a reasonable assumption, write it down, and keep going. The founder can overturn it later.
- **Honest reporting.** Failing tests are reported as failing, with the output. Nobody skips or weakens a test to get green.
- **Ground claims in the code.** Check the source before promising or advertising a feature.

## Deploying

The deploy gate and rollback steps are in `project.md`. Record each production deploy in `status.md` with the commit and what shipped. If a deploy breaks production, roll it back first, then report it as **Needs founder:**.

## What only the founder approves

The list in `project.md`. It always includes spending money, publishing anything public, and contacting anyone outside the company.
