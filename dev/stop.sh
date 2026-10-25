#!/bin/sh
# Stop what dev/start.sh started.
TMP="${FW_TEST_DIR:-${TMPDIR:-/tmp}/floodwatcher-test}"
[ -f "$TMP/pids" ] && xargs kill < "$TMP/pids" 2>/dev/null; rm -f "$TMP/pids"
