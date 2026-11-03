# Prompts to paste into Codex

Use one Codex task per phase (or per 2–3 tasks for big phases like 1, 4, 5, 6).
Review and merge before starting the next prompt.

## First run (Phase 0)

```
Read AGENTS.md, docs/DOMAIN.md and docs/PLAN.md fully.
Implement Phase 0 (tasks T0.1 to T0.6) exactly as described in docs/PLAN.md.
Rules: follow AGENTS.md; one commit per task named "T0.x: ..."; do not start Phase 1.
Do not perform steps marked [AHMED]; list them at the end.
At the end, report: what you built per task, files changed, test suites you ran and their results,
suites you could not run and why, and any questions you added to docs/QUESTIONS.md.
```

## Any later phase (replace N and the task range)

```
Read AGENTS.md, docs/DOMAIN.md and docs/PLAN.md. Phase N-1 is merged.
Implement Phase N, tasks TN.1 to TN.k, in order, following AGENTS.md.
Business rules come only from docs/DOMAIN.md. If something is missing or contradictory,
write it in docs/QUESTIONS.md with your recommended default and stop if it is hard to change later.
Every new endpoint needs: permission declaration, isolation matrix fixture (cross-account 404),
a permission-denied API test, and i18n strings in ar + en.
One commit per task "TN.x: ...". Do not start Phase N+1.
Report: per-task summary, files, tests run + results, suites not run, open questions, [AHMED] steps.
```

## Review prompt (run after each phase, in a fresh Codex task)

```
Review the changes of Phase N against AGENTS.md and docs/DOMAIN.md. Do not change code.
Check specifically: account isolation (every query scoped, no account_id from input, 404 for foreign ids),
authorization pipeline on every route, financial invariants and locking order, money never float,
dates via Clock/LocalDate, no WordPress calls inside financial transactions, no $wpdb outside Infrastructure,
cross-module access only via Api, tests present for each acceptance criterion.
Output a list of findings: file:line, rule violated, severity (blocker/major/minor), suggested fix.
```

## Fix prompt

```
Fix the following review findings for Phase N without changing unrelated code. Add a regression test for each blocker.
<paste findings>
```
