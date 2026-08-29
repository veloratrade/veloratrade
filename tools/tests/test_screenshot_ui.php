<?php

declare(strict_types=1);

/**
 * Screenshot smart-import UI contract tests (multi-provider failover phase).
 *
 * Verifies public/assets/velora-smart-import.js consumes the structured
 * extraction of external AI providers while keeping the tesseract/browser-OCR
 * path fully intact, and adds NO new hardcoded UI literals (freeze-safe).
 *
 * Run: php tools/tests/test_screenshot_ui.php
 */

$root = dirname(__DIR__, 2);
$failures = 0;
$checks = 0;
function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    echo ($cond ? '  PASS: ' : '  FAIL: ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$js = (string) file_get_contents($root . '/public/assets/velora-smart-import.js');

echo "== Structured extraction consumed (external AI providers) ==\n";
check(strpos($js, 'data.extraction') !== false || strpos($js, 'extraction || data.data') !== false, 'ocrServer reads data.extraction (with data alias)');
check(strpos($js, 'window.__vsiExtraction') !== false, 'extraction stashed for the merge step');
check(substr_count($js, 'extractionToParsed') >= 3, 'extractionToParsed defined and wired');
check(strpos($js, "provider === 'gemini' || provider === 'openai' || provider === 'claude'") !== false, 'gemini/openai/claude treated as authoritative external AI');
check(strpos($js, "if (aiParsed.fields[key]) delete row.fields[key];") !== false && strpos($js, 'parsed = [aiParsed].concat(parsed);') !== false, 'AI row is authoritative: merged ahead of OCR, which only supplements fields the AI lacks');
check(strpos($js, "if (!external) return null;") !== false, 'tesseract/cache rows do NOT take the AI path (stay supplementary)');

echo "== Field mapping to review form ==\n";
foreach ([
    ["put('symbol', f.symbol)", 'symbol'],
    ["put('direction', side)", 'side → direction'],
    ["put('volume', f.lot)", 'lot → volume'],
    ["put('entryPrice', f.entry)", 'entry → entryPrice'],
    ["put('exitPrice', f.exit)", 'exit → exitPrice'],
    ["put('stopLoss', f.sl)", 'sl → stopLoss'],
    ["put('takeProfit', f.tp)", 'tp → takeProfit'],
    ["put('profitLoss', f.pnl)", 'pnl → profitLoss'],
    ["put('openTime', f.openTime)", 'openTime'],
    ["put('closeTime', f.closeTime)", 'closeTime'],
] as [$needle, $label]) {
    check(strpos($js, $needle) !== false, "mapping: {$label}");
}
check(strpos($js, 'NUMERIC_KEYS[key]') !== false, 'numeric fields pass the same normalizeNumber gate as OCR values');
check(strpos($js, "side === 'buy' || side === 'sell'") !== false, 'direction validated to buy/sell only');

echo "== Tesseract + browser OCR path intact ==\n";
check(strpos($js, 'function parseMt(') !== false || strpos($js, 'function parseMt (') !== false || preg_match('/function parseMt\s*\(/', $js) === 1, 'parseMt text pipeline still present');
check(strpos($js, 'looksLikeTrade') !== false, 'trade-likeness heuristic still present');
check(strpos($js, 'tesseract.js@5.1.1') !== false && strpos($js, 'function ocrFast') !== false, 'browser OCR (tesseract.js) untouched');
check(strpos($js, 'window.__vsiTimes = data.times') !== false, 'times contract unchanged');
check(strpos($js, 'throw new Error(\'empty\')') !== false, 'empty-response guard kept (unless valid AI extraction exists)');

echo "== No new UI literals / no new inline scripts ==\n";
// Persian literals must be IDENTICAL to the committed version of this asset:
// any new hardcoded UI string would break the freeze checker in CI.
function persianLiteralCount(string $code): int {
    preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`/s', $code, $lits);
    $set = [];
    foreach ($lits[0] as $lit) {
        $lit = substr($lit, 1, -1);
        $norm = preg_replace('/\s+/u', ' ', trim($lit));
        if ($norm !== '' && preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $lit)) {
            $set[$norm] = true;
        }
    }
    return count($set);
}
$baselineCode = @shell_exec('git -C ' . escapeshellarg($root) . ' show HEAD:public/assets/velora-smart-import.js 2>/dev/null');
if ($baselineCode === null || $baselineCode === '') {
    echo "  SKIP: hardcoded-literal freeze comparison (git baseline unavailable)\n";
} else {
    $base = persianLiteralCount($baselineCode);
    $current = persianLiteralCount($js);
    check($current === $base, "hardcoded Persian literal set unchanged (baseline {$base}, current {$current})");
}
$html = (string) file_get_contents($root . '/trades/new/index.html');
check(strpos($html, 'velora-smart-import.js?v=') !== false, 'page still loads the asset via versioned script tag (no inline copy)');


echo "== Freshness: new screenshot cannot inherit the previous run ==\n";
$js = (string) file_get_contents($root . '/public/assets/velora-smart-import.js');
$resetPos = strpos($js, "window.__vsiExtraction = null;\n    var data = await window.VeloraData.request('/api/v1/trades/extract-screenshot'");
$reqPos = strpos($js, "/api/v1/trades/extract-screenshot");
check($resetPos !== false, 'previous extraction is dropped BEFORE the extract request (failed request cannot repopulate the form)');
check(strpos($js, 'aiParsed.fields[key]) delete row.fields[key];') !== false, 'OCR rows only supplement fields the external AI result lacks (AI value wins, no silent overwrite)');
check(strpos($js, "__vsiTimes.openTime && !merged.openTime") !== false, 'server times fill missing values only — never overwrite AI-provided open/close times');
check(strpos($js, "window.__vsiExtraction = null;") !== false && strpos($js, 'runExtract') !== false, 'runExtract path keeps its reset-on-entry');

echo "\nscreenshot-ui: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
