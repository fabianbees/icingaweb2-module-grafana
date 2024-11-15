.PHONY: setup lint phpcs

lint:
	phplint application/ library/ configuration.php
phpcs:
	phpcs application/ library/ configuration.php
