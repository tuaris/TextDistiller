#!/usr/bin/env php
<?php
/**
 * Regression test: validates the 8 original comparison cases.
 */

spl_autoload_register(function ($class) {
    $prefix = 'TextDistiller\\';
    if (strpos($class, $prefix) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);
    $classFile = __DIR__ . '/../src/' . $relative . '.class.php';
    if (is_file($classFile)) { require_once $classFile; return; }
    $ifaceFile = __DIR__ . '/../src/' . $relative . '.interface.php';
    if (is_file($ifaceFile)) { require_once $ifaceFile; return; }
});

use TextDistiller\Distiller;

$distiller = new Distiller();
$pass = 0;
$fail = 0;

$cases = [
    // Case 1: Numbered procedural steps → archive
    [
        'name' => 'Numbered procedural steps',
        'input' => "(1) Download the source tarball from the release page (2) Verify the GPG signature (3) Run the build with cross-compilation flags (4) Package into the target format (5) Run integration tests in a sandbox",
        'expected_action' => 'archive',
    ],

    // Case 2: Key-value configuration block → archive
    [
        'name' => 'Key-value configuration block',
        'input' => "dep_name=libcurl\ndep_version=8.5.0\nopt_ssl=openssl\nopt_zlib=true\nbuild_cmd=make -j4\ninstall_prefix=/usr/local\ncategory=net",
        'expected_action' => 'archive',
    ],

    // Case 3: Technical correction with numbers → compress
    [
        'name' => 'Technical correction with dense identifiers',
        'input' => "CORRECTION: the actual number of functions refactored was 57, not 61. That cost us a recount. auth-core has 226 unit tests, auth-crypto has 68. Total across all 6 repos: 570 tests. The 45-second figure for the GPU benchmark was wrong — it was 54 seconds. Package updated to v0.9.3 in the stable channel.",
        'expected_action' => 'compress',
    ],

    // Case 4: Software release notes → compress (should extract multiple facts)
    [
        'name' => 'Software release notes',
        'input' => "Acme Platform v2.11.0 released 2026-06-29. Library swap: replaced internal RPC namespace with canonical SharedRPC library (7 files). Transport refactor: index.php now uses StreamTransport::handle(). Added 4 API resources: entities, entity/{name}, stats, recent. Tests: 139 tests, 430 assertions (was 124/394).",
        'expected_action' => 'compress',
        'min_facts' => 3,
    ],

    // Case 5: Cross-implementation test verification → compress
    [
        'name' => 'Cross-implementation test verification',
        'input' => "DKIM signing test suite passes 17/17 against reference vectors. Signing uses RSA-SHA256 with relaxed/relaxed canonicalization. Test key is fetched from upstream commit a4c7e2b1 (pinned SHA256 hash). The fetch-vectors.sh script validates integrity before use.",
        'expected_action' => 'compress',
        'min_facts' => 2,
    ],

    // Case 6: Short factual statement → compress (1+ facts)
    [
        'name' => 'Short factual statement',
        'input' => "The RFC proposes adding a built-in test framework to the language core, similar to Go's testing package. Target version is 9.0.",
        'expected_action' => 'compress',
        'min_facts' => 1,
    ],

    // Case 7: Pure session narrative → prune
    [
        'name' => 'Pure session narrative',
        'input' => "This was a productive session. We discussed the approach and explored several options. I then realized we needed a different strategy. Will continue in the next session. SESSION COMPLETE.",
        'expected_action' => 'prune',
    ],

    // Case 8: Refactoring metrics (dense numbers) → compress
    [
        'name' => 'Refactoring metrics with dense numbers',
        'input' => "Stage 3 complete: processRequest depth reduced 7->4, handleAuth depth 6->4, loadConfig depth 6->3. Evaluator struct reduced 11 functions from 6 params to 3. Unit tests increased from 417 to 570 across all 6 repos. Conformance gate unchanged: 203/203, 17/17, 28/28, 171/171.",
        'expected_action' => 'compress',
        'min_facts' => 3,
    ],
];

echo "TextDistiller v0.2 — TF-IDF + LexRank Regression Tests\n";
echo str_repeat('=', 60) . "\n\n";

foreach ($cases as $i => $case) {
    $num = $i + 1;
    $result = $distiller->distill($case['input']);
    $actionMatch = ($result['action'] === $case['expected_action']);
    $factsOk = true;

    if (isset($case['min_facts']) && $result['action'] === 'compress') {
        $factsOk = count($result['facts']) >= $case['min_facts'];
    }

    $ok = $actionMatch && $factsOk;

    if ($ok) {
        $pass++;
        echo "  ✓ Case {$num}: {$case['name']} → {$result['action']}";
        if (!empty($result['facts'])) {
            echo " (" . count($result['facts']) . " facts)";
        }
        echo "\n";
    } else {
        $fail++;
        echo "  ✗ Case {$num}: {$case['name']}\n";
        echo "    Expected: {$case['expected_action']}";
        if (isset($case['min_facts'])) echo " (≥{$case['min_facts']} facts)";
        echo "\n";
        echo "    Got: {$result['action']}";
        if (!empty($result['facts'])) echo " (" . count($result['facts']) . " facts)";
        echo "\n";
        echo "    Reason: {$result['reason']}\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$pass} passed, {$fail} failed, " . count($cases) . " total\n";

exit($fail > 0 ? 1 : 0);
