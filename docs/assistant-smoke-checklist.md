# Learn Kijo AI Assistant Smoke Checklist

Run after assistant migrations and before calling the assistant production-ready.

## Service-Layer Smoke

Run these from `backend-laravel`:

```bash
php artisan assistant:smoke --role=system-admin --dry-run
php artisan assistant:smoke --role=manager --dry-run
php artisan assistant:smoke --role=hr --dry-run
php artisan assistant:smoke --role=finance --dry-run
php artisan assistant:smoke --role=sales --dry-run
php artisan assistant:smoke --role=staff --dry-run
```

Check each output row for:

- expected `provider_keys`
- expected `source_types`
- no unexpected restricted-data warning
- `context_quality` is `complete` or explainable as `partial`
- no high-value scenario has `source_count: 0` without a logged source gap.

## Browser Smoke

Use the account guidance in the root `SMOKE.md`.

- Ask a Knowledge workflow question.
- Ask a Handbook policy question.
- Ask a dashboard metric question.
- Ask about project status.
- Ask who the top returning client is now.
- Ask about unpaid invoices and debtors.
- Ask about vendor registration expiry.
- Ask leave/task questions as normal Staff and as Manager/HR.
- Submit one helpful and one bad feedback response.
- Confirm System Admin can see the feedback and source gaps in AI Assistant Governance.
- Promote one source gap to provider backlog.
- Create one Knowledge draft from a source gap and confirm it remains unpublished.
