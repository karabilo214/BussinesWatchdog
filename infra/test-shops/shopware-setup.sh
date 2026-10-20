#!/bin/sh
# Turns the freshly started bw-test-shopware container (bw-shopware:<version>, built by
# plugins/shopware-app/tests/build-image.sh from dockware) into the public test store: prod mode, public HTTPS URL,
# a new APP_SECRET, trusted Caddy proxy, new admin password, no dockware development tools (Adminer, mailcatcher, log
# viewer), SwagPayPal 10 installed. Run on the server from infra/test-shops; PayPal is configured by shopware-paypal.py.
set -eu
. /opt/business-watchdog/test-shops.env
URL="https://shopware-test.$SITE_DOMAIN"
C=bw-test-shopware

docker exec -i $C bash -lc "cd /var/www/html && umask 077 && cat > .env.local && chmod 600 .env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_URL=$URL
PROXY_URL=$URL
APP_SECRET=$(openssl rand -hex 32)
MAILER_DSN=null://null
ENV
docker exec -i $C bash -lc "cat > /var/www/html/config/packages/zz_bw_trusted_proxies.yaml" <<'YAML'
framework:
    trusted_proxies: "127.0.0.1,REMOTE_ADDR"
    trusted_headers: ["x-forwarded-for", "x-forwarded-proto", "x-forwarded-host", "x-forwarded-port"]
YAML
docker exec -i -u root $C bash -lc "cat > /etc/apache2/sites-enabled/000-default.conf && rm -f /var/www/html/public/adminer.php /etc/apache2/conf-enabled/adminer.conf && apachectl -t && apachectl graceful" <<'VHOST'
<VirtualHost *:80>
    ErrorLog /var/log/apache2/error.log
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        Options -Indexes
        AllowOverride All
        Require all granted
        CGIPassAuth On
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
VHOST
docker exec $C bash -lc "cd /var/www/html \
    && mysql -h127.0.0.1 -uroot -proot shopware -e \"UPDATE sales_channel_domain SET url='$URL' WHERE url IN ('http://localhost','https://localhost')\" 2>/dev/null \
    && php bin/console user:change-password admin --password='$SHOPWARE_ADMIN_PASSWORD' -n >/dev/null \
    && composer require 'swag/paypal:^10' --no-interaction >/dev/null 2>&1 \
    && php bin/console plugin:refresh -n >/dev/null && php bin/console plugin:install --activate SwagPayPal -n >/dev/null \
    && php bin/console cache:clear -n >/dev/null && php bin/console --version"
