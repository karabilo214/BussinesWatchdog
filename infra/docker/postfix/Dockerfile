FROM alpine:3.22

RUN apk add --no-cache postfix ca-certificates \
    && postconf -e \
        'mydestination =' \
        'local_transport = error:local delivery is disabled' \
        'inet_interfaces = all' \
        'inet_protocols = ipv4' \
        'mynetworks = 127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' \
        'smtpd_relay_restrictions = permit_mynetworks, reject' \
        'smtpd_recipient_restrictions = permit_mynetworks, reject' \
        'smtpd_client_restrictions = permit_mynetworks, reject' \
        'message_size_limit = 26214400' \
        'smtp_tls_security_level = may' \
        'smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt' \
        'smtp_tls_loglevel = 1' \
        'smtputf8_enable = no' \
        'maillog_file = /dev/stdout' \
        'notify_classes =' \
        'bounce_queue_lifetime = 1d' \
        'maximal_queue_lifetime = 2d'

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 25

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
