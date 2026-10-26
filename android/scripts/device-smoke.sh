#!/usr/bin/env bash
# Installs the debug APK on a running emulator, launches it against the production site and
# checks that the real site loads in the WebView (debug builds log page-load events — host, path
# and page title only) and that the app neither crashes nor shows an error screen.
# Usage: device-smoke.sh <apk> <package> <expected-host> <out-dir>
set -uo pipefail
APK="$1"; PKG="$2"; HOST="$3"; OUT="$4"
mkdir -p "$OUT"
adb wait-for-device
adb install -r "$APK" || { echo "::error::adb install failed"; exit 1; }
adb logcat -c || true
adb shell am start -W -n "$PKG/app.flatmates.android.MainActivity"
ok=""
for i in $(seq 1 24); do
  sleep 5
  adb logcat -d -s FlatmatesApp:* > "$OUT/app-log.txt" 2>/dev/null || true
  if grep -q "page_finished host=$HOST" "$OUT/app-log.txt"; then ok=yes; break; fi
  if grep -qE "main_frame_error|ssl_error" "$OUT/app-log.txt"; then break; fi
done
adb exec-out screencap -p > "$OUT/screen.png" || true
adb logcat -d > "$OUT/logcat.txt" || true
echo "----- app log -----"; cat "$OUT/app-log.txt" || true; echo "-------------------"
fail=""
if ! adb shell pidof "$PKG" > /dev/null; then echo "::error::App process is not running (crashed?)"; fail=yes; fi
if grep -q "FATAL EXCEPTION" "$OUT/logcat.txt"; then echo "::error::Crash found in logcat"; grep -A15 "FATAL EXCEPTION" "$OUT/logcat.txt" | head -30; fail=yes; fi
if grep -qE "main_frame_error|ssl_error" "$OUT/app-log.txt"; then echo "::error::The WebView reported a loading error"; fail=yes; fi
if [ -z "$ok" ]; then echo "::error::https://$HOST/ did not finish loading within 120 s"; fail=yes; fi
[ -n "$fail" ] && exit 1
# Navigate inside the app (exercises the URL policy) and press Back
adb shell am start -W -a android.intent.action.VIEW -d "https://$HOST/rooms" "$PKG" > /dev/null
sleep 8
adb logcat -d -s FlatmatesApp:* > "$OUT/app-log.txt"
grep -q "page_finished host=$HOST path=/rooms" "$OUT/app-log.txt" || { echo "::error::Deep link to /rooms did not load"; cat "$OUT/app-log.txt"; exit 1; }
adb shell input keyevent KEYCODE_BACK; sleep 3
adb shell pidof "$PKG" > /dev/null || { echo "::error::App died after Back"; exit 1; }
echo "Smoke test passed: installed, launched, loaded https://$HOST/, deep link + Back OK."
