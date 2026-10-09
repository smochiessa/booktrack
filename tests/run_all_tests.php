<?php
/**
 * Master Automated Test Runner for Page Lounge Library Enhancements
 */

$tests = [
    'test_migration.php',
    'test_copies_logic.php',
    'test_customer_auth.php',
    'test_customer_approval.php',
    'test_borrow_limit.php',
    'test_admin_return.php',
    'test_qr_return_endpoint.php',
    'test_print_qrs_rendering.php',
    'test_most_borrowed_query.php',
];

$passed = 0;
$failed = 0;
$results = [];

echo "========================================================\n";
echo "Page Lounge Book Café - Automated Test Suite\n";
echo "Date: " . date("Y-m-d H:i:s") . "\n";
echo "========================================================\n\n";

foreach ($tests as $testFile) {
    $fullPath = __DIR__ . '/' . $testFile;
    if (!file_exists($fullPath)) {
        echo "FAIL: $testFile (File not found)\n";
        $failed++;
        $results[$testFile] = 'MISSING';
        continue;
    }

    try {
        // Run test in an isolated process
        $output = [];
        $returnCode = 0;
        exec("php " . escapeshellarg($fullPath) . " 2>&1", $output, $returnCode);

        $outStr = implode("\n", $output);
        if ($returnCode === 0 && strpos($outStr, 'PASS:') !== false) {
            echo "✔ PASS: $testFile\n";
            $passed++;
            $results[$testFile] = 'PASSED';
        } else {
            echo "✖ FAIL: $testFile (Code: $returnCode)\n";
            echo "   Output: " . trim($outStr) . "\n";
            $failed++;
            $results[$testFile] = 'FAILED';
        }
    } catch (\Throwable $e) {
        echo "✖ ERROR: $testFile (" . $e->getMessage() . ")\n";
        $failed++;
        $results[$testFile] = 'ERROR';
    }
}

echo "\n--------------------------------------------------------\n";
echo "Test Execution Summary:\n";
echo "Total: " . count($tests) . " | Passed: $passed | Failed: $failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
