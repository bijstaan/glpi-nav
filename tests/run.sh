#!/bin/sh
# How the glpi-nav tests are run.
#
# There is no unit-testable core here: the whole plugin is a transform over an
# array that only GLPI can build, from a menu that only a logged-in profile can
# produce. So the tests are invariants asserted against the real thing, and the
# behaviour that actually matters — breadcrumbs, Add buttons, the sidebar
# highlight — is covered in the browser, by glpi-nav/tests/browser/nav-check.js.
#
# Nothing here writes. transform.php reads config and the menu; it is safe to
# run on an instance somebody is working on.

set -e

CONTAINER=${CONTAINER:-glpi-glpi-1}
PLUGIN=/var/www/glpi/plugins/glpinav

echo "== lint =="
for f in setup.php hook.php front/config.php src/Layout.php src/Nav.php \
         src/Registry.php src/Settings.php tests/transform.php; do
    docker exec "$CONTAINER" php -l "$PLUGIN/$f"
done

echo
echo "== transform invariants =="
docker exec -i "$CONTAINER" php < "$(dirname "$0")/transform.php"

echo
echo "== browser =="
echo "Fixtures first (nav-probe, nav-dark):"
echo "  docker exec -i $CONTAINER php < glpi-nav/tests/fixtures.php"
echo "Then:"
echo "  cd glpi-nav/tests/browser && SHOT_DIR=../../docs/screenshots node nav-check.js"
