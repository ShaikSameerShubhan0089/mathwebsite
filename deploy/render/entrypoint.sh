#!/bin/sh
# Render assigns the port at run time in $PORT; Apache is compiled to listen on
# 80. Rewriting ports.conf at startup is the smallest way to reconcile the two,
# and it keeps the image usable locally with a different port.
set -e

PORT="${PORT:-10000}"

echo "Listen ${PORT}" > /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

# Fail fast and clearly. Without this a missing variable surfaces as
# "Error establishing a database connection", which sends you looking at
# Supabase when the real problem is an unset environment variable on Render.
missing=""
for v in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    eval "val=\$$v"
    [ -z "$val" ] && missing="$missing $v"
done
if [ -n "$missing" ]; then
    echo "FATAL: required environment variable(s) not set:$missing" >&2
    echo "Set them in the Render dashboard under Environment." >&2
    exit 1
fi

# Supabase requires TLS. libpq reads PGSSLMODE, so the connection is encrypted
# without patching PG4WP, which builds its connection string without an
# sslmode option of its own.
export PGSSLMODE="${PGSSLMODE:-require}"

# uploads/ is the only directory WordPress writes to at run time. On Render's
# free tier the filesystem is ephemeral, so this exists to keep media uploads
# working within a single container lifetime — see RENDER-SUPABASE.md.
mkdir -p /var/www/html/wp-content/uploads
chown -R www-data:www-data /var/www/html/wp-content/uploads

echo "Áis Mhatamaitice starting on port ${PORT}, database ${DB_HOST}/${DB_NAME}, sslmode ${PGSSLMODE}"

exec "$@"
