# AGENTS.md

## Repo

- Small PHP 8.3 document-sharing app.
- SQLite database, seeded from `seed.php`.
- Docker Compose is the expected runtime; do not swap tooling without approval.
- Public routes live in `public/`; shared helpers live in `lib/`.
- Keep files under ~500 LOC; split when a file stops being easy to scan.

## Workflow

- Read the live path before editing.
- Always do red/green development style.
- Bugs: add a regression test when it fits.
- Follow DRY (Don't Repeat Yourself) when writing code of any type.
- Prefer small, reviewable changes over broad rewrites.
- No repo-wide search/replace scripts.
- If a function, method, or section has a comment, update the comment when behavior changes.

## Runtime

- Start app: `docker compose up`.
- App URL: `http://localhost:8000`.
- First run rebuilds the image and reseeds `db.sqlite`.
- Stop app: `Ctrl+C`.

## Tests

- Test command: `docker compose exec app php tests/test.php`.
- Run tests before every commit.
- For red/green work, run the targeted failing test first, then the full test command before handoff.

## Database

- Schema changes go through migration files added to the repo.
- Keep the fresh-clone `docker compose up` flow working.
- Log auditable business events to `audit_log` when those flows change.

## Docs

- Keep `README.md` current when changing setup, runtime, content models, routes, data fetching, docs layout, or gate commands.
- Prefer updating the existing compact sections over adding sprawling new docs.
- When an ADR-worthy decision is made, create a Markdown ADR in `docs/ADRs`.
- ADR filenames: concise and chronologically sortable, like `YYYY-MM-DD-short-title.md`.
- ADR content: status, context, decision, consequences.

## Git

- Safe commands by default: `git status`, `git diff`, `git log`.
- Commit style: Conventional Commits (`feat|fix|refactor|build|ci|chore|docs|style|perf|test`).
- Prefer `committer` for commits when available.
- Push only when asked.
- Do not amend, reset, clean, restore, or delete unexpected files unless explicitly requested.
