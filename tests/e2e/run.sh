#!/usr/bin/env bash
# End-to-end tests: starts WordPress in Docker with Graph Mailer and a test
# double for Microsoft's endpoints, runs the mail cases and checks the settings
# screen, then removes the containers.
#   tests/e2e/run.sh          run and clean up
#   KEEP=1 tests/e2e/run.sh   leave the site running at http://localhost:8091
set -euo pipefail
cd "$(dirname "$0")"
export PLUGIN_DIR="${PLUGIN_DIR:-$(cd ../.. && pwd)}"
BASE=http://localhost:8091
compose() { docker compose -f compose.yml -p gm-e2e "$@"; }
wp() { compose run --rm -T cli "$@" </dev/null | tr -d '\r'; }

[ "${KEEP:-}" = 1 ] || trap 'compose down -v >/dev/null 2>&1' EXIT
compose up -d --quiet-pull wordpress
for i in $(seq 1 60); do
  curl -s -o /dev/null -w '%{http_code}' "$BASE/" | grep -qE '200|302' && break
  sleep 2
done
wp core is-installed 2>/dev/null || wp core install --url="$BASE" --title="Example Site" --admin_user=admin \
  --admin_password="$(head -c 24 /dev/urandom | base64)" --admin_email=admin@example.test --skip-email
wp plugin activate graph-mailer

status=0
compose run --rm -T cli eval-file wp-content/plugins/graph-mailer/tests/e2e/cases.php </dev/null | tr -d '\r' || status=1

echo
echo "== 8. Settings screen"
pass=0; fail=0
check() { if eval "$2"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1"; fail=$((fail+1)); fi; }
read -r HASH AUTH LOGGED < <(wp eval 'echo md5( get_option( "siteurl" ) ), " ", wp_generate_auth_cookie( 1, time() + 600, "auth" ), " ", wp_generate_auth_cookie( 1, time() + 600, "logged_in" ), "\n";')
COOKIE="wordpress_$HASH=$AUTH; wordpress_logged_in_$HASH=$LOGGED"
PAGE=$(curl -s -b "$COOKIE" "$BASE/wp-admin/options-general.php?page=graph-mailer")
check "the settings screen shows version 0.2.0" '[[ "$PAGE" == *">0.2.0</span>"* ]]'
check "the stored client secret never appears in the page" '[[ "$PAGE" != *"test-secret"* ]]'
check "the screen reports the configuration as complete" '[[ "$PAGE" == *"Configured"* && "$PAGE" == *"gm-ok"* ]]'
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE" -d 'action=graph_mailer_test' "$BASE/wp-admin/admin-post.php")
check "a test send without its nonce is refused" '[ "$CODE" = 403 ]'
LOGS=$(compose logs wordpress 2>&1 | grep -iE 'PHP (Warning|Notice|Fatal|Deprecated).*graph-mailer' || true)
check "no PHP warnings or notices from graph-mailer" '[ -z "$LOGS" ]'
[ -z "$LOGS" ] || echo "$LOGS"
echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ] || status=1
exit $status
