.PHONY: test-up test-env test-file test-unit test-contract test-concurrency test-transport test-filament test-install quality test-all test-report
export TEST_FILE
test-up:
	python3 scripts/test-rig.py up
test-env:
	python3 scripts/test-rig.py env
test-file:
	python3 scripts/test-rig.py file-env
test-unit test-contract test-concurrency test-transport test-filament:
	python3 scripts/test-rig.py suite $(patsubst test-%,%,$@)
test-install:
	python3 scripts/test-rig.py suite Installation
test-all:
	python3 scripts/test-rig.py suite .
test-report:
	python3 scripts/test-rig.py report
quality:
	python3 scripts/test-rig.py env
	composer validate --no-check-publish
	composer validate --no-check-publish --working-dir=packages/core
	composer validate --no-check-publish --working-dir=packages/filament
	docker compose -p proxyma_test -f docker/compose.test.yml exec -T runner vendor/bin/pint --test
	docker compose -p proxyma_test -f docker/compose.test.yml exec -T runner vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	python3 scripts/test-rig.py file tests/Smoke/ArchitectureTest.php
