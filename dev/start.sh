#!/bin/sh
# Start the mock services and a test copy of the site (see dev/README.md).
# Needs: php (8.2+), python3 with pyftpdlib (pip install pyftpdlib), and a MariaDB/MySQL database
# named in dev/config.test.php (FW_DB_* environment variables). Stop everything with dev/stop.sh.
set -e
REPO="$(cd "$(dirname "$0")/.." && pwd)"
TMP="${FW_TEST_DIR:-${TMPDIR:-/tmp}/floodwatcher-test}"
export SFW_CONFIG="$REPO/dev/config.test.php" SFW_CODE_DIR="$REPO" FW_TEST_DIR="$TMP"
mkdir -p "$TMP/site/data" "$TMP/var" "$TMP/logs"
# The site: the page files linked from www/, the data folder the scripts write.
for f in "$REPO"/www/*; do ln -sfn "$f" "$TMP/site/$(basename "$f")"; done
start() { # port, command...
  port=$1; shift
  if ! (ss -ltn 2>/dev/null || netstat -ltn) | grep -q ":$port "; then
    nohup "$@" > "$TMP/logs/$port.log" 2>&1 &
    echo $! >> "$TMP/pids"
  fi
}
start 2121 python3 -m pyftpdlib -p 2121 -d "$REPO/dev/fixtures/bom"
start 8099 php -S 127.0.0.1:8099 "$REPO/dev/mock/bcc.php"
start 8101 php -S 127.0.0.1:8101 "$REPO/dev/mock/wmip.php"
start 8103 php -S 127.0.0.1:8103 "$REPO/dev/mock/arcgis.php"
start 8105 php -S 127.0.0.1:8105 "$REPO/dev/mock/push.php"
start 8106 php -S 127.0.0.1:8106 "$REPO/dev/mock/wu.php"
start 8102 php -S 127.0.0.1:8102 -t "$TMP/site"
sleep 1
echo "Site: http://127.0.0.1:8102/   Data: $TMP"
echo "Run scripts with: SFW_CONFIG=$SFW_CONFIG php bin/<script>.php"
