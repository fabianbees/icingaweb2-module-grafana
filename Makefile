.PHONY: setup lint phpcs

lint:
	phplint application/ library/
phpcs:
	phpcs application/ library/
