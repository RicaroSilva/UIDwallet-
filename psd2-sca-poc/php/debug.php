<?php
// debug.php
// TEMPORARY: isolates each step (PHP itself, OpenSSL extension, EC key
// generation, file writes) so we can see exactly which one crashes the
// process on this server, instead of guessing from a bare ERR_INVALID_RESPONSE.
// Delete once the request-object.php issue is diagnosed.

header('Content-Type: text/plain');

echo "1. PHP alive: OK\n";
echo "2. PHP version: " . PHP_VERSION . "\n";
echo "3. openssl extension loaded: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
flush();

echo "4a. Testing EC key generation WITHOUT any env vars set...\n";
flush();
$keyPlain = @openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
echo '    ' . ($keyPlain === false ? 'FAILED: ' . openssl_error_string() : 'OK') . "\n";
flush();

echo "4b. Testing EC key generation WITH OPENSSL_CONF + RANDFILE env vars (the actual fix)...\n";
flush();
$opensslConfigPath = __DIR__ . '/openssl.cnf';
$randFilePath = sys_get_temp_dir() . '/lusopay-openssl-rand.tmp';
echo "    config file exists: " . (is_file($opensslConfigPath) ? 'YES' : 'NO, missing: ' . $opensslConfigPath) . "\n";
echo "    temp dir for RANDFILE: {$randFilePath}\n";
putenv("OPENSSL_CONF={$opensslConfigPath}");
putenv("RANDFILE={$randFilePath}");
$key = @openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if ($key === false) {
    echo "    FAILED. openssl_error_string(): " . openssl_error_string() . "\n";
} else {
    echo "    OK\n";
    flush();

    echo "5. Testing key export...\n";
    flush();
    $exported = @openssl_pkey_export($key, $pem);
    echo '    ' . ($exported ? 'OK' : 'FAILED: ' . openssl_error_string()) . "\n";

    if ($exported) {
        echo "6. Testing key details / EC point extraction...\n";
        flush();
        $details = @openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            echo "    FAILED: could not read ec.x / ec.y from key details\n";
            echo '    details: ' . var_export($details, true) . "\n";
        } else {
            echo "    OK\n";
        }
    }
}
flush();

echo "7. Testing directory write in " . __DIR__ . "...\n";
flush();
$testDir = __DIR__ . '/keys-test';
@mkdir($testDir, 0700, true);
echo '    mkdir: ' . (is_dir($testDir) ? 'OK' : 'FAILED') . "\n";
if (is_dir($testDir)) {
    $writeOk = @file_put_contents($testDir . '/test.txt', 'hello');
    echo '    write file: ' . ($writeOk !== false ? 'OK' : 'FAILED') . "\n";
    @unlink($testDir . '/test.txt');
    @rmdir($testDir);
}

echo "DONE\n";
