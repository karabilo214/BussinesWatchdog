FROM debian:12.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends squid \
    && rm -rf /var/lib/apt/lists/*

COPY squid.conf /etc/squid/squid.conf

USER proxy

EXPOSE 3128

CMD ["squid", "-N", "-f", "/etc/squid/squid.conf"]
