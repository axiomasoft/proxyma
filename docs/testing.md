# Изолированный test rig

Зависимости устанавливаются отдельно командой `php8.4 /usr/local/bin/composer install`.
`make test-up` собирает runtime и запускает проект Compose `proxyma_test`.
Проверки работают только внутри закрытой сети fixture, без портов PostgreSQL на хосте.
`make test-env` проверяет сеть, том, идентичность базы и доступность fixture до тестов.
`make test-file TEST_FILE=tests/Installation/RigIsolationTest.php` запускает один файл;
`make test-all` запускает все файлы, `make test-report` собирает JUnit и SHA HEAD в build.
`python3 -B scripts/test-rig-selftest.py` проверяет локальные отказы до подключения к Docker.
Отчёт содержит SHA исходного дерева и признак незакоммиченных изменений; после изменения
исходников или HEAD старый JUnit считается устаревшим и должен быть собран заново.

Независимые worker-процессы конкурентных тестов должны использовать отдельные соединения
PostgreSQL и явную очистку только после preflight. Общая rollback-транзакция скрывает
изменения от других процессов и не доказывает поведение блокировок.

Сертификат и ключ в `scripts/test-fixture-*.pem` предназначены только для локального
fixture; runner доверяет сертификату явно и проверяет имя `fixture`.
