#!/bin/sh
set -eu

: "${MAIL_HOSTNAME:?MAIL_HOSTNAME is required}"
: "${MAIL_DOMAIN:?MAIL_DOMAIN is required}"

CERTS="${MAIL_CERT_SOURCE:-/caddy/caddy/certificates}"

find_certificate() {
    for crt in "$CERTS"/*/"$MAIL_HOSTNAME"/"$MAIL_HOSTNAME".crt; do
        [ -f "$crt" ] && [ -f "${crt%.crt}.key" ] && { echo "${crt%.crt}"; return 0; }
    done
    return 1
}

install_certificate() {
    mkdir -p /etc/ssl/mail
    if source="$(find_certificate)"; then
        if ! cmp -s "$source.crt" /etc/ssl/mail/cert.pem; then
            install -m 644 "$source.crt" /etc/ssl/mail/cert.pem
            install -m 600 "$source.key" /etc/ssl/mail/key.pem
            return 0
        fi
        return 1
    fi
    if [ ! -f /etc/ssl/mail/cert.pem ]; then
        echo "No certificate for $MAIL_HOSTNAME yet; using a temporary self-signed one." >&2
        openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj "/CN=$MAIL_HOSTNAME" \
            -keyout /etc/ssl/mail/key.pem -out /etc/ssl/mail/cert.pem 2>/dev/null
        chmod 600 /etc/ssl/mail/key.pem
        return 0
    fi
    return 1
}

install_certificate || true
postconf -e "myhostname = ${MAIL_HOSTNAME}" "mydomain = ${MAIL_DOMAIN}" "myorigin = ${MAIL_DOMAIN}" "virtual_mailbox_domains = ${MAIL_DOMAIN}"
MAIL_DOMAIN="$MAIL_DOMAIN" mailbox rebuild
mkdir -p /var/mail/vhosts && chown vmail:vmail /var/mail/vhosts
newaliases
postfix set-permissions >/dev/null 2>&1 || true

dovecot

(
    minutes=0
    while sleep 60; do
        minutes=$((minutes + 1))
        pidof dovecot >/dev/null || { echo "dovecot stopped" >&2; kill 1; exit 1; }
        if [ $((minutes % 10)) -eq 0 ] && install_certificate; then
            echo "Certificate for $MAIL_HOSTNAME renewed; reloading." >&2
            postfix reload
            doveadm reload
        fi
    done
) &

exec postfix start-fg
