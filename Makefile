.PHONY: build check test integration integration-down up down audit
build:
	docker build --target runtime -t queue-service-laravel-13:local .
check:
	docker build --target test -t queue-service-laravel-13:test .
	docker run --rm queue-service-laravel-13:test composer lint
	docker run --rm queue-service-laravel-13:test vendor/bin/phpunit --testsuite Unit
test: check
integration:
	docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test test
integration-down:
	docker compose -f compose.test.yaml down --volumes --remove-orphans
up:
	docker compose up -d --build --wait
down:
	docker compose down
audit:
	docker run --rm queue-service-laravel-13:test composer audit
