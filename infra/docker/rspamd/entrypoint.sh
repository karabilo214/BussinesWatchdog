#!/bin/sh
set -eu

: "${MAIL_DOMAIN:?MAIL_DOMAIN is required}"
: "${MAIL_DKIM_SELECTOR:?MAIL_DKIM_SELECTOR is required}"

KEY="/var/lib/rspamd/dkim/$MAIL_DKIM_SELECTOR.key"

mkdir -p /var/lib/rspamd/dkim /run/rspamd
if [ -n "${MAIL_DKIM_PRIVATE_KEY:-}" ]; then
    printf '%b\n' "$MAIL_DKIM_PRIVATE_KEY" > "$KEY"
fi
unset MAIL_DKIM_PRIVATE_KEY

cat > /etc/rspamd/local.d/dkim_signing.conf <<CONF
enabled = true;
sign_authenticated = true;
sign_local = false;
allow_username_mismatch = false;
use_domain = "header";
use_esld = true;
domain {
  $MAIL_DOMAIN {
    path = "$KEY";
    selector = "$MAIL_DKIM_SELECTOR";
  }
}
CONF

chown -R rspamd:rspamd /var/lib/rspamd /run/rspamd
chmod 600 "$KEY" 2>/dev/null || true

exec rspamd -f -u rspamd -g rspamd
