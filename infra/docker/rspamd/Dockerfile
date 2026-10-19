FROM alpine:3.22

RUN apk add --no-cache rspamd rspamd-proxy rspamd-controller rspamd-client

COPY local.d/ /etc/rspamd/local.d/
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 11332

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
