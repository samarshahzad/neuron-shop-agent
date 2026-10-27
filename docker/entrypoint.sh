#!/bin/sh
set -e

cd /app

# 1. Install PHP dependencies (fast when nothing changed)
composer install --no-interaction --prefer-dist --no-progress

# 2. Create tables + demo data (skipped automatically if they already exist).
#    Retries while MySQL finishes starting.
tries=0
until php bin/console app:db:setup --no-interaction; do
    tries=$((tries + 1))
    if [ "$tries" -ge 15 ]; then
        echo "Database setup failed. Check the mysql container: docker compose logs mysql"
        exit 1
    fi
    echo "Database not ready yet, retrying in 3 seconds... ($tries/15)"
    sleep 3
done

# 3. Create AI vectors for new or changed products (for "similar products").
#    If the embedding model is missing, the app still works with rule-based recommendations.
php bin/console app:products:embed --no-interaction || echo "Embeddings skipped: recommendations use rules for now."

# 4. Start the web server
echo ""
echo "  Kiez Living shop assistant is running: http://localhost:8000"
echo ""
exec php -S 0.0.0.0:8000 -t public
