#!/bin/sh
set -eu
marker=/var/lib/postgresql/data/.proxyma-test-volume
if [ -e /var/lib/postgresql/data/PG_VERSION ]; then
    [ -f "$marker" ] && [ "$(cat "$marker")" = proxyma_test_v1 ] || { echo 'unmarked database volume' >&2; exit 1; }
else
    [ -z "$(find /var/lib/postgresql/data -mindepth 1 -maxdepth 1 -print -quit)" ] || { echo 'nonempty unmarked database volume' >&2; exit 1; }
    # The entrypoint needs an empty directory when it initializes the cluster.
fi
exec docker-entrypoint.sh postgres
