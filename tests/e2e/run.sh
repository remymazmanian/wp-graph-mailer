#!/usr/bin/env bash
# End-to-end tests: starts WordPress in Docker with Sendelva and a test
# double for Microsoft's endpoints, runs the mail cases and checks the settings
# screen, then removes the containers.
#   tests/e2e/run.sh          run and clean up
#   KEEP=1 tests/e2e/run.sh   leave the site running at http://localhost:8091
set -euo pipefail
cd "$(dirname "$0")"
export PLUGIN_DIR="${PLUGIN_DIR:-$(cd ../.. && pwd)}"
BASE=http://localhost:8091
compose() { docker compose -f compose.yml -p sendelva-e2e "$@"; }
wp() { compose run --rm -T cli "$@" </dev/null | tr -d '\r'; }

[ "${KEEP:-}" = 1 ] || trap 'compose down -v >/dev/null 2>&1' EXIT
compose up -d --quiet-pull wordpress
for i in $(seq 1 60); do
  curl -s -o /dev/null -w '%{http_code}' "$BASE/" | grep -qE '200|302' && break
  sleep 2
done
wp core is-installed 2>/dev/null || wp core install --url="$BASE" --title="Example Site" --admin_user=admin \
  --admin_password="$(head -c 24 /dev/urandom | base64)" --admin_email=admin@example.test --skip-email
wp plugin activate sendelva

status=0
compose run --rm -T cli eval-file wp-content/plugins/sendelva/tests/e2e/cases.php </dev/null | tr -d '\r' || status=1

echo
echo "== 10. Settings screen"
pass=0; fail=0
check() { if eval "$2"; then echo "PASS  $1"; pass=$((pass+1)); else echo "FAIL  $1"; fail=$((fail+1)); fi; }
read -r HASH AUTH LOGGED < <(wp eval 'echo md5( get_option( "siteurl" ) ), " ", wp_generate_auth_cookie( 1, time() + 600, "auth" ), " ", wp_generate_auth_cookie( 1, time() + 600, "logged_in" ), "\n";')
COOKIE="wordpress_$HASH=$AUTH; wordpress_logged_in_$HASH=$LOGGED"
PAGE=$(curl -s -b "$COOKIE" "$BASE/wp-admin/options-general.php?page=sendelva")
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN_DIR/sendelva.php" | head -1 | tr -d '[:space:]')
check "the settings screen shows version $VERSION" '[[ "$PAGE" == *">$VERSION</span>"* ]]'
check "the settings screen loads its stylesheet and script" '[[ "$PAGE" == *"sendelva/assets/admin.css"* && "$PAGE" == *"sendelva/assets/admin.js"* ]]'
check "the plugin prints no inline styles or event handlers" '! grep -q -E "onchange=|\.sendelva-wrap\{" <<<"$PAGE"'
check "the stored client secret never appears in the page" '[[ "$PAGE" != *"test-secret"* ]]'
check "the screen reports the configuration as complete" '[[ "$PAGE" == *"Configured"* && "$PAGE" == *"sendelva-ok"* ]]'
FORGED=$(curl -s -b "$COOKIE" "$BASE/wp-admin/options-general.php?page=sendelva&sendelva_test=ok&sendelva_nonce=forged")
check "a test result in the URL without a valid nonce shows no notice" '[[ "$FORGED" != *"Test message accepted"* ]]'
PLUGINS=$(curl -s -b "$COOKIE" "$BASE/wp-admin/plugins.php")
check "the Plugins screen links to the settings" '[[ "$PLUGINS" == *"options-general.php?page=sendelva"* ]]'
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE" -d 'action=sendelva_test' "$BASE/wp-admin/admin-post.php")
check "a test send without its nonce is refused" '[ "$CODE" = 403 ]'
LOGS=$(compose logs wordpress 2>&1 | grep -iE 'PHP (Warning|Notice|Fatal|Deprecated).*sendelva' || true)
check "no PHP warnings or notices from sendelva" '[ -z "$LOGS" ]'
[ -z "$LOGS" ] || echo "$LOGS"

echo
echo "== 11. Upgrading from Graph Mailer"
wp eval 'update_option( "e2e_saved", get_option( "sendelva_settings" ) ); delete_option( "sendelva_settings" ); update_option( "graph_mailer_settings", array( "tenant_id" => "from-graph-mailer" ) );' >/dev/null
MIGRATED=$(wp eval 'echo get_option( "sendelva_settings" )["tenant_id"] ?? "";')
check "Graph Mailer's settings are copied as soon as Sendelva loads" '[ "$MIGRATED" = from-graph-mailer ]'
wp eval 'update_option( "sendelva_settings", get_option( "e2e_saved" ) ); delete_option( "e2e_saved" ); delete_option( "graph_mailer_settings" );' >/dev/null
# A stand-in for the old plugin, with its folder, file and class name.
wp eval '$d = WP_PLUGIN_DIR . "/graph-mailer"; wp_mkdir_p( $d ); file_put_contents( "$d/graph-mailer.php", "<?php\n/**\n * Plugin Name: Graph Mailer\n * Version: 0.3.0\n */\nfinal class Graph_Mailer {}\n" );' >/dev/null
wp plugin activate graph-mailer >/dev/null
PLUGINS=$(curl -s -b "$COOKIE" "$BASE/wp-admin/plugins.php")
PAGE=$(curl -s -b "$COOKIE" "$BASE/wp-admin/options-general.php?page=sendelva")
NOTICE=$(grep -o '<div class="notice notice-warning"><p>Graph Mailer is still active[^<]*<a href="[^"]*">Deactivate Graph Mailer</a>' <<<"$PLUGINS" || true)
check "with Graph Mailer active, the Plugins screen offers to deactivate it" '[ -n "$NOTICE" ]'
check "so does the settings screen" '[[ "$PAGE" == *"Graph Mailer is still active"* ]]'
LINK=$(sed -e 's/.*<a href="\([^"]*\)".*/\1/' -e 's/&amp;/\&/g' -e 's/&#038;/\&/g' <<<"$NOTICE")
curl -s -o /dev/null -b "$COOKIE" "$LINK"
ACTIVE=$(wp plugin list --status=active --field=name | grep -x graph-mailer || true)
check "the notice's link deactivates Graph Mailer" '[ -z "$ACTIVE" ]'
PLUGINS=$(curl -s -b "$COOKIE" "$BASE/wp-admin/plugins.php")
check "and the notice goes away" '[[ "$PLUGINS" != *"Graph Mailer is still active"* ]]'
wp plugin delete graph-mailer >/dev/null

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ] || status=1
exit $status
