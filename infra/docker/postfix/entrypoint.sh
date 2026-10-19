#!/bin/sh
set -eu

: "${MAIL_HOSTNAME:?MAIL_HOSTNAME is required}"
: "${MAIL_DOMAIN:?MAIL_DOMAIN is required}"

postconf -e "myhostname = ${MAIL_HOSTNAME}" "mydomain = ${MAIL_DOMAIN}" "myorigin = ${MAIL_DOMAIN}"
newaliases
exec postfix start-fg
