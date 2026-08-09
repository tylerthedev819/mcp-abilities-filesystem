# Filesystem safety boundaries

## Authorization

All eleven registered operations require an authenticated WordPress user with the `manage_options` capability.

## Path and read boundaries

- Every supplied path is resolved and limited to the current WordPress root before an operation proceeds.
- File reads reject the root `wp-config.php`, environment files, SSH key names, authorized key files, and common private key or certificate extensions.
- A file read defaults to 256 KB and accepts an explicit limit no larger than 1 MB.

## Write and destination checks

- File writes, appends, copies, and moves honor `DISALLOW_FILE_MODS`. The write guard also checks `DISALLOW_FILE_EDIT` for a PHP target.
- The write guard rejects every PHP-like target extension, dangerous executable or script extensions, suspicious filenames, PHP double extensions, file types WordPress does not allow, PHP signatures hidden in other file types, and unsafe root `.htaccess` directives.
- Write and append payloads are limited to 10 MB. Write and append also reject WordPress core files under `wp-admin` and `wp-includes`.
- Move rejects a source under `wp-admin` or `wp-includes`. File and directory deletion reject those WordPress core locations, and file deletion also rejects root `wp-config.php`, `.htaccess`, and `index.php`.

## Backup and destructive action boundaries

- Overwriting with write or append creates a backup by default. A caller can explicitly disable that backup.
- File deletion creates a backup by default. A caller can explicitly disable it, so the operation has no separate confirmation stage.
- Copying over an existing destination requires an explicit overwrite choice and backs up that destination. Moving always backs up the source and also backs up an existing destination before an explicitly allowed overwrite.
- Directory deletion does not create a backup. Recursive deletion is disabled by default and must be explicitly selected.
- Backup folders older than seven days are eligible for periodic cleanup.

## Operation audit

Successful write, append, file deletion, directory deletion, copy, and move operations append an audit entry with time, operation, path, WordPress user, client address, and supplied context. Entries also include the backup, destination, or size change when the operation provides it. The changelog operation returns recent entries for inspection.
