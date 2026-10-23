# Upgrading Bharat SEO

1. **Back up first.** Take a database backup (`php bin/backup.php` or cPanel Backup) and save a copy of the private config file.
2. **Pause automation.** Go to *Cron & queue → Pause all jobs*, or disable the cron entries temporarily.
3. **Upload the new release** over the existing folder. Keep:
   - the private config file (outside the app folder),
   - `storage/` (logs, uploads, `installed.lock`).

   Delete `public/install.php` again if it was re-uploaded. The installer stays locked either way.
4. **Apply database migrations.** Run them from the CLI:
   ```
   php bin/migrate.php
   ```
   Or use *Install & upgrade → Apply pending*. Migrations are `database/migrations/NNN_description.sql` files, and each applied version is recorded in `schema_migrations`.
5. **Check the result.** *Install & upgrade* should list every migration as applied. Then click *Verify integrity* on the audit log page.
6. **Resume automation** by un-pausing the queue or re-enabling cron. Then confirm on *Cron & queue health* that the scripts run again.

## Writing a migration (developers)
- Use the next number, for example `database/migrations/002_add_reply_notes.sql`.
- Make statements tolerant of re-runs where possible, e.g. `CREATE TABLE IF NOT EXISTS`, or check `information_schema` first.
- Separate statements with `;` at the end of a line. Lines starting with `--` are comments.
- Also update `database/schema.sql`, so that fresh installs get the same final structure, and add an `INSERT IGNORE INTO schema_migrations` line for the new version.

## Rolling back
Restore the database backup from step 1 and re-upload the previous release:
```
php bin/backup.php --restore=storage/backups/FILE.sql.gz --yes
```

## Reinstalling from scratch (destroys data)
1. Back up.
2. Drop all tables or use a new empty database.
3. Delete the private config file and `storage/installed.lock`.
4. Upload `public/install.php` and run it again.
