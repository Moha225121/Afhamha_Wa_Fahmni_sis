# Recovering missing library PDFs

The catalog can still list books after their stored PDF files are lost. The
reader, inline file and download routes correctly return 404 when those bytes
are absent. Do not loosen student visibility checks or change page layouts.

Run the read-only file audit from the deployed application directory:

```bash
php artisan library:verify-files
```

Restore missing files from a trusted directory containing the original source
tree, preserving the catalog's `source_path` layout:

```bash
php artisan library:verify-files --restore --source=/var/lib/nds/library-originals
```

Recovery verifies the recorded file size and SHA-256 fingerprint before copying,
then verifies the temporary copy before atomically placing it on the recorded
disk. It does not edit book records, student grades, visibility or assignments.
Existing damaged files and missing originals produce a failed audit and require
investigation. Restored PDFs remain behind the same authenticated routes.

When there is insufficient space for another full copy, use `--hard-link` with
immutable originals on the same filesystem. This creates regular private files,
not symlinks or public URLs, without duplicating PDF bytes. Keep these originals
read-only: a hard link shares file contents and is not an independent backup.
The deployment supports this mode with `LIBRARY_RECOVERY_HARD_LINK=1`.

The NDS GitHub deployment script runs this audit before announcing deployment
success. Supply `LIBRARY_RECOVERY_SOURCE` to select the retained originals folder.
For the original NDS import it also recognizes existing
`/tmp/school-library-import-*/libyan_books` folders. Retain originals outside `/tmp`
for long-term recovery and keep off-server backups of the database and storage.

Ensure the scheduled deploy script is executable, or invoke it explicitly through
`bash`. A GitHub push cannot deploy while the cron job reports `Permission denied`.
