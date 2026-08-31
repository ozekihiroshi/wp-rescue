#!/usr/bin/env bash
# Bounded foreground test observer. Uses real HTTP Cron, never run()/tick().
set -euo pipefail
test "$(hostname)" = ip-172-31-2-103
expected_job=${1:?Pass the known test job ID}
[[ "$expected_job" =~ ^[a-f0-9]{32}$ ]]
for ((sample=0; sample<90; sample++)); do
    curl --fail --silent --show-error --max-time 35 --output /dev/null \
        http://127.0.0.1:8084/wp-cron.php
    status=$(docker exec --user www-data odbfs3-media-ziptest-web \
        php /opt/ziptest-tools/media-test.php status)
    printf '%s\n' "$status"
    if [[ "$status" != *"\"id\":\"$expected_job\""* ]]; then exit 2; fi
    if [[ "$status" == *'"status":"succeeded"'* ]]; then exit 0; fi
    if [[ "$status" == *'"status":"failed"'* ]]; then exit 1; fi
    sleep 30
done
echo 'Observer timeout; inspect the job without deleting its data.' >&2
exit 3
