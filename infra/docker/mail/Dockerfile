FROM alpine:3.22

RUN apk add --no-cache postfix dovecot dovecot-lmtpd dovecot-pigeonhole-plugin ca-certificates openssl \
    && deluser vmail && addgroup -S -g 5000 vmail && adduser -S -D -H -u 5000 -G vmail -h /var/mail vmail \
    && postconf -e \
        'mydestination =' \
        'local_transport = error:local delivery is disabled' \
        'inet_interfaces = all' \
        'inet_protocols = ipv4' \
        'mynetworks = 127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' \
        'virtual_mailbox_maps = texthash:/var/mail/config/mailboxes' \
        'virtual_alias_maps = texthash:/var/mail/config/aliases' \
        'virtual_transport = lmtp:unix:private/dovecot-lmtp' \
        'smtpd_helo_required = yes' \
        'disable_vrfy_command = yes' \
        'smtpd_client_restrictions =' \
        'smtpd_relay_restrictions = reject_unauth_destination' \
        'smtpd_recipient_restrictions = reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination' \
        'smtpd_sender_restrictions = reject_non_fqdn_sender, reject_unknown_sender_domain' \
        'smtpd_tls_security_level = may' \
        'smtpd_tls_cert_file = /etc/ssl/mail/cert.pem' \
        'smtpd_tls_key_file = /etc/ssl/mail/key.pem' \
        'smtpd_tls_mandatory_protocols = >=TLSv1.2' \
        'smtpd_tls_protocols = >=TLSv1.2' \
        'smtpd_tls_loglevel = 1' \
        'smtpd_milters = inet:rspamd:11332' \
        'non_smtpd_milters =' \
        'milter_default_action = tempfail' \
        'milter_protocol = 6' \
        'message_size_limit = 26214400' \
        'smtp_tls_security_level = may' \
        'smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt' \
        'smtp_tls_loglevel = 1' \
        'smtputf8_enable = no' \
        'maillog_file = /dev/stdout' \
        'notify_classes =' \
        'bounce_queue_lifetime = 1d' \
        'maximal_queue_lifetime = 2d' \
    && postconf -M '2525/inet=2525 inet n - n - - smtpd' \
    && postconf -P \
        '2525/inet/syslog_name=postfix/internal' \
        '2525/inet/smtpd_client_restrictions=permit_mynetworks,reject' \
        '2525/inet/smtpd_relay_restrictions=permit_mynetworks,reject' \
        '2525/inet/smtpd_recipient_restrictions=permit_mynetworks,reject' \
        '2525/inet/smtpd_sender_restrictions=' \
        '2525/inet/smtpd_milters=' \
    && for service in submission submissions; do \
        postconf -M "$service/inet=$service inet n - n - - smtpd" \
        && postconf -P \
            "$service/inet/syslog_name=postfix/$service" \
            "$service/inet/smtpd_sasl_auth_enable=yes" \
            "$service/inet/smtpd_sasl_type=dovecot" \
            "$service/inet/smtpd_sasl_path=private/auth" \
            "$service/inet/smtpd_sasl_security_options=noanonymous" \
            "$service/inet/smtpd_sender_login_maps=texthash:/var/mail/config/senders" \
            "$service/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
            "$service/inet/smtpd_sender_restrictions=reject_sender_login_mismatch,permit_sasl_authenticated,reject" \
            "$service/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
            "$service/inet/smtpd_recipient_restrictions=permit_sasl_authenticated,reject"; \
    done \
    && postconf -P 'submission/inet/smtpd_tls_security_level=encrypt' 'submissions/inet/smtpd_tls_wrappermode=yes'

COPY dovecot/dovecot.conf /etc/dovecot/dovecot.conf
COPY dovecot/spam.sieve /etc/dovecot/sieve/spam.sieve
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY mailbox.sh /usr/local/bin/mailbox

RUN sievec /etc/dovecot/sieve/spam.sieve

EXPOSE 25 465 587 993 2525

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
