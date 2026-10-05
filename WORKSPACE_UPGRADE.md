# Programme workspace redesign

## What changes

- Responsive light workspace with forest-green navigation, shared branding and a dedicated sign-in screen.
- Project picker; overall progress, scheduled activities/operatives today and overdue-work summaries.
- Native timeline, full task register (including unscheduled work) and a lookahead planner.
- Search and filters for blocks, floors, contractors and status, populated from the project data.
- Adjustable date window and timeline density; programme-start shortcut for historical imports.
- Task editing for activity name, contractor, operatives, working-day duration, zone, progress and start constraint.
- Desktop drag/move and right-edge resize, with a confirmation dialog before applying changes. On touch devices, use the task editor.
- Baseline overlays, milestone labels and scheduling alerts in the activity details.
- CSV, task-list Excel, window-based lookahead Excel/PDF and print actions. Exports cover the selected project; the date-window exports include all matching scheduled work in that window, not the UI search/status filters.
- Workforce, tight-handover, throughput and baseline-variance reports with data tables and per-report error states. Charts no longer require a CDN. Long programmes aggregate chart buckets by peak; tables retain every value.
- Consistent navigation and styling around existing imports, baseline controls, calendars, templates and user management. New `/admin/index.php` resolves previously broken admin landing links.

## Compatibility and scheduling

PHP/MySQL hosting and the existing project data, import/export endpoints, permissions, calendar and dependency scheduler are retained. No database migration or Node build is needed.

The new `/api/workspace.php` reads the existing schema and derives status in the project's timezone. "Scheduled today" is inferred from planned dates, not confirmation that work has started. Average progress is an unweighted average of activity completion percentages. Planned operatives are the sum of activity allocations, not unique headcount.

Updates still use `/api/tasks.php?action=update` and CSRF/role checks. Progress is now editable; empty contractor/constraint values can clear those fields. Edits and scheduling recalculation use a database transaction so a failed recalculation rolls everything back. Resizing now derives duration using the same working-day interval convention as `Scheduler::recalc()`, eliminating the previous extra-day mismatch. Dependencies, weekends and holidays can move the final dates from the proposed drag dates.

The existing scheduling engine and its finish-date convention are retained. No imported data is automatically cleaned or rewritten by loading the workspace.

## Deploy on Plesk

1. Back up the current application and database using your usual hosting process.
2. Deploy the repository changes, including `api/workspace.php`, `admin/index.php`, `assets/css/`, `assets/js/{workspace,analytics,chrome}.js` and `assets/programme-mark.svg`.
3. Keep the existing private database configuration and upload directories. This change does not need new credentials.
4. Open `/index.html` and `/lookahead.html`. Check the selected project, historical date window and reports.
5. Sign in as a planner/admin. Confirm progress editing and a schedule change against your live project; check imports, export generation, baselines and calendar exceptions.
6. Confirm responsive layouts on an actual phone and desktop before rolling the changes into general use.

## Verification

- PHP lint passed for changed PHP code.
- JavaScript syntax checks passed.
- `tests/schedule_regression.py`: PHP endpoint logic against an isolated SQLite fixture; progress, project reads, holidays/weekends, dependent dates, clearing fields, bad dates/values, CSRF, read-only roles and rollback on a dependency cycle.
- `tests/workspace_dom.cjs`: simulated DOM interactions for the timeline, list, search, editing, overdue filters, baselines, confirmed moves, focus exit, mobile-navigation state, lookahead, read-only views, retry states and analytics.
- Live public page and existing Gantt API responded during inspection. No production writes were performed.
- A full browser layout test could not run in the execution environment. DOM checks do not verify browser rendering, pointer capture or physical mobile behavior. PHP regression uses SQLite as a test double, not the production MySQL database. Production import/export dependencies were retained rather than exercised through a live write.

Run the regression with PHP CLI and PDO SQLite installed:

```sh
python3 tests/schedule_regression.py
```

Run the DOM test with `jsdom` resolvable in Node (or set `PROGRAMME_TEST_JSDOM` to its installed path):

```sh
node tests/workspace_dom.cjs
```
