# Android app

`android/` is a Kotlin Android Studio project: a hardened WebView shell around the production website. The website stays the single source of truth for login, sessions, matching, chat and permissions — the app has no separate backend, database or login.

| | |
|---|---|
| Application ID | `in.noidarankers.flatmates` (debug builds: `…flatmates.debug`) |
| Production URL | `https://flatmates.noidarankers.in/` |
| Min / target SDK | 24 (Android 7.0) / 35 |
| Toolchain | JDK 17, Gradle 8.11.1 (wrapper, checksum-pinned), AGP 8.7.3, Kotlin 2.0.21 |

## Configuration

Set in `android/gradle.properties` or override per build with `-P`:

```
flatmates.baseUrl=https://flatmates.noidarankers.in/     # must be https:// and end with /
flatmates.applicationId=in.noidarankers.flatmates         # never change after the first release
flatmates.versionName=1.0.0                              # CI sets this from the release tag
flatmates.versionCode=1                                  # CI sets this (X*1000000 + Y*1000 + Z)
```

The build fails if the URL isn't HTTPS or still contains a placeholder domain (`YOUR-DOMAIN`, `*.example`). No credentials, API keys or admin details are ever compiled into the APK.

## What the app does

- Loads only `https://<baseUrl host>` pages inside the WebView. Plain-HTTP links to that host are upgraded to HTTPS; links to other sites, `mailto:` and `tel:` open in the system browser/app; `javascript:`, `intent:`, `file:`, `content:`, `data:`, user-info tricks (`https://host@evil`) and non-standard ports are blocked (`UrlPolicy.kt`, unit tested).
- TLS: `onReceivedSslError` always cancels — there is no way to "proceed anyway". `network_security_config.xml` disables cleartext and trusts only system CAs.
- No `addJavascriptInterface`; file and content access off; mixed content never allowed; Safe Browsing on; third-party cookies off; WebView debugging only in debug builds.
- Session cookies are flushed on pause so login survives app restarts; backups/device transfer of app data are disabled so cookies never leave the device. Logging out on the website clears the session exactly as in a browser.
- File inputs (profile photo, listing photos, verification documents) open the system picker, including multi-select.
- Downloads on the trusted host (e.g. "Download my data") use DownloadManager with the session cookie — only for that host. Other downloads (e.g. the APK on GitHub) open in the browser.
- Native screens for offline, server error and certificate failure, with **Try again**.
- Back button walks WebView history, then exits. Branded splash, icon (adaptive + bitmap), status/navigation bars and edge-to-edge insets.
- Adds `FlatmatesAndroid/<version>` to the user agent so the website can hide "download the app" prompts inside the app.

## Android App Links

The manifest declares an `autoVerify` intent filter for the production host. Android only verifies it when `https://<host>/.well-known/assetlinks.json` lists the **release** signing certificate. The website serves that file automatically once **Admin → Settings → Android app → Release signing certificate SHA-256 fingerprint** is filled in (the release workflow prints the value). Until then links simply open in the browser. Debug builds use a different certificate and will not verify — that's expected.

## Build locally

```
cd android
./gradlew testDebugUnitTest lintDebug assembleDebug
# → app/build/outputs/apk/debug/app-debug.apk
```

Needs JDK 17 and an Android SDK with `platforms;android-35` and `build-tools;35.0.0` (set `ANDROID_HOME` or `sdk.dir` in `local.properties`, which is git-ignored).

## GitHub Actions

### `android-apk.yml` — debug APK (testing only)

Runs on **Run workflow**, on pushes to `main` / the Flatmates branches that touch `android/**`, and on pull requests (no secrets are available to it). Steps: wrapper validation → SDK install → unit tests + lint + `assembleDebug` → checks the APK exists and is signed → SHA-256 → uploads artifact **`student-flatmate-android-debug`** (`app-debug.apk` + `.sha256`). A second job installs the APK on an Android 14 emulator, launches it against the live site, and uploads a screenshot and logcat (`android-device-smoke`). Build logs and lint/test reports are uploaded even when the build fails.

> GitHub only shows **Run workflow** for workflows that exist on the repository's default branch. This repository's default branch is currently `feature/bharat-seo-platform`, so until the Flatmates branch is merged into the default branch (or the default branch is changed), runs start automatically from pushes instead.

### `android-release.yml` — signed release

Triggered by pushing a tag `flatmates-android-vX.Y.Z` (or **Run workflow** with a version, which creates that tag). It never runs for pull requests. It:

1. fails immediately if any signing secret is missing (nothing is built or published);
2. decodes the keystore into the runner's temp folder (deleted at the end);
3. runs unit tests and `assembleRelease` (minified, not debuggable);
4. verifies the APK exists, is v2/v3-signed, is **not** debug-signed or debuggable;
5. computes the APK SHA-256 and the certificate fingerprint;
6. creates the GitHub Release (refuses to overwrite an existing one) with the APK, a `.sha256` file and install notes.

The job uses the `android-release` **environment**. Create it once and protect it:

*Settings → Environments → New environment* → name `android-release` → **Deployment branches and tags: Selected** → add tag rule `flatmates-android-v*` → optionally **Required reviewers** (you), so every release waits for your approval.

### Create the signing key and secrets (one time)

```
keytool -genkeypair -keystore release.jks -storetype PKCS12 -alias flatmates \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -dname "CN=Student Flatmate Finder, O=NoidaRankers, L=Noida, ST=Uttar Pradesh, C=IN"
base64 -w0 release.jks > release.jks.base64       # macOS: base64 -i release.jks
```

Add these as **environment secrets** of `android-release` (Settings → Environments → android-release → Add secret), or as repository secrets:

| Secret | Value |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | contents of `release.jks.base64` |
| `ANDROID_KEYSTORE_PASSWORD` | keystore password |
| `ANDROID_KEY_ALIAS` | `flatmates` |
| `ANDROID_KEY_PASSWORD` | key password (same as the keystore password for PKCS12) |

Keep `release.jks` and its password in a password manager **and** an offline backup. If it is lost, existing installs can't be updated — users would have to uninstall and reinstall. Never commit it (`*.jks`, `*.keystore`, `*.p12` are git-ignored), never email or chat it, delete `release.jks.base64` after adding the secret.

## Publishing a release

1. Bump nothing by hand — the tag sets the version.
2. `git tag flatmates-android-v1.0.0 && git push origin flatmates-android-v1.0.0` (or Actions → Android release APK → Run workflow).
3. Approve the deployment if you added reviewers.
4. From the run summary copy the APK URL, SHA-256 and certificate fingerprint into **Admin → Settings → Android app** (also set version, date and `https://github.com/<owner>/<repo>/releases` as the releases page).
5. Open `/app` on the website and check the button, checksum and QR code.

Never attach `app-debug.apk` to a release.

## Installing on a phone

Download the APK from `/app` on the phone, open it, allow the browser to *install unknown apps* if Android asks (only for this install), tap **Install**. Updates from the same signed release channel install over the top and keep the user signed in.

## Troubleshooting

| Problem | Fix |
|---|---|
| `Missing Gradle property 'flatmates.baseUrl'` | Restore it in `android/gradle.properties`. |
| `flatmates.baseUrl still contains a placeholder` | Set the real production domain. |
| `SDK location not found` | Set `ANDROID_HOME` or create `android/local.properties` with `sdk.dir=…`. |
| Release run: "signing secrets missing" | Add the four secrets (see above) to the `android-release` environment. |
| Release run waits forever | It's waiting for an environment reviewer — approve it under the run. |
| "App not installed" on the phone | An older copy signed with a different key is installed (e.g. a debug build). Uninstall it first. |
| App shows "Secure connection failed" | The device can't validate the site certificate (often a captive Wi-Fi login page or an expired certificate). Renew the site's SSL certificate if it's expired. |
| App Links don't open in the app | Check `/.well-known/assetlinks.json` returns the release fingerprint, then on the phone: Settings → Apps → Flatmate Finder → Open by default. |
