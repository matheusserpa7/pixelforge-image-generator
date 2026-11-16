---
name: sdd
description: >-
  Runs the spec-driven workflow of pixelforge-studio: writes prd.md,
  tech-spec.md and tasks.md, stops for approval and only then orchestrates the
  implementation through the implementador and revisor agents, closing with the
  project documentation. Use when planning or implementing a change of
  behaviour, schema, permission, money or billing ledger, webhooks or
  generation status, or provider calls, and when the user asks for a spec, PRD,
  tech spec, tasks or SDD.
---

# SDD

The law is in `.claude/rules/sdd.md`. This skill is the procedure. Do not reinterpret the law.

Specs with status `implemented` are not a queue. The **Open questions** section of a PRD is not a task.

The spec session runs on Opus, medium effort. If it is on another model, say so in one line and carry on.

Committed text is in English; talk to the maintainer in Portuguese.

## Before writing

Read `docs/product/overview.md`, the fixed decisions in `CLAUDE.md` and the ADRs the change touches (`docs/adr/`). Read only the code the change touches; the wide exploration is left to the implementer, who will read the area anyway.

One folder per change: `docs/specs/NNN-name/`, next number, kebab-case, English, matching the row in `docs/roadmap.md`.

The three files share one status: `draft`, `approved` or `implemented`.

## Skip or spec

Apply the three conditions of the law. If all three hold, say so in one sentence and implement directly, in this session. If one fails, open the folder.

## Draft

Write the three files with status `draft`. A decision that will need an ADR is proposed in the tech spec. Do not edit `docs/product/`, `docs/adr/` or `CLAUDE.md` at this stage. No code and no migration.

Stop. Show the three paths. Wait for approval of that text. A request for changes keeps `draft`. "Approve and execute" in the initial request is not approval.

### prd.md

Problem, who uses it, what is in, what is out, acceptance. No file, SQL, class or module.

```markdown
# NNN Name: PRD

Status: **draft** · <date>

## Problem

## Users

## In scope

## Out of scope

## Acceptance

## Open questions
```

### tech-spec.md

How: modules, model, contracts, risk. Write for an implementer who was not in the conversation: whatever they would need to ask must be answered here. Every question that comes back costs one agent round.

```markdown
# NNN Name: tech spec

Status: **draft** · <date> · PRD: [prd.md](prd.md)

## Technical context

## Model and contracts

## Behaviour in code

## Tests

## Risks
```

### tasks.md

Ordered, testable slices. Each task is one implementer call, almost always one reviewer call, and one Conventional Commit, and each agent rereads the spec when it starts. So put in the same task what belongs to the same area and is tested together; split what has a different risk. Three well-cut tasks are cheaper and better than eight tiny ones.

Each task points to the tech spec section and names the reviewer:

- **Opus** when it touches money and the billing ledger; webhooks and generation status; provider calls (idempotency, rate limiter, retries); authorization and database schema; or any rule for reading and writing data.
- **Sonnet** when it is only visual interface, copy or tests.
- **none** when it is only a document, or a mechanical change the bar proves entirely (rename, move a file).

When in doubt, Opus. The user approves this choice together with the tasks.

```markdown
# NNN Name: tasks

Status: **draft** · <date> · Tech spec: [tech-spec.md](tech-spec.md)

- [ ] **T1. <name>.** <slice>. Tech spec: <section>. Reviewer: Opus.
```

## After approval

Mark the three files `approved`. The main session orchestrates; it does not implement and does not reread each task's diff (that is the reviewer's job, and reading twice is paying twice).

Make sure the stack is up (`make up`) and the working tree is clean. Then, for each task, in order:

1. Call `implementador` with the spec folder name and the task number. Nothing else; it reads the spec by itself.
2. If it stopped with a question, take it to the user. The answer goes into the spec before calling again.
3. If the task says "Reviewer: none", go to step 6.
4. Call `revisor` with the spec folder name, the task number and the **Bar** line of the implementer's report. If the task says "Reviewer: Sonnet", pass `model: "sonnet"` in the call.
5. By verdict:
   - **RETURN**: send the findings to the same implementer (continue the agent that already has the context, if the tool allows, instead of opening another) and then call the reviewer again. On the second return of the same task, stop and take it to the user.
   - **QUESTION FOR THE USER**: as in step 2.
   - **APPROVED** or **APPROVED WITH FIXES**: carry on.
6. Check that the task is `[x]`, commit it (`git add -A` and one Conventional Commit, no AI attribution trailer) so the next diff shows only the next task, and tell the user in one line what closed.

One agent at a time, never two in the same worktree. Two specs in parallel: one session per spec, each in its own worktree.

### Bar

The implementer runs the filtered test while working and the full bar (`make lint analyse test`) once, when closing the task.

The reviewer does not run the bar; it only retests what it changed itself. Never two bars at once in the same worktree.

Tests run only inside the `app` container, through `make` or `docker compose exec -T app ...`: the host has no PHP and cannot reach MySQL. Never `make fresh`, `php artisan migrate:fresh` or a `DB_DATABASE` override around tests: Pest already uses the separate `testing` database, and anything else wipes the dev database.

## Last task

The code part goes to `implementador` like the others. Then the main session updates only the documents whose content changed:

1. A durable decision becomes an ADR in `docs/adr/`, next number, same format as the others (context, decision, consequences), listed in `docs/adr/README.md` and pointing to the spec folder. A new fixed decision also gets its line in `CLAUDE.md`.
2. A product delta goes into `docs/product/overview.md`.
3. The spec's row in `docs/roadmap.md` becomes `Done` and the next one `Next`; the status note in `README.md` follows if it no longer holds.

Run the full bar again only if some code changed after the last green full bar (a reviewer fix). Documents do not ask for a new run. Mark the three files `implemented` and commit the closing as `docs:`.

No push or PR unless explicitly asked.
