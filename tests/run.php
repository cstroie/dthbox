<?php
// Minimal self-contained tests for the GD-independent parts of DitherBox.
// Run with: php tests/run.php
define('DTHBOX_NO_RUN', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/../index.php';

$fails = 0;
function check($name, $cond) {
    global $fails;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$cond) { $fails++; }
}
function expectThrow($name, $fn, $code = null) {
    try { $fn(); check($name, false); }
    catch (DthException $e) { check($name, $code === null || $e->getCode() === $code); }
}
function rowReader($rows) { return function ($y) use ($rows) { return $rows[$y]; }; }
function flat($rows) { $o = []; foreach ($rows as $r) { $o = array_merge($o, array_values(unpack('C*', $r))); } return $o; }

// --- kernels: weights must sum to 1 (or less for Atkinson)
foreach (diffusionKernels() as $name => $k) {
    $sum = 0;
    foreach ($k['taps'] as $t) { $sum += $t[2]; }
    check("kernel $name sum", $name === 'ak' ? $sum === 6 && $k['div'] === 8 : $sum === $k['div']);
    foreach ($k['taps'] as $t) {
        check("kernel $name tap causal", $t[1] > 0 || ($t[1] === 0 && $t[0] > 0));
        if ($t[1] > 2 || abs($t[0]) > 2) { check("kernel $name within padding", false); }
    }
}

// --- error diffusion preserves average brightness (mid gray -> ~50% white)
$w = 64; $h = 64;
$rows = array_fill(0, $h, array_fill(0, $w, 127.5));
foreach (['fs', 'ak', 'jv', 'sk', 'bk', 'sr', 'sl'] as $m) {
    foreach ([false, true] as $serp) {
        $out = ditherRows($w, $h, rowReader($rows), 2, $m, false, $serp);
        $mean = array_sum(flat($out)) / ($w * $h);
        // Atkinson deliberately loses 25% of the error, so allow a wider band for it
        $tol = $m === 'ak' ? 0.2 : 0.05;
        check("$m serp=" . (int)$serp . " mean ~0.5 ($mean)", abs($mean - 0.5) < $tol);
    }
}
// two-row diffusion actually reaches row y+2: a dark pixel on a bright field
$rows = array_fill(0, 8, array_fill(0, 8, 255.0));
$rows[0][3] = 100.0;
$out = flat(ditherRows(8, 8, rowReader($rows), 2, 'jv', false, false));
check('jv: input value 255 stays white away from error', $out[7 * 8 + 7] === 1);

// --- levels: indexes in range
$rows = [range(0, 255)];
foreach ([2, 4, 16, 256] as $lv) {
    foreach (array_keys(DTH_METHODS) as $m) {
        $out = flat(ditherRows(256, 1, rowReader($rows), $lv, $m, true, false));
        check("$m/$lv indexes in range", max($out) <= $lv - 1 && min($out) >= 0);
    }
}
// 256 levels = identity
$out = flat(ditherRows(256, 1, rowReader([range(0, 255)]), 256, 'fs', false, false));
check('256 levels identity', $out === range(0, 255));

// --- Bayer: flat mid gray gives ~50% and an ordered pattern; matrix is a permutation
foreach ([2, 4, 8] as $n) {
    $m = bayerMatrix($n);
    $vals = []; foreach ($m as $r) { $vals = array_merge($vals, $r); } sort($vals);
    check("bayer $n permutation", $vals === range(0, $n * $n - 1));
    $rows = array_fill(0, $n * 4, array_fill(0, $n * 4, 127.5));
    $out = flat(ditherOrdered($n * 4, $n * 4, rowReader($rows), 2, $n));
    check("bayer $n mid gray ~0.5", abs(array_sum($out) / count($out) - 0.5) < 0.01);
}

// --- tone LUT
$id = buildToneLut(0, 0, 1.0, false);
check('identity LUT', $id[0] == 0 && $id[128] == 128 && $id[255] == 255);
$inv = buildToneLut(0, 0, 1.0, true);
check('invert LUT', $inv[0] == 255 && $inv[255] == 0);
check('brightness LUT', buildToneLut(50, 0, 1.0, false)[100] > 100);
check('gamma>1 brightens', buildToneLut(0, 0, 2.0, false)[64] > 64);
check('contrast pivots at 128', abs(buildToneLut(0, 50, 1.0, false)[128] - 128) < 1 && buildToneLut(0, 50, 1.0, false)[64] < 64);

// --- encoders
$r = ['w' => 10, 'h' => 2, 'levels' => 2, 'rows' => [packBytes([0,1,0,1,0,1,0,1,0,1]), packBytes(array_fill(0, 10, 1))]];
$pbm = encodePbm($r);
$hdr = "P4\n10 2\n";
check('pbm header', strpos($pbm, $hdr) === 0);
$body = substr($pbm, strlen($hdr));
check('pbm size (2 bytes/row)', strlen($body) === 4);
check('pbm black=1 (index 0 -> bit set)', ord($body[0]) === 0b10101010 && ord($body[1]) === 0b10000000);
check('pbm all white row is 0', ord($body[2]) === 0 && ord($body[3]) === 0);
$pgm = encodePgm(['w' => 3, 'h' => 1, 'levels' => 4, 'rows' => [packBytes([0, 1, 3])]]);
check('pgm maps 4 levels', substr($pgm, -3) === chr(0) . chr(85) . chr(255));
$ppm = encodePpm(['w' => 2, 'h' => 1, 'levels' => 2, 'rows' => [packBytes([0, 1])]]);
check('ppm rgb triples', substr($ppm, -6) === "\0\0\0\xff\xff\xff");

// --- resolution parsing
check('res WxH', parseRes('296x128') === ['w' => 296, 'h' => 128, 'max' => false]);
check('res clamp', parseRes('9999x1')['w'] === MAX_DIM);
check('res single', parseRes('300') === ['w' => 300, 'h' => 300, 'max' => true]);
check('res invalid -> default', parseRes('abc') === ['w' => 400, 'h' => 300, 'max' => false]);

// --- geometry
$g = computeGeometry(1000, 500, ['w' => 100, 'h' => 100, 'max' => false], 'cover');
check('cover crops width', $g['sw'] === 500 && $g['sh'] === 500 && $g['sx'] === 250 && $g['cw'] === 100);
$g = computeGeometry(1000, 500, ['w' => 100, 'h' => 100, 'max' => false], 'contain');
check('contain pads', $g['dw'] === 100 && $g['dh'] === 50 && $g['dy'] === 25 && $g['cw'] === 100 && $g['ch'] === 100);
$g = computeGeometry(1000, 500, ['w' => 200, 'h' => 200, 'max' => true], 'cover');
check('max size keeps ratio', $g['cw'] === 200 && $g['ch'] === 100);
$g = computeGeometry(10, 10000, ['w' => 100, 'h' => 100, 'max' => true], 'cover');
check('max size min 1', $g['cw'] === 1 && $g['ch'] === 100);

// --- url helpers / SSRF
expectThrow('reject file://', function () { validateRemoteUrl('file:///etc/passwd'); }, 400);
expectThrow('reject ftp://', function () { validateRemoteUrl('ftp://example.com/x'); }, 400);
expectThrow('reject php://', function () { validateRemoteUrl('php://filter/resource=index.php'); }, 400);
expectThrow('reject loopback', function () { validateRemoteUrl('http://127.0.0.1/x'); }, 400);
expectThrow('reject metadata', function () { validateRemoteUrl('http://169.254.169.254/latest'); }, 400);
expectThrow('reject private', function () { validateRemoteUrl('http://10.0.0.5/x'); }, 400);
expectThrow('reject v6 loopback', function () { validateRemoteUrl('http://[::1]/x'); }, 400);
expectThrow('reject v4-mapped', function () { validateRemoteUrl('http://[::ffff:127.0.0.1]/x'); }, 400);
expectThrow('reject creds', function () { validateRemoteUrl('http://user:pw@8.8.8.8/x'); }, 400);
check('allow public ip', validateRemoteUrl('https://8.8.8.8/x') === ['host' => '8.8.8.8', 'port' => 443, 'ip' => '8.8.8.8']);
check('resolve relative', resolveUrl('http://a.com/x/y.rss', 'i.jpg') === 'http://a.com/x/i.jpg');
check('resolve root', resolveUrl('http://a.com:8080/x/y.rss', '/i.jpg') === 'http://a.com:8080/i.jpg');
check('resolve proto-relative', resolveUrl('https://a.com/', '//b.com/i.jpg') === 'https://b.com/i.jpg');

// --- feed parsing
$rss = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/"><channel>
<item><enclosure url="http://x.com/a.jpg?w=1" type="image/jpeg"/></item>
<item><description><![CDATA[<img src="/rel/b.png?x=1&amp;y=2">]]></description></item>
<item><content:encoded><![CDATA[<p><img class="c" src=\'http://x.com/c.jpeg\'></p><img src="http://x.com/avatar.jpg">]]></content:encoded></item>
<item><media:content url="http://x.com/d.jpg" medium="image"/></item>
</channel></rss>';
$urls = parseFeedImages($rss, 'http://x.com/feed', 'T');
check('feed: enclosure with query', in_array('http://x.com/a.jpg?w=1', $urls));
check('feed: relative + entity', in_array('http://x.com/rel/b.png?x=1&y=2', $urls));
check('feed: content:encoded', in_array('http://x.com/c.jpeg', $urls));
check('feed: avatar filtered', !in_array('http://x.com/avatar.jpg', $urls));
check('feed: media:content', in_array('http://x.com/d.jpg', $urls));
$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><content type="html">&lt;img src="http://y.com/e.jpg"&gt;</content></entry></feed>';
check('atom feed', parseFeedImages($atom, 'http://y.com/f', 'A') === ['http://y.com/e.jpg']);
expectThrow('bad xml', function () { parseFeedImages('<nope', 'http://y.com/', 'A'); }, 502);

// --- settings
$_GET = ['dth' => '<script>', 'bits' => '99', 'rb' => '0', 'fmt' => 'JPEG', 'rotate' => '450', 'gamma' => 'x'];
$s = readSettings();
check('dth whitelisted', $s['dth'] === 'fs');
check('bits clamped', $s['bits'] === 8 && $s['levels'] === 256);
check('rb=0 false', $s['rb'] === false);
check('jpeg normalised', $s['fmt'] === 'jpg');
check('rotate 450 -> 90', $s['rotate'] === 90);
check('bad gamma -> 1', $s['gamma'] === 1.0);
check('defaults', (function () { $_GET = []; $s = readSettings(); return $s['rb'] === true && $s['dth'] === 'fs' && $s['bits'] === 1; })());

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
