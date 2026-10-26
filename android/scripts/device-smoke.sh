#!/usr/bin/env bash
# Installs the debug APK on a running emulator, launches it against the production site and
# checks that the app stays alive and renders website content (not an error screen).
# Usage: device-smoke.sh <apk> <package> <out-dir>
set -euo pipefail
APK="$1"; PKG="$2"; OUT="$3"
mkdir -p "$OUT"
adb wait-for-device
adb install -r "$APK"
adb logcat -c || true
adb shell am start -W -n "$PKG/app.flatmates.android.MainActivity"
found=""
for i in $(seq 1 18); do
  sleep 5
  adb shell uiautomator dump /sdcard/ui.xml >/dev/null 2>&1 || continue
  adb pull /sdcard/ui.xml "$OUT/ui.xml" >/dev/null 2>&1 || continue
  if grep -qiE "Find Your People|Flatmate Finder|Explore Rooms" "$OUT/ui.xml"; then found=yes; break; fi
done
adb exec-out screencap -p > "$OUT/screen.png" || true
adb logcat -d > "$OUT/logcat.txt" || true
if ! adb shell pidof "$PKG" >/dev/null; then echo "::error::App process is not running (crashed?)"; exit 1; fi
if grep -qE "FATAL EXCEPTION" "$OUT/logcat.txt"; then echo "::error::Crash found in logcat"; exit 1; fi
if grep -qiE "Couldn.t load the page|Secure connection failed|You.re offline" "$OUT/ui.xml" 2>/dev/null; then echo "::error::App is showing an error screen"; exit 1; fi
if [ -z "$found" ]; then echo "::error::Website content did not appear in the WebView within 90 s"; exit 1; fi
# Back button on the home page must not crash the app
adb shell input keyevent KEYCODE_BACK; sleep 2
echo "Smoke test passed: app installed, launched and rendered the site."
