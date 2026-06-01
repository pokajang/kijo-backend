# Workload Daily Snapshot Operations

Daily workload snapshots are forward-only. The scheduler captures the current day's workload at 23:55 and the check command verifies the prior day's capture at 00:30. Missing captures create System Admin notifications, but they are not auto-created.

Current commands:

```bash
php artisan workload:capture-daily {--date=} {--force}
php artisan workload:capture-daily --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --repair-only
php artisan workload:check-daily-capture {--date=}
php artisan workload:prune-snapshot-payloads {--older-than-days=180}
```

The payload prune command only removes large evidence JSON from old rows. It keeps score, task counts, score breakdown JSON, and work type breakdown JSON.

Limited repair replay is CLI-only, capped at 31 calendar days, cannot target future dates, skips existing snapshots, disallows `--force`, and writes `capture_mode = reconstructed` on replayed aggregate rows. It is not scheduled automatically and is intended only to bootstrap or repair the recent graph window.
