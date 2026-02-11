#!/bin/bash
set -euo pipefail

echo "Waiting for database..."
until wp db check --quiet 2>/dev/null; do
    sleep 2
done
echo "Database ready."

if ! wp core is-installed 2>/dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --url="http://localhost:8080" \
        --title="GMTU Dev" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email
    echo "WordPress installed."
fi

echo "Installing parent plugin..."
wp plugin install common-knowledge-join-flow --activate --force

echo "Activating GMTU extension..."
wp plugin activate join-flow-gmtu

echo "Setup complete."
echo "  WordPress: http://localhost:8080/wp-admin  (admin / admin)"
echo "  Mailpit:   http://localhost:8025"
