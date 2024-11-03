run-app:
	docker compose up -d

run-tests:
	docker compose run --rm time-tests vendor/bin/phpunit AppTest.php