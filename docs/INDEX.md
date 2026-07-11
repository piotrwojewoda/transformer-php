# Documentation Index

The complete specification lives in [`SPECKIT.md`](../SPECKIT.md) at the repository root. This folder is a small set of focused companions for fast navigation.

## Files in this folder

| File | Purpose | When to read |
|---|---|---|
| [`QUICKSTART.md`](./QUICKSTART.md) | Five-minute "hello, world" walkthrough: bring up Docker, ingest a corpus, create a model, train, predict. | First time you run the project. |
| [`CHECKLIST.md`](./CHECKLIST.md) | One-line-per-task implementation checklist, grouped by phase. Tick boxes as you go. | During implementation. |
| [`ACCEPTANCE.md`](./ACCEPTANCE.md) | The exact list of things that must be true before the project is "done". | Before declaring a phase complete, before merging. |
| [`DECISIONS.md`](./DECISIONS.md) | A short log of every significant decision and the alternatives considered. | When a teammate asks "why did we do it this way?". |

## Reading order for an LLM implementing the project

1. [`../SPECKIT.md`](../SPECKIT.md) — read sections 1 to 7 (purpose, stack, architecture, contexts, layers, layout, domain).
2. [`../SPECKIT.md`](../SPECKIT.md) — read sections 8 to 12 (application, infrastructure, schema, messenger, transformer math).
3. [`../SPECKIT.md`](../SPECKIT.md) — read sections 13 to 16 (UI, docker, xdebug, testing).
4. [`../SPECKIT.md`](../SPECKIT.md) — read sections 17 to 22 (phases, acceptance, usage, decisions, open items, diagrams).
5. [`CHECKLIST.md`](./CHECKLIST.md) — use as the working plan.
6. [`ACCEPTANCE.md`](./ACCEPTANCE.md) — refer to before closing each phase.

## Reading order for a human developer

1. [`QUICKSTART.md`](./QUICKSTART.md) — run the app end-to-end in 5 minutes.
2. [`../SPECKIT.md`](../SPECKIT.md) — read top to bottom once. It is intentionally linear.
3. [`DECISIONS.md`](./DECISIONS.md) — skim for context on tradeoffs.
4. [`CHECKLIST.md`](./CHECKLIST.md) — use as the working plan.
5. [`ACCEPTANCE.md`](./ACCEPTANCE.md) — refer to before closing each phase.

## Pointers into `SPECKIT.md` (jump table)

| I want to know... | Section |
|---|---|
| What the project is and is not | §1 |
| Exact versions of every tool | §2 |
| The big picture of the architecture | §3, §22.1 |
| What bounded contexts exist | §4 |
| Which directory holds what | §5, §6 |
| What an aggregate root or value object looks like | §7 |
| What commands and queries exist | §8 |
| How Doctrine is wired | §9.1, §10 |
| How tokenization works | §9.2 |
| How the math is implemented | §9.3, §12 |
| How the SQL schema looks | §10 |
| How Messenger is configured | §11 |
| How to start a worker | §11.2 |
| How to ingest text via the UI | §13 |
| How to set up Docker | §14 |
| How to use Xdebug with VS Code | §15 |
| How tests are organized | §16.3 |
| What the mandatory tests are | §16.5 |
| What coverage targets apply | §16.8 |
| How to run the suite locally | §16.11 |
| What to build first | §17 |
| What "done" means | §18 |
| How to use the app | §19 |
| Why each decision was made | §20 |
| What is still open | §21 |
| Architecture diagrams | §22 |
