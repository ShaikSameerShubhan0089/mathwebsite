#!/bin/sh
# Provision the Primary Mathematics Hub. Idempotent — safe to re-run.
#
#   docker compose run --rm setup
#
# Everything here is the Phase 1 install COGG would receive: site created,
# plugin and theme active, the three editorial roles populated with real
# accounts, curriculum vocabulary seeded, pages created and sample content
# published through the same metadata gate that governs COGG's own editing.
set -eu

SITE_URL="${SITE_URL:-http://localhost:8091}"
ADMIN_PASS="${ADMIN_PASS:-cogg2026}"

echo "==> Waiting for WordPress core files"
i=0
while [ ! -f /var/www/html/wp-settings.php ] && [ "$i" -lt 60 ]; do
  i=$((i + 1)); sleep 2
done
if [ ! -f /var/www/html/wp-settings.php ]; then
  echo "!! WordPress core not found. Start the stack first: docker compose up -d" >&2
  exit 1
fi

echo "==> Installing WordPress"
if ! wp core is-installed 2>/dev/null; then
  wp core install \
    --url="$SITE_URL" \
    --title="Áis Mhatamaitice" \
    --admin_user="riarthoir" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="eolas@cogg.ie" \
    --skip-email
else
  echo "    already installed"
fi

echo "==> Locale and options"
wp language core install ga --activate 2>/dev/null || echo "    (ga language pack unavailable; continuing in English)"
wp option update blogdescription "Acmhainní matamaitice do bhunscoileanna lán-Ghaeilge agus Gaeltachta"
wp option update timezone_string "Europe/Dublin"
wp option update users_can_register 0
# Re-assert the site URL every run: a port change would otherwise leave the
# install redirecting to the old host and every page 301s.
wp option update siteurl "$SITE_URL"
wp option update home "$SITE_URL"
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

echo "==> Activating plugin and theme"
wp plugin activate cogg-mata
wp theme activate mata 2>/dev/null || echo "    (theme 'mata' not present; keeping default)"

# H5P is the Phase 3 authoring platform (RFT §9.2.1, SRS §8.3). It is optional
# here: Phases 1-2 must never depend on a Phase 3 decision.
if [ "${INSTALL_H5P:-0}" = "1" ]; then
  echo "==> Installing H5P"
  wp plugin install h5p --activate || echo "    (H5P install failed; native kits still work)"
fi

echo "==> COGG editorial accounts"
create_user() {
  login="$1"; email="$2"; name="$3"; role="$4"
  if wp user get "$login" >/dev/null 2>&1; then
    wp user update "$login" --role="$role" --display_name="$name" >/dev/null
    echo "    updated $login ($role)"
  else
    wp user create "$login" "$email" \
      --role="$role" --display_name="$name" --user_pass="$ADMIN_PASS" >/dev/null
    echo "    created $login ($role)"
  fi
}
create_user "sile"  "sile@cogg.ie"  "Síle Ní Mhurchú"     "mata_content_editor"
create_user "dara"  "dara@cogg.ie"  "Dara Ó Briain"        "mata_digital_author"
create_user "aoife" "aoife@cogg.ie" "Aoife Nic Gearailt"   "mata_administrator"

echo "==> Pages"
make_page() {
  slug="$1"; title="$2"; content="$3"
  if ! wp post list --post_type=page --name="$slug" --format=count | grep -q '^1$'; then
    wp post create --post_type=page --post_status=publish \
      --post_name="$slug" --post_title="$title" --post_content="$content" >/dev/null
    echo "    created /$slug/"
  else
    echo "    /$slug/ exists"
  fi
}
make_page "uirlisi"        "Uirlisí"        "[mata_toolkit]"
make_page "acmhainni"      "Acmhainní"      "[mata_library]"
make_page "gniomhaiochtai" "Gníomhaíochtaí" "[mata_activities]"
make_page "oscail"         "Oscail gníomhaíocht" "[mata_open]"
make_page "priobhaideacht" "Fógra príobháideachais" \
  "<p>Níl aon chuntas dalta ná aon sonra pearsanta dalta ar an ardán seo. Oibríonn an sreabhadh oibre sábháil-agus-roinn go hiomlán ar ghléas an mhúinteora agus an dalta — ní shroicheann an comhad gníomhaíochta ár bhfreastalaí riamh.</p>"

# A real static front page, so the theme's front-page.php is used. With
# show_on_front=page and no page_on_front, WordPress falls through to the blog
# index and is_front_page() is false.
make_page "baile" "Áis Mhatamaitice" ""
home_id=$(wp post list --post_type=page --name=baile --field=ID --format=ids | head -1)
wp option update show_on_front page
[ -n "$home_id" ] && wp option update page_on_front "$home_id"

# Remove the WordPress starter content — it is not COGG's and it confuses a demo.
for junk in "Hello world!" "Sample Page"; do
  jid=$(wp post list --post_type=any --post_status=any --title="$junk" --field=ID --format=ids 2>/dev/null | head -1)
  [ -n "$jid" ] && wp post delete "$jid" --force >/dev/null 2>&1 || true
done
wp menu create "Príomhroghchlár" 2>/dev/null || true
# Rebuild from empty. `wp menu item add-post` is not idempotent, so re-running
# setup without this appends a second copy of every link.
for item in $(wp post list --post_type=nav_menu_item --field=ID --format=ids 2>/dev/null); do
  wp post delete "$item" --force >/dev/null 2>&1 || true
done
for slug in uirlisi acmhainni gniomhaiochtai oscail; do
  pid=$(wp post list --post_type=page --name="$slug" --field=ID --format=ids | head -1)
  [ -n "$pid" ] && wp menu item add-post "Príomhroghchlár" "$pid" >/dev/null 2>&1 || true
done
wp menu location assign "Príomhroghchlár" primary 2>/dev/null || true

echo "==> Sample content"
wp eval-file /scripts/seed.php

echo ""
echo "======================================================================"
echo " Áis Mhatamaitice is up:  $SITE_URL"
echo " Admin:                   $SITE_URL/wp-admin"
echo ""
echo " riarthoir / $ADMIN_PASS   WordPress administrator"
echo " sile      / $ADMIN_PASS   COGG Content Editor   (resources + pages)"
echo " dara      / $ADMIN_PASS   COGG Digital Author   (activities only)"
echo " aoife     / $ADMIN_PASS   COGG Administrator    (everything + taxonomy)"
echo ""
echo " Sign in as each to see the SRS §4.2 permissions matrix enforced."
echo "======================================================================"
