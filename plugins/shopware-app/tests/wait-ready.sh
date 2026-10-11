#!/bin/sh
# Waits until the test Shopware on 127.0.0.1:8480 issues an admin token twice in a row (the API answering 401 is not
# enough: a fresh container may still restart its web server).
ok=0; i=0
while [ $ok -lt 2 ]; do
    i=$((i + 1)); [ $i -gt 90 ] && { echo "shopware did not become ready"; exit 1; }
    code=$(curl -s -o /dev/null -w '%{http_code}' -X POST http://127.0.0.1:8480/api/oauth/token -H 'Content-Type: application/json' -d '{"grant_type":"password","client_id":"administration","scope":"write","username":"admin","password":"shopware"}' || true)
    if [ "$code" = "200" ]; then ok=$((ok + 1)); else ok=0; fi
    sleep 5
done
