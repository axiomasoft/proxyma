<?php

declare(strict_types=1);

foreach (['DB_HOST' => 'db', 'DB_DATABASE' => 'proxyma_test', 'DB_USERNAME' => 'proxyma_test', 'DB_PORT' => '5432', 'DB_URL' => ''] as $key => $expected) {
    if (getenv($key) !== $expected) {
        throw new RuntimeException("Unsafe {$key}");
    }
}
$db = new PDO('pgsql:host=db;port=5432;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$row = $db->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM);
if ($row !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Unsafe database identity or marker');
}
if (file_get_contents('http://fixture:8080/') !== 'proxyma-fixture-v1') {
    throw new RuntimeException('Fixture endpoint failed');
}
foreach ([null, 'http://fixture:8888'] as $proxy) {
    $curl = curl_init('https://fixture:8443/');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CAINFO => '/work/scripts/test-fixture-cert.pem', CURLOPT_PROXY => $proxy ?? '', CURLOPT_NOPROXY => '']);
    if (curl_exec($curl) !== 'proxyma-fixture-v1') {
        throw new RuntimeException('TLS or proxy fixture failed: '.curl_error($curl));
    }
    curl_close($curl);
}
$socket = @stream_socket_client('tcp://1.1.1.1:443', $errno, $errstr, 2);
if ($socket !== false) {
    fclose($socket);
    throw new RuntimeException('Public egress is available');
}
echo "proxyma_test identity, fixture and egress verified\n";
