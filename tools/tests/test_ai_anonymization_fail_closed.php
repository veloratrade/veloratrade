<?php

declare(strict_types=1);

/**
 * Runtime test — ImageAnonymizer fail-closed behavior (requires GD).
 *
 * Verifies the real failure paths, not just the existence of the class:
 *   - empty input       -> null
 *   - non-image input   -> null
 *   - valid image       -> non-null, different bytes, getInfo() truthful
 *   - tiny image        -> null
 *   - getInfo() reflects the real anonymization state
 *
 * Run: php tools/tests/test_ai_anonymization_fail_closed.php
 */

require __DIR__ . '/../../api/src/bootstrap.php';

use Velora\AI\Security\ImageAnonymizer;

$failures = 0;
$checks = 0;

function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  PASS: $label\n";
    } else {
        echo "  FAIL: $label\n";
        $failures++;
    }
}

echo "=== ImageAnonymizer fail-closed runtime test ===\n";

// 1. Empty input
check(ImageAnonymizer::anonymize('') === null, 'empty input returns null');

// 2. Non-image input
check(ImageAnonymizer::anonymize('this is not an image at all') === null, 'non-image input returns null');
check(ImageAnonymizer::anonymize(random_bytes(64)) === null, 'random bytes return null');

// 3. getInfo() reports failure state after failures
$info = ImageAnonymizer::getInfo();
check($info['anonymized'] === false, 'getInfo() reports anonymized=false after failure');
check($info['fail_closed'] === true, 'getInfo() reports fail_closed=true');

// 4. Valid image -> successful anonymization, different bytes
$valid = makeTestImage(200, 100);
$anon = ImageAnonymizer::anonymize($valid);
check(is_string($anon) && $anon !== '', 'valid image anonymized (non-empty result)');
if (is_string($anon) && $anon !== '') {
    check(hash('sha256', $anon) !== hash('sha256', $valid), 'anonymized bytes differ from original');
    $info2 = ImageAnonymizer::getInfo();
    check($info2['anonymized'] === true, 'getInfo() reports anonymized=true after success');
    check($info2['method'] === 'blur_top_15_percent', 'getInfo() reports method blur_top_15_percent');
}

// 5. Tiny image (below 10px) must fail closed
$tiny = makeTestImage(5, 5);
check(ImageAnonymizer::anonymize($tiny) === null, 'tiny image (<10px) returns null');

echo "=== RESULT: $checks checks, $failures failures ===\n";
exit($failures === 0 ? 0 : 1);

/**
 * Build a small PNG/JPEG image bytes with GD.
 */
function makeTestImage(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 255, 255, 255);
    $red = imagecolorallocate($im, 200, 30, 30);
    imagefilledrectangle($im, 0, 0, $w, $h, $white);
    imagefilledrectangle($im, 0, 0, $w, max(1, (int)($h * 0.2)), $red);
    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);
    return $bytes;
}
