#!/bin/sh
set -eu

: "${LAB_NETWORK:?set a unique temporary CI network name}"
: "${RABBITMQ_PASSWORD:?set a test password}"
: "${POSTGRES_PASSWORD:?set a test password}"
project=queue-service-laravel-13-smoke

cleanup() {
    docker compose -p "$project" down --volumes --remove-orphans
    docker network rm "$LAB_NETWORK"
}
trap cleanup EXIT
docker network create --internal "$LAB_NETWORK"
docker compose -p "$project" up -d --build --wait

wait_receipt() {
    attempt=0
    while [ "$attempt" -lt 60 ]; do
        count=$(docker compose -p "$project" exec -T postgres psql -U lab -d queue_lab -Atc \
            "SELECT count(*) FROM processed_messages WHERE queue_name='tasks' AND message_id='$1';")
        if [ "$count" = 1 ]; then return 0; fi
        attempt=$((attempt + 1))
        sleep 0.2
    done
    docker compose -p "$project" logs
    return 1
}

docker compose -p "$project" exec -T queue-service-laravel-13 php artisan queue:publish \
    --id=runtime-smoke --payload=hello
wait_receipt runtime-smoke

# Leave one confirmed message waiting, then restart the broker with its volume.
docker compose -p "$project" stop consumer
docker compose -p "$project" exec -T queue-service-laravel-13 php artisan queue:publish \
    --id=restart-smoke --payload=persistent
docker compose -p "$project" restart rabbitmq
docker compose -p "$project" up -d --wait rabbitmq
docker compose -p "$project" exec -T queue-service-laravel-13 php artisan queue:inspect --queue=tasks
docker compose -p "$project" up -d consumer
wait_receipt restart-smoke

# The existing RoadRunner process must accept a new call after broker recovery.
docker compose -p "$project" exec -T queue-service-laravel-13 php artisan queue:publish \
    --id=recovery-smoke --payload=reconnected
wait_receipt recovery-smoke
