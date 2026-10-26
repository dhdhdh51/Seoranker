# Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Students don't receive OTP emails | Admin → System health shows the last mail error. Check SMTP settings and that the server can make outbound connections (port 587/465). Queued mail is retried by `cron/mail.php`. |
| "Page not found" for every page except home | URL rewriting isn't active: make sure `public/.htaccess` is uploaded (Apache/LiteSpeed) or route all non-file requests to `public/index.php` (nginx). |
| Images upload but don't show | `storage/uploads` must be writable by the PHP user; photos are served through `/media/…`, not directly. |
| Old listings show no thumbnails | Thumbnails (`-sm.jpg`) are created for new uploads; older photos automatically fall back to the full image. |
| `Unknown column 'reviewed_at'` | Run `php bin/migrate.php` after updating the code. |
| Download button says "No public release yet" | Fill Admin → Settings → Android app with the URL from a published GitHub Release. |
| QR code missing | It's only shown when a download or releases URL is configured. |
| `/.well-known/assetlinks.json` returns 404 | Set the release certificate fingerprint in Admin → Settings → Android app. On Apache/LiteSpeed make sure the updated `public/.htaccess` (which allows that one dotfile path) is deployed. |
| Android build or release problems | See the table at the end of [ANDROID.md](ANDROID.md). |
