# Legacy Schema Sync Disabled

This folder is retained only as historical reference while the project finishes moving to Laravel migrations.

Do not add new schema-sync scripts here. New database changes must be implemented in `database/migrations` and applied with:

```bash
php artisan migrate
```

The shared schema-sync helper throws immediately if loaded so the old browser/manual runner cannot be accidentally revived.
