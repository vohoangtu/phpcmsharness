# Tuzygent Harness and Website Update Flow

The Harness does not edit the running website while an agent is working. Every
task follows this lifecycle:

1. Copy the managed application into `.harness/staging/` and run the selected
   generator or OpenAI-compatible API inside that isolated workspace.
2. Run the three verification gates against the staged files: PHP syntax,
   Hexagonal boundaries, and the integration suite.
3. When all gates pass, create a checksummed package under
   `.harness/updates/task-<id>/` and set the task to `ready_to_install`.
4. An authenticated administrator installs the package from `/admin/harness`.
   The installer snapshots the current website and SQLite database, enters
   maintenance mode, atomically replaces each file, checks installed checksums
   and PHP syntax, and restores the snapshot if installation fails. The full
   integration suite runs in staging so install verification does not mutate
   the live database.
5. A task becomes `completed` only after the website update passes its
   post-install checks. Agent/API errors are recorded as task failures and do
   not trigger a source rollback because staging never touched the website.

The package manifest contains the task ID, task code, file checksums, baseline
checksums for changed paths, and any deleted paths. Installation refuses a
stale package if a touched live file changed after staging. It only accepts files under `config/`, `src/`,
`public/`, and `tests/`, excluding the deployment-specific `config/app.php`
and `public/uploads/`. Uploads are preserved when restoring old snapshots too.
API credentials remain in `.harness/api_config.json` and are not included in
the staged package.

## Verification gates

- Gate 1 parses all managed PHP files with `TOKEN_PARSE`.
- Gate 2 checks that `src/Domain` stays free of infrastructure and HTTP
  dependencies.
- Gate 3 runs `tests/hexagon_test.php`, `tests/worker_queue_test.php`, and
  `tests/harness_snapshot_test.php`, `tests/harness_update_test.php`, and
  `tests/worker_concurrency_test.php` from the staging copy.

API context is selected by task relevance and capped before submission. An
upstream HTTP 5xx stops the current task attempt early; it is reported as an
agent/API failure, separate from gate results.

## Operation and recovery

Run `composer install` after checkout. The web and CLI processes need access
to the same SQLite database and write permission for `storage/` and `.harness/`.
Workers are launched by the dashboard and drain accepted jobs one at a time.
Their startup output is in `storage/workers/worker.log`; the Workers page shows
whether the worker lock is held. Retry failed jobs from that page after fixing
their reported cause. Disabling Workers rejects new jobs but drains queued jobs.

Continuous sends task work to Workers. A failed monitoring gate stops Continuous
and asks an administrator to review the available snapshots. Installation is
explicitly started from the Harness dashboard after staging passes its gates.
If installation is interrupted or recovery fails, maintenance stays enabled;
after checking the snapshot and database, run `php bin/tuzygent update:recover`.
The command restores the recorded snapshot before clearing maintenance.
Installation and database recovery currently support SQLite only.
