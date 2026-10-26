<?php
/*
 * DitherBox - A PHP-based image dithering tool
 * Copyright (C) 2025 Costin Stroie <costinstroie@eridu.eu.org>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Project: DitherBox
 * Repository: https://github.com/cstroie/dthbox
 */

// Fetch an image (URL, upload, or random from an RSS collection), crop and
// scale it, convert it to grayscale, dither it and return it in the requested
// format.
//
// Environment variables (all optional):
//   DTHBOX_RATE           max processing requests per IP per minute (default 60, 0 = off)
//   DTHBOX_ALLOW_PRIVATE  set to 1 to allow fetching from private/loopback addresses

const DTHBOX_UA = 'DitherBox/1.0 (+https://github.com/cstroie/dthbox)';
const MAX_DIM = 2000;               // max output width/height
const MAX_DOWNLOAD = 15728640;      // max bytes for a downloaded/uploaded image (15 MiB)
const MAX_SRC_PIXELS = 40000000;    // max source image pixels (decompression bomb guard)
const MAX_REDIRECTS = 5;
const FEED_CACHE_TTL = 1800;        // seconds
const DEF_RES = '400x300';

const COLLECTIONS = [
    'apod' => [
        'id' => 'apod',
        'name' => 'Astronomy Picture of the Day',
        'url' => 'https://apod.com/feed.rss'
    ],
    'tic' => [
        'id' => 'tic',
        'name' => 'This Is Colossal',
        'url' => 'https://www.thisiscolossal.com/feed/'
    ],
    'jux' => [
        'id' => 'jux',
        'name' => 'Juxtapoz',
        'url' => 'https://www.juxtapoz.com/news/?format=feed&type=rss'
    ],
    'veri' => [
        'id' => 'veri',
        'name' => 'Veri Artem',
        'url' => 'https://veriartem.com/feed/'
    ]
];

const FORMATS = ['png', 'jpg', 'jpeg', 'ppm', 'pgm', 'pbm', 'gif'];

const DTH_METHODS = [
    'none' => 'None',
    'fs' => 'Floyd-Steinberg',
    'ak' => 'Atkinson',
    'jv' => 'Jarvis, Judice & Ninke',
    'sk' => 'Stucki',
    'bk' => 'Burkes',
    'sr' => 'Sierra',
    'sl' => 'Sierra Lite',
    'by' => 'Bayer 2x2',
    'by4' => 'Bayer 4x4',
    'by8' => 'Bayer 8x8'
];

/**
 * Exception carrying a message that is safe to show to the user.
 * The exception code is used as the HTTP status.
 */
class DthException extends Exception {
}

/* ------------------------------------------------------------------------
 * Request parameters
 * --------------------------------------------------------------------- */

/**
 * Read a string request parameter, POST taking precedence over GET.
 *
 * @param string $name Parameter name
 * @param string|null $default Value returned when the parameter is missing
 * @return string|null
 */
function param($name, $default = null) {
    if (isset($_POST[$name]) && is_string($_POST[$name])) {
        return $_POST[$name];
    }
    if (isset($_GET[$name]) && is_string($_GET[$name])) {
        return $_GET[$name];
    }
    return $default;
}

/**
 * Interpret a request parameter as a boolean.
 *
 * @param string|null $value Raw value
 * @param bool $default Value used when the parameter is missing
 * @return bool
 */
function parseBool($value, $default) {
    if ($value === null) {
        return $default;
    }
    return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
}

/**
 * Parse a resolution string: "WxH", or a single number meaning "fit in a
 * square of that size". Falls back to the default resolution.
 *
 * @param string $res Resolution string
 * @return array ['w' => int, 'h' => int, 'max' => bool]
 */
function parseRes($res) {
    $res = trim($res);
    if (preg_match('/^(\d{1,5})x(\d{1,5})$/i', $res, $m)) {
        return [
            'w' => max(1, min(MAX_DIM, (int)$m[1])),
            'h' => max(1, min(MAX_DIM, (int)$m[2])),
            'max' => false
        ];
    }
    if (ctype_digit($res) && strlen($res) <= 5) {
        $size = max(1, min(MAX_DIM, (int)$res));
        return ['w' => $size, 'h' => $size, 'max' => true];
    }
    return parseRes(DEF_RES);
}

/**
 * Build the list of processing settings from the request.
 *
 * @return array Validated settings
 */
function readSettings() {
    $fmt = strtolower(param('fmt', 'png'));
    if (!in_array($fmt, FORMATS, true)) {
        $fmt = 'png';
    }
    if ($fmt === 'jpeg') {
        $fmt = 'jpg';
    }

    $dth = strtolower(param('dth', 'fs'));
    if (!isset(DTH_METHODS[$dth])) {
        $dth = 'fs';
    }

    $gamma = param('gamma', '1');
    $gamma = is_numeric($gamma) ? max(0.1, min(5.0, (float)$gamma)) : 1.0;

    $rotate = param('rotate', '0');
    $rotate = is_numeric($rotate) ? ((((int)$rotate % 360) + 360) % 360) : 0;
    if ($rotate % 90 !== 0) {
        $rotate = 0;
    }

    $fit = strtolower(param('fit', 'cover'));
    if ($fit !== 'contain') {
        $fit = 'cover';
    }

    $source = strtolower(param('image_source', ''));
    if (!in_array($source, ['url', 'file', 'collection'], true)) {
        $source = '';
    }

    $bits = max(1, min(8, (int)param('bits', '1')));

    return [
        'fmt' => $fmt,
        'bits' => $bits,
        'levels' => 1 << $bits,
        'res' => parseRes(param('res', DEF_RES)),
        'dth' => $dth,
        'rb' => parseBool(param('rb'), true),
        'serp' => parseBool(param('serp'), false),
        'invert' => parseBool(param('invert'), false),
        'brightness' => max(-100, min(100, (int)param('brightness', '0'))),
        'contrast' => max(-100, min(100, (int)param('contrast', '0'))),
        'gamma' => $gamma,
        'rotate' => $rotate,
        'fit' => $fit,
        'source' => $source,
        'ui' => $_SERVER['REQUEST_METHOD'] === 'POST' && param('ui') === '1'
    ];
}

/* ------------------------------------------------------------------------
 * Safe remote fetching
 * --------------------------------------------------------------------- */

/**
 * Check whether an IP address may be contacted.
 *
 * @param string $ip IPv4 or IPv6 address
 * @return bool
 */
function isAllowedIp($ip) {
    if (getenv('DTHBOX_ALLOW_PRIVATE') === '1') {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    // IPv4-mapped IPv6 addresses: check the embedded IPv4 address
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
        $ip = $m[1];
    }
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Resolve a host name to its IPv4 and IPv6 addresses.
 *
 * @param string $host Host name
 * @return array List of IP addresses
 */
function resolveHost($host) {
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    $v6 = @dns_get_record($host, DNS_AAAA);
    if (is_array($v6)) {
        foreach ($v6 as $rec) {
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
    }
    return $ips;
}

/**
 * Validate a remote URL: only http(s), no credentials, and the host must not
 * resolve to a private, loopback or reserved address (SSRF protection).
 *
 * @param string $url URL to validate
 * @return array ['host' => string, 'port' => int, 'ip' => string]
 * @throws DthException If the URL is not acceptable
 */
function validateRemoteUrl($url) {
    $p = parse_url($url);
    if ($p === false || empty($p['scheme']) || empty($p['host'])) {
        throw new DthException('Invalid URL provided', 400);
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new DthException('Only http and https URLs are supported', 400);
    }
    if (isset($p['user']) || isset($p['pass'])) {
        throw new DthException('URLs with credentials are not supported', 400);
    }
    $host = trim($p['host'], '[]');
    $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);

    $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : resolveHost($host);
    $allowed = [];
    foreach ($ips as $ip) {
        if (isAllowedIp($ip)) {
            $allowed[] = $ip;
        }
    }
    if (empty($allowed) || count($allowed) !== count($ips)) {
        // Refuse hosts with any disallowed address (DNS tricks) or no address at all
        throw new DthException('The URL host is not allowed or cannot be resolved', 400);
    }
    // Prefer IPv4 for the pinned connection
    $pick = $allowed[0];
    foreach ($allowed as $ip) {
        if (strpos($ip, ':') === false) {
            $pick = $ip;
            break;
        }
    }
    return ['host' => $host, 'port' => $port, 'ip' => $pick];
}

/**
 * Resolve a possibly relative URL against a base URL.
 *
 * @param string $base Absolute base URL
 * @param string $rel Absolute, protocol-relative, root-relative or relative URL
 * @return string Absolute URL
 */
function resolveUrl($base, $rel) {
    $rel = trim($rel);
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel)) {
        return $rel;
    }
    $p = parse_url($base);
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'http';
    $authority = (isset($p['host']) ? $p['host'] : '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($rel, '//') === 0) {
        return $scheme . ':' . $rel;
    }
    if (isset($rel[0]) && $rel[0] === '/') {
        return $scheme . '://' . $authority . $rel;
    }
    $path = isset($p['path']) ? $p['path'] : '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $scheme . '://' . $authority . $dir . $rel;
}

/**
 * Download a URL with SSRF protection, size and time limits.
 * Every redirect hop is validated again, and the connection is pinned to the
 * validated IP address to defeat DNS rebinding.
 *
 * @param string $url URL to fetch
 * @param int $maxBytes Maximum response size
 * @return string Response body
 * @throws DthException On any failure
 */
function safeFetch($url, $maxBytes = MAX_DOWNLOAD) {
    for ($hop = 0; $hop <= MAX_REDIRECTS; $hop++) {
        $target = validateRemoteUrl($url);
        if (!function_exists('curl_init')) {
            throw new DthException('The PHP cURL extension is required', 500);
        }
        $body = '';
        $tooBig = false;
        $location = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => DTHBOX_UA,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $tooBig = true;
                    return 0;
                }
                return strlen($chunk);
            }
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooBig) {
            throw new DthException('Remote file is too large', 413);
        }
        if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== null) {
            $url = resolveUrl($url, $location);
            continue;
        }
        if ($errno !== 0) {
            throw new DthException('Failed to fetch the remote resource', 502);
        }
        if ($code !== 200) {
            throw new DthException("Remote server returned HTTP $code", 502);
        }
        return $body;
    }
    throw new DthException('Too many redirects', 502);
}

/* ------------------------------------------------------------------------
 * Rate limiting
 * --------------------------------------------------------------------- */

/**
 * Simple per-IP fixed-window rate limiter backed by a temp file.
 *
 * @throws DthException With code 429 when the limit is exceeded
 */
function rateLimit() {
    $env = getenv('DTHBOX_RATE');
    $limit = $env === false ? 60 : (int)$env;
    if ($limit <= 0) {
        return;
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
    $file = sys_get_temp_dir() . '/dthbox_rl_' . md5($ip);
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    $parts = explode('|', (string)stream_get_contents($fp));
    $now = time();
    $start = isset($parts[0]) ? (int)$parts[0] : 0;
    $count = isset($parts[1]) ? (int)$parts[1] : 0;
    if ($now - $start >= 60) {
        $start = $now;
        $count = 0;
    }
    $count++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $start . '|' . $count);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($count > $limit) {
        header('Retry-After: ' . max(1, 60 - ($now - $start)));
        throw new DthException('Rate limit exceeded, try again later', 429);
    }
}

/* ------------------------------------------------------------------------
 * RSS collections
 * --------------------------------------------------------------------- */

/**
 * Extract image URLs from the HTML snippet of a feed item.
 *
 * @param string $html HTML fragment
 * @return array List of (possibly relative) image URLs
 */
function imagesFromHtml($html) {
    $urls = [];
    if ($html !== '' && preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            $urls[] = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $urls;
}

/**
 * Tell whether an URL looks like an image GD can decode and that is not an
 * icon, avatar or tracking pixel.
 *
 * @param string $url Absolute URL
 * @return bool
 */
function isUsableImageUrl($url) {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)) {
        return false;
    }
    if (preg_match('/\.webp$/i', $path) && function_exists('imagetypes') && !(imagetypes() & 32)) {
        return false;
    }
    return !preg_match('/avatar|gravatar|favicon|emoji|logo|icon|pixel|1x1|spacer/i', $path);
}

/**
 * Parse an RSS 2.0 or Atom feed and return the image URLs it references.
 * Looks at enclosures, media:content, and img tags in the item bodies.
 *
 * @param string $xml Feed document
 * @param string $feedUrl Feed URL (to resolve relative image URLs)
 * @param string $siteName Site name (for error messages)
 * @return array Absolute image URLs
 * @throws DthException If the feed cannot be parsed or has no images
 */
function parseFeedImages($xml, $feedUrl, $siteName) {
    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($feed === false) {
        throw new DthException("Failed to parse $siteName feed", 502);
    }

    $items = [];
    if (isset($feed->channel->item)) {
        foreach ($feed->channel->item as $item) {
            $items[] = $item;
        }
    }
    if (isset($feed->entry)) {
        foreach ($feed->entry as $entry) {
            $items[] = $entry;
        }
    }

    $ns = [
        'media' => 'http://search.yahoo.com/mrss/',
        'content' => 'http://purl.org/rss/1.0/modules/content/'
    ];
    $found = [];
    foreach ($items as $item) {
        $cands = [];
        foreach ($item->enclosure as $enc) {
            $type = (string)$enc['type'];
            if ($type === '' || stripos($type, 'image/') === 0) {
                $cands[] = (string)$enc['url'];
            }
        }
        foreach ($item->link as $link) {   // Atom enclosure links
            if ((string)$link['rel'] === 'enclosure' && stripos((string)$link['type'], 'image/') === 0) {
                $cands[] = (string)$link['href'];
            }
        }
        $media = $item->children($ns['media']);
        foreach ($media->content as $mc) {
            $attr = $mc->attributes();   // attributes of namespaced elements need attributes()
            $medium = (string)$attr['medium'];
            $type = (string)$attr['type'];
            if ($medium === 'image' || stripos($type, 'image/') === 0 || ($medium === '' && $type === '')) {
                $cands[] = (string)$attr['url'];
            }
        }
        $html = (string)$item->description . (string)$item->content . (string)$item->summary
            . (string)$item->children($ns['content'])->encoded;
        $cands = array_merge($cands, imagesFromHtml($html));

        foreach ($cands as $c) {
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            $abs = resolveUrl($feedUrl, $c);
            if (isUsableImageUrl($abs)) {
                $found[$abs] = true;
            }
        }
    }
    if (empty($found)) {
        throw new DthException("No usable images found in $siteName feed", 502);
    }
    return array_keys($found);
}

/**
 * Get the image URL list for a collection, cached on disk.
 * A stale cache is used if the feed cannot be fetched.
 *
 * @param array $collection Collection definition
 * @return array Image URLs
 * @throws DthException If no cache exists and the feed cannot be used
 */
function collectionImageUrls($collection) {
    $cacheFile = sys_get_temp_dir() . '/dthbox_feed_' . md5($collection['url']) . '.json';
    $cached = null;
    if (is_file($cacheFile)) {
        $data = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($data) && !empty($data)) {
            $cached = $data;
            if (time() - filemtime($cacheFile) < FEED_CACHE_TTL) {
                return $cached;
            }
        }
    }
    try {
        $urls = parseFeedImages(safeFetch($collection['url'], 5242880), $collection['url'], $collection['name']);
    } catch (DthException $e) {
        if ($cached !== null) {
            return $cached;
        }
        throw $e;
    }
    $tmp = $cacheFile . '.' . getmypid();
    if (@file_put_contents($tmp, json_encode($urls)) !== false) {
        @rename($tmp, $cacheFile);
    }
    return $urls;
}

/**
 * Fetch a random image from a collection, retrying with other images when one
 * fails or is too small to be useful.
 *
 * @param string $collectionId Collection ID
 * @return string Image data
 * @throws DthException If no image could be fetched
 */
function fetchRandomImage($collectionId) {
    if (!isset(COLLECTIONS[$collectionId])) {
        throw new DthException("Invalid collection: $collectionId", 400);
    }
    $collection = COLLECTIONS[$collectionId];
    $urls = collectionImageUrls($collection);
    shuffle($urls);
    $last = null;
    foreach (array_slice($urls, 0, 4) as $url) {
        try {
            $data = safeFetch($url);
            $info = @getimagesizefromstring($data);
            if ($info !== false && $info[0] >= 64 && $info[1] >= 64) {
                return $data;
            }
        } catch (DthException $e) {
            $last = $e;
        }
    }
    throw new DthException('Failed to fetch an image from ' . $collection['name'], $last ? $last->getCode() : 502);
}

/* ------------------------------------------------------------------------
 * Image acquisition
 * --------------------------------------------------------------------- */

/**
 * Read the image bytes from the request.
 *
 * @param array $s Settings
 * @return array [string $data, bool $cacheable]
 * @throws DthException If no image can be obtained
 */
function acquireImage($s) {
    $url = trim((string)param('url', ''));
    $file = isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null;
    $hasFile = $file && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK;
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    $source = $s['source'];
    if ($source === '') {
        if ($url !== '') {
            $source = 'url';
        } elseif ($hasFile) {
            $source = 'file';
        } elseif (param('col') !== null || !$isPost) {
            $source = 'collection';
        } else {
            $source = 'body';
        }
    }

    switch ($source) {
        case 'url':
            if ($url === '') {
                throw new DthException('No image URL provided', 400);
            }
            return [safeFetch($url), true];

        case 'file':
            if (!$hasFile) {
                $err = $file && isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new DthException('Uploaded file is too large', 413);
                }
                throw new DthException('No image uploaded', 400);
            }
            if ($file['size'] > MAX_DOWNLOAD) {
                throw new DthException('Uploaded file is too large', 413);
            }
            $data = file_get_contents($file['tmp_name']);
            if ($data === false) {
                throw new DthException('Failed to read uploaded image', 500);
            }
            return [$data, false];

        case 'collection':
            $col = strtolower((string)param('col', 'any'));
            if ($col === 'any' || $col === '') {
                $keys = array_keys(COLLECTIONS);
                $col = $keys[array_rand($keys)];
            } elseif (!isset(COLLECTIONS[$col])) {
                throw new DthException("Invalid collection: $col", 400);
            }
            return [fetchRandomImage($col), false];

        default:
            $fp = fopen('php://input', 'rb');
            $data = $fp ? stream_get_contents($fp, MAX_DOWNLOAD + 1) : '';
            if ($data === false || $data === '') {
                throw new DthException('No image data provided', 400);
            }
            if (strlen($data) > MAX_DOWNLOAD) {
                throw new DthException('Request body is too large', 413);
            }
            return [$data, false];
    }
}

/* ------------------------------------------------------------------------
 * Geometry and tone
 * --------------------------------------------------------------------- */

/**
 * Compute the canvas size and the source/destination rectangles.
 *
 * @param int $sw Source width
 * @param int $sh Source height
 * @param array $res Result of parseRes()
 * @param string $fit 'cover' (crop to fill) or 'contain' (pad with white)
 * @return array Keys: cw, ch (canvas), dx, dy, dw, dh (destination), sx, sy, sw, sh (source)
 */
function computeGeometry($sw, $sh, $res, $fit) {
    if ($res['max']) {
        // Fit inside a square box, keep aspect ratio, no cropping
        $scale = $res['w'] / max($sw, $sh);
        $cw = max(1, (int)round($sw * $scale));
        $ch = max(1, (int)round($sh * $scale));
        return ['cw' => $cw, 'ch' => $ch, 'dx' => 0, 'dy' => 0, 'dw' => $cw, 'dh' => $ch,
                'sx' => 0, 'sy' => 0, 'sw' => $sw, 'sh' => $sh];
    }
    $cw = $res['w'];
    $ch = $res['h'];
    if ($fit === 'contain') {
        $scale = min($cw / $sw, $ch / $sh);
        $dw = max(1, min($cw, (int)round($sw * $scale)));
        $dh = max(1, min($ch, (int)round($sh * $scale)));
        return ['cw' => $cw, 'ch' => $ch,
                'dx' => intdiv($cw - $dw, 2), 'dy' => intdiv($ch - $dh, 2), 'dw' => $dw, 'dh' => $dh,
                'sx' => 0, 'sy' => 0, 'sw' => $sw, 'sh' => $sh];
    }
    // cover: centre crop to the target aspect ratio
    $srcRatio = $sw / $sh;
    $dstRatio = $cw / $ch;
    if ($srcRatio > $dstRatio) {
        $cropW = max(1, min($sw, (int)round($sh * $dstRatio)));
        $cropH = $sh;
    } else {
        $cropW = $sw;
        $cropH = max(1, min($sh, (int)round($sw / $dstRatio)));
    }
    return ['cw' => $cw, 'ch' => $ch, 'dx' => 0, 'dy' => 0, 'dw' => $cw, 'dh' => $ch,
            'sx' => intdiv($sw - $cropW, 2), 'sy' => intdiv($sh - $cropH, 2), 'sw' => $cropW, 'sh' => $cropH];
}

/**
 * Build a 256-entry lookup table applying brightness, contrast, gamma and
 * inversion to 8-bit gray values.
 *
 * @param int $brightness -100..100
 * @param int $contrast -100..100
 * @param float $gamma 0.1..5 (values above 1 brighten midtones)
 * @param bool $invert Invert the result
 * @return array 256 floats in 0..255
 */
function buildToneLut($brightness, $contrast, $gamma, $invert) {
    $c = $contrast * 2.55;
    $factor = (259 * ($c + 255)) / (255 * (259 - $c));
    $lut = [];
    for ($i = 0; $i < 256; $i++) {
        $v = $i / 255;
        if ($gamma != 1.0) {
            $v = pow($v, 1 / $gamma);
        }
        $v = $v * 255;
        if ($contrast != 0) {
            $v = $factor * ($v - 128) + 128;
        }
        $v += $brightness * 2.55;
        $v = max(0.0, min(255.0, $v));
        $lut[$i] = $invert ? 255.0 - $v : $v;
    }
    return $lut;
}

/* ------------------------------------------------------------------------
 * Dithering core. These functions are independent of GD: they take a row
 * reader returning 0..255 gray values and produce rows of level indexes
 * (one byte per pixel, 0..levels-1).
 * --------------------------------------------------------------------- */

/**
 * Error diffusion kernels: each tap is [dx, dy, weight]; weights are divided
 * by 'div'.
 *
 * @return array
 */
function diffusionKernels() {
    return [
        'fs' => ['div' => 16, 'taps' => [[1, 0, 7], [-1, 1, 3], [0, 1, 5], [1, 1, 1]]],
        'ak' => ['div' => 8, 'taps' => [[1, 0, 1], [2, 0, 1], [-1, 1, 1], [0, 1, 1], [1, 1, 1], [0, 2, 1]]],
        'jv' => ['div' => 48, 'taps' => [
            [1, 0, 7], [2, 0, 5],
            [-2, 1, 3], [-1, 1, 5], [0, 1, 7], [1, 1, 5], [2, 1, 3],
            [-2, 2, 1], [-1, 2, 3], [0, 2, 5], [1, 2, 3], [2, 2, 1]]],
        'sk' => ['div' => 42, 'taps' => [
            [1, 0, 8], [2, 0, 4],
            [-2, 1, 2], [-1, 1, 4], [0, 1, 8], [1, 1, 4], [2, 1, 2],
            [-2, 2, 1], [-1, 2, 2], [0, 2, 4], [1, 2, 2], [2, 2, 1]]],
        'bk' => ['div' => 32, 'taps' => [
            [1, 0, 8], [2, 0, 4],
            [-2, 1, 2], [-1, 1, 4], [0, 1, 8], [1, 1, 4], [2, 1, 2]]],
        'sr' => ['div' => 32, 'taps' => [
            [1, 0, 5], [2, 0, 3],
            [-2, 1, 2], [-1, 1, 4], [0, 1, 5], [1, 1, 4], [2, 1, 2],
            [-1, 2, 2], [0, 2, 3], [1, 2, 2]]],
        'sl' => ['div' => 4, 'taps' => [[1, 0, 2], [-1, 1, 1], [0, 1, 1]]]
    ];
}

/**
 * Generate a Bayer threshold matrix of size n x n (n a power of two).
 *
 * @param int $n Matrix size
 * @return array Matrix of integers 0..n*n-1
 */
function bayerMatrix($n) {
    $m = [[0]];
    for ($size = 1; $size < $n; $size *= 2) {
        $next = [];
        for ($y = 0; $y < $size; $y++) {
            $a = [];
            $b = [];
            for ($x = 0; $x < $size; $x++) {
                $a[$x] = 4 * $m[$y][$x];
                $b[$x] = 4 * $m[$y][$x] + 2;
            }
            $next[$y] = array_merge($a, $b);
        }
        for ($y = 0; $y < $size; $y++) {
            $a = [];
            $b = [];
            for ($x = 0; $x < $size; $x++) {
                $a[$x] = 4 * $m[$y][$x] + 3;
                $b[$x] = 4 * $m[$y][$x] + 1;
            }
            $next[$size + $y] = array_merge($a, $b);
        }
        $m = $next;
    }
    return $m;
}

/**
 * Pack an array of small ints into a byte string.
 *
 * @param array $values Ints 0..255
 * @return string
 */
function packBytes($values) {
    return pack('C*', ...$values);
}

/**
 * Quantize without dithering.
 */
function ditherNone($w, $h, $readRow, $levels) {
    $max = $levels - 1;
    $q = [];
    for ($i = 0; $i < 256; $i++) {
        $q[$i] = (int)round($i / 255 * $max);
    }
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $out = [];
        foreach ($readRow($y) as $x => $g) {
            $out[$x] = $q[(int)round($g)];
        }
        $rows[$y] = packBytes($out);
    }
    return $rows;
}

/**
 * Ordered (Bayer) dithering.
 */
function ditherOrdered($w, $h, $readRow, $levels, $n) {
    $max = $levels - 1;
    $step = 255 / $max;
    $matrix = bayerMatrix($n);
    $cells = $n * $n;
    $thr = [];
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            $thr[$y][$x] = (($matrix[$y][$x] + 0.5) / $cells - 0.5) * $step;
        }
    }
    $rows = [];
    for ($y = 0; $y < $h; $y++) {
        $t = $thr[$y % $n];
        $out = [];
        foreach ($readRow($y) as $x => $g) {
            $idx = (int)round(($g + $t[$x % $n]) / $step);
            $out[$x] = $idx < 0 ? 0 : ($idx > $max ? $max : $idx);
        }
        $rows[$y] = packBytes($out);
    }
    return $rows;
}

/**
 * Generic error diffusion dithering.
 *
 * @param int $w Width
 * @param int $h Height
 * @param callable $readRow Returns the gray values (0..255) of row $y
 * @param int $levels Number of gray levels
 * @param array $kernel Entry of diffusionKernels()
 * @param bool $rb Reduced bleeding: diffuse only half of the error
 * @param bool $serp Serpentine scanning (alternate direction on each row)
 * @return array One byte string of level indexes per row
 */
function ditherDiffusion($w, $h, $readRow, $levels, $kernel, $rb, $serp) {
    $max = $levels - 1;
    $step = 255 / $max;
    $scale = $rb ? 0.5 : 1.0;
    $fwd = [];
    $bwd = [];
    foreach ($kernel['taps'] as $t) {
        $c = $t[2] / $kernel['div'] * $scale;
        $fwd[] = [$t[0], $t[1], $c];
        $bwd[] = [-$t[0], $t[1], $c];
    }
    // Error rows are padded by 2 on each side to avoid bounds checks
    $pad = 2;
    $zero = array_fill(0, $w + 2 * $pad, 0.0);
    $err = [$zero, $zero, $zero];
    $rows = [];

    for ($y = 0; $y < $h; $y++) {
        $gray = $readRow($y);
        $reverse = $serp && ($y & 1);
        $taps = $reverse ? $bwd : $fwd;
        $out = array_fill(0, $w, 0);
        for ($i = 0; $i < $w; $i++) {
            $x = $reverse ? $w - 1 - $i : $i;
            $g = $gray[$x] + $err[0][$x + $pad];
            if ($g < 0) {
                $g = 0;
            } elseif ($g > 255) {
                $g = 255;
            }
            $idx = (int)round($g / $step);
            $out[$x] = $idx;
            $e = $g - $idx * $step;
            if ($e != 0.0) {
                foreach ($taps as $t) {
                    $err[$t[1]][$x + $pad + $t[0]] += $e * $t[2];
                }
            }
        }
        $rows[$y] = packBytes($out);
        $err = [$err[1], $err[2], $zero];
    }
    return $rows;
}

/**
 * Quantize/dither an image to the given number of levels.
 *
 * @param int $w Width
 * @param int $h Height
 * @param callable $readRow Row reader (tone adjusted gray values)
 * @param int $levels Number of gray levels (2..256)
 * @param string $method Key of DTH_METHODS
 * @param bool $rb Reduced bleeding
 * @param bool $serp Serpentine scanning
 * @return array One byte string of level indexes per row
 */
function ditherRows($w, $h, $readRow, $levels, $method, $rb, $serp) {
    if ($levels >= 256 || $method === 'none') {
        return ditherNone($w, $h, $readRow, $levels);
    }
    $kernels = diffusionKernels();
    if (isset($kernels[$method])) {
        return ditherDiffusion($w, $h, $readRow, $levels, $kernels[$method], $rb, $serp);
    }
    $sizes = ['by' => 2, 'by4' => 4, 'by8' => 8];
    return ditherOrdered($w, $h, $readRow, $levels, isset($sizes[$method]) ? $sizes[$method] : 2);
}

/* ------------------------------------------------------------------------
 * Image processing (GD)
 * --------------------------------------------------------------------- */

/**
 * Parse PHP's memory_limit into bytes (-1 for unlimited).
 */
function memoryLimitBytes() {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

/**
 * Decode raw image data into a GD image, applying EXIF orientation.
 *
 * @param string $data Raw image bytes
 * @return resource|GdImage
 * @throws DthException If the data is not a supported image or is too big
 */
function decodeImage($data) {
    $info = @getimagesizefromstring($data);
    if ($info === false) {
        throw new DthException('The data is not a supported image', 415);
    }
    $pixels = $info[0] * $info[1];
    if ($pixels > MAX_SRC_PIXELS) {
        throw new DthException('Source image is too large', 413);
    }
    $limit = memoryLimitBytes();
    if ($limit > 0 && memory_get_usage() + $pixels * 6 > $limit) {
        throw new DthException('Source image is too large for the server memory limit', 413);
    }
    $img = @imagecreatefromstring($data);
    if ($img === false) {
        throw new DthException('Failed to decode the image', 415);
    }

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
        $o = is_array($exif) && isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        if ($o === 2 || $o === 4) {
            imageflip($img, $o === 2 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        } elseif ($o === 3) {
            $img = rotateImage($img, 180);
        } elseif ($o === 6) {
            $img = rotateImage($img, 90);
        } elseif ($o === 8) {
            $img = rotateImage($img, 270);
        }
    }
    return $img;
}

/**
 * Free a GD image (a no-op since PHP 8.0, deprecated in 8.5).
 *
 * @param resource|GdImage $img Image
 */
function freeImage($img) {
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($img);
    }
}

/**
 * Rotate a GD image clockwise by a multiple of 90 degrees.
 *
 * @param resource|GdImage $img Image
 * @param int $degrees 0, 90, 180 or 270
 * @return resource|GdImage The rotated image
 */
function rotateImage($img, $degrees) {
    if ($degrees % 360 === 0) {
        return $img;
    }
    $rotated = imagerotate($img, (360 - $degrees) % 360, 0xFFFFFF);
    if ($rotated === false) {
        return $img;
    }
    return $rotated;
}

/**
 * Process an image: orient, crop/scale, grayscale, adjust tone and dither.
 *
 * @param string $data Raw image bytes
 * @param array $s Settings
 * @return array ['w' => int, 'h' => int, 'levels' => int, 'rows' => string[]]
 * @throws DthException If the image cannot be processed
 */
function processImage($data, $s) {
    $src = decodeImage($data);
    $src = rotateImage($src, $s['rotate']);

    $g = computeGeometry(imagesx($src), imagesy($src), $s['res'], $s['fit']);
    $dst = imagecreatetruecolor($g['cw'], $g['ch']);
    if ($dst === false) {
        throw new DthException('Failed to allocate the output image', 500);
    }
    // White background so transparent areas and padding come out white
    imagealphablending($dst, true);
    imagefilledrectangle($dst, 0, 0, $g['cw'] - 1, $g['ch'] - 1, 0xFFFFFF);
    imagecopyresampled($dst, $src, $g['dx'], $g['dy'], $g['sx'], $g['sy'],
        $g['dw'], $g['dh'], $g['sw'], $g['sh']);
    freeImage($src);

    imagefilter($dst, IMG_FILTER_GRAYSCALE);

    $lut = buildToneLut($s['brightness'], $s['contrast'], $s['gamma'], $s['invert']);
    $w = $g['cw'];
    $h = $g['ch'];
    $readRow = function ($y) use ($dst, $w, $lut) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $row[$x] = $lut[(imagecolorat($dst, $x, $y) >> 16) & 0xFF];
        }
        return $row;
    };
    $rows = ditherRows($w, $h, $readRow, $s['levels'], $s['dth'], $s['rb'], $s['serp']);
    freeImage($dst);

    return ['w' => $w, 'h' => $h, 'levels' => $s['levels'], 'rows' => $rows];
}

/* ------------------------------------------------------------------------
 * Output encoders
 * --------------------------------------------------------------------- */

/**
 * Map from level index to gray value (0..255).
 *
 * @param int $levels Number of levels
 * @return array
 */
function levelGrays($levels) {
    $grays = [];
    for ($i = 0; $i < $levels; $i++) {
        $grays[$i] = (int)round($i * 255 / ($levels - 1));
    }
    return $grays;
}

/**
 * Build a byte-for-byte translation table for strtr(): level index => gray.
 */
function levelTranslation($levels) {
    $from = '';
    $to = '';
    foreach (levelGrays($levels) as $i => $gray) {
        $from .= chr($i);
        $to .= chr($gray);
    }
    return [$from, $to];
}

/**
 * Encode the processed image as binary PGM (P5), 8-bit gray.
 */
function encodePgm($r) {
    list($from, $to) = levelTranslation($r['levels']);
    $out = "P5\n{$r['w']} {$r['h']}\n255\n";
    foreach ($r['rows'] as $row) {
        $out .= strtr($row, $from, $to);
    }
    return $out;
}

/**
 * Encode the processed image as binary PPM (P6), RGB.
 */
function encodePpm($r) {
    $map = [];
    foreach (levelGrays($r['levels']) as $i => $gray) {
        $map[chr($i)] = str_repeat(chr($gray), 3);
    }
    $out = "P6\n{$r['w']} {$r['h']}\n255\n";
    foreach ($r['rows'] as $row) {
        $out .= strtr($row, $map);
    }
    return $out;
}

/**
 * Pack a string of '0'/'1' characters into bytes, MSB first, zero-padding
 * the last byte (PHP's pack() has no bit-string format).
 *
 * @param string $bits String of '0' and '1'
 * @return string
 */
function packBits($bits) {
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        $out .= chr(bindec(str_pad($chunk, 8, '0')));
    }
    return $out;
}

/**
 * Encode the processed image as binary PBM (P4). In PBM a set bit is black.
 */
function encodePbm($r) {
    $map = [];
    foreach (levelGrays($r['levels']) as $i => $gray) {
        $map[chr($i)] = $gray >= 128 ? '0' : '1';
    }
    $out = "P4\n{$r['w']} {$r['h']}\n";
    foreach ($r['rows'] as $row) {
        $out .= packBits(strtr($row, $map));
    }
    return $out;
}

/**
 * Build an 8-bit palette GD image from level-index rows. Palette images keep
 * PNG and GIF files small (1-bit PNG for 1-bit output).
 *
 * @param array $r Result of processImage()
 * @return resource|GdImage
 */
function toPaletteImage($r) {
    $img = imagecreate($r['w'], $r['h']);
    foreach (levelGrays($r['levels']) as $i => $gray) {
        imagecolorallocate($img, $gray, $gray, $gray);   // allocated in order: palette index == level
    }
    for ($y = 0; $y < $r['h']; $y++) {
        $row = $r['rows'][$y];
        for ($x = 0; $x < $r['w']; $x++) {
            imagesetpixel($img, $x, $y, ord($row[$x]));
        }
    }
    return $img;
}

/**
 * Encode the processed image.
 *
 * @param array $r Result of processImage()
 * @param string $fmt Output format
 * @return array [string $mime, string $bytes]
 */
function encodeImage($r, $fmt) {
    switch ($fmt) {
        case 'pgm':
            return ['image/x-portable-graymap', encodePgm($r)];
        case 'ppm':
            return ['image/x-portable-pixmap', encodePpm($r)];
        case 'pbm':
            return ['image/x-portable-bitmap', encodePbm($r)];
    }
    $img = toPaletteImage($r);
    ob_start();
    switch ($fmt) {
        case 'jpg':
        case 'jpeg':
            $mime = 'image/jpeg';
            imagejpeg($img, null, 90);
            break;
        case 'gif':
            $mime = 'image/gif';
            imagegif($img);
            break;
        default:
            $mime = 'image/png';
            imagepng($img, null, 9);
            break;
    }
    $bytes = ob_get_clean();
    freeImage($img);
    return [$mime, $bytes];
}

/* ------------------------------------------------------------------------
 * HTML
 * --------------------------------------------------------------------- */

/**
 * Escape a string for HTML output.
 */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Per-request CSP nonce.
 */
function cspNonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Emit a <select> element.
 *
 * @param string $id Element id and name
 * @param array $options value => label
 * @param string $selected Selected value
 */
function selectBox($id, $options, $selected) {
    echo '<select id="' . h($id) . '" name="' . h($id) . '">';
    foreach ($options as $value => $label) {
        echo '<option value="' . h($value) . '"' . ((string)$value === (string)$selected ? ' selected' : '') . '>'
            . h($label) . '</option>';
    }
    echo '</select>';
}

/**
 * Display the main form, pre-filled with the given settings.
 *
 * @param array $s Settings
 */
function displayForm($s) {
    $source = $s['source'] !== '' ? $s['source'] : 'url';
    $res = param('res', DEF_RES);
    $collections = ['any' => 'Random collection'];
    foreach (COLLECTIONS as $id => $c) {
        $collections[$id] = $c['name'];
    }
    ?>
    <h1>DitherBox</h1>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="ui" value="1">

        <div>
            <label for="image_source">Image source:</label>
            <?php selectBox('image_source', ['url' => 'Via URL', 'file' => 'Upload file', 'collection' => 'Collection'], $source); ?>
        </div>

        <div id="url_field">
            <label for="url_input">Image URL:</label>
            <input type="url" id="url_input" name="url" value="<?php echo h(param('url', '')); ?>" placeholder="https://example.com/image.jpg">
        </div>

        <div id="file_field" hidden>
            <label for="image">Select image file:</label>
            <input type="file" id="image" name="image" accept="image/*">
        </div>

        <div id="collection_field" hidden>
            <label for="col">Collection:</label>
            <?php selectBox('col', $collections, strtolower((string)param('col', 'any'))); ?>
        </div>

        <div>
            <label for="fmt">Output format:</label>
            <?php selectBox('fmt', ['png' => 'PNG', 'jpg' => 'JPG', 'gif' => 'GIF', 'ppm' => 'PPM',
                'pgm' => 'PGM', 'pbm' => 'PBM'], $s['fmt']); ?>
        </div>

        <div>
            <label for="bits">Grayscale bits: <span id="bits_value"><?php echo (int)$s['bits']; ?></span></label>
            <input type="range" id="bits" name="bits" min="1" max="8" value="<?php echo (int)$s['bits']; ?>">
        </div>

        <div>
            <label for="dth">Dithering method:</label>
            <?php selectBox('dth', DTH_METHODS, $s['dth']); ?>
        </div>

        <div>
            <input type="hidden" name="rb" value="0">
            <label>
                <input type="checkbox" id="rb" name="rb" value="1"<?php echo $s['rb'] ? ' checked' : ''; ?>>
                Reduce color bleeding
            </label>
            <input type="hidden" name="serp" value="0">
            <label>
                <input type="checkbox" id="serp" name="serp" value="1"<?php echo $s['serp'] ? ' checked' : ''; ?>>
                Serpentine scanning
            </label>
            <input type="hidden" name="invert" value="0">
            <label>
                <input type="checkbox" id="invert" name="invert" value="1"<?php echo $s['invert'] ? ' checked' : ''; ?>>
                Invert
            </label>
        </div>

        <div>
            <label for="brightness">Brightness: <span id="brightness_value"><?php echo (int)$s['brightness']; ?></span></label>
            <input type="range" id="brightness" name="brightness" min="-100" max="100" value="<?php echo (int)$s['brightness']; ?>">
        </div>

        <div>
            <label for="contrast">Contrast: <span id="contrast_value"><?php echo (int)$s['contrast']; ?></span></label>
            <input type="range" id="contrast" name="contrast" min="-100" max="100" value="<?php echo (int)$s['contrast']; ?>">
        </div>

        <div>
            <label for="gamma">Gamma:</label>
            <input type="number" id="gamma" name="gamma" min="0.1" max="5" step="0.1" value="<?php echo h($s['gamma']); ?>">
        </div>

        <div>
            <label for="res">Resolution (WxH, or a single number for the maximum size):</label>
            <input type="text" id="res" name="res" value="<?php echo h($res); ?>" placeholder="<?php echo h(DEF_RES); ?>">
        </div>

        <div>
            <label for="fit">Fit:</label>
            <?php selectBox('fit', ['cover' => 'Cover (crop)', 'contain' => 'Contain (pad with white)'], $s['fit']); ?>
        </div>

        <div>
            <label for="rotate">Rotate:</label>
            <?php selectBox('rotate', ['0' => 'None', '90' => '90° clockwise', '180' => '180°', '270' => '90° counter-clockwise'], (string)$s['rotate']); ?>
        </div>

        <input type="submit" value="Process Image">
    </form>

    <p class="note">Note: DitherBox processes images with customizable dithering and grayscale levels.</p>
    <?php
}

/**
 * Display the processed image result.
 *
 * @param string $dataUri Data URI of the preview image
 * @param array $r Result of processImage()
 * @param array $s Settings
 */
function displayResult($dataUri, $r, $s) {
    ?>
    <h1>DitherBox Result</h1>

    <div>
        <img src="<?php echo h($dataUri); ?>" alt="Processed Image" class="result-image"
             width="<?php echo (int)$r['w']; ?>" height="<?php echo (int)$r['h']; ?>">
    </div>

    <div>
        <h2>Image Details</h2>
        <p>
            <small>
            <?php echo h(strtoupper($s['fmt'])); ?> |
            <?php echo (int)$r['w']; ?>x<?php echo (int)$r['h']; ?> |
            <?php echo (int)$s['bits']; ?> bits |
            <?php echo h(DTH_METHODS[$s['dth']]); ?>
            <?php echo $s['rb'] ? '| reduce bleeding' : ''; ?>
            <?php echo in_array($s['fmt'], ['ppm', 'pgm', 'pbm'], true) ? '| preview shown as PNG' : ''; ?>
            </small>
        </p>
    </div>
    <?php
}

/**
 * Display a web page with common HTML structure.
 *
 * @param string $title The page title
 * @param callable $contentCallback Function that outputs the page content
 */
function displayWebPage($title, $contentCallback) {
    $nonce = cspNonce();
    header("Content-Security-Policy: default-src 'none'; img-src data:; "
        . "style-src 'nonce-$nonce' https://cdn.jsdelivr.net; script-src 'nonce-$nonce'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo h($title); ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.0.6/css/pico.min.css"
              integrity="sha384-7P0NVe9LPDbUCAF+fH2R8Egwz1uqNH83Ns/bfJY0fN2XCDBMUI2S9gGzIOIRBKsA"
              crossorigin="anonymous">
        <style nonce="<?php echo h($nonce); ?>">
            .container {
                max-width: 800px;
                margin: 2rem auto;
                padding: 0 1rem;
            }
            .note {
                font-size: 0.9rem;
                color: var(--pico-muted-color);
            }
            .result-image {
                max-width: 100%;
                height: auto;
                image-rendering: pixelated;
            }
            .error {
                color: #b00020;
                padding: 1rem;
                border: 1px solid #b00020;
                border-radius: 4px;
                background-color: #ffe6e6;
            }
        </style>
    </head>
    <body>
        <main class="container">
            <?php $contentCallback(); ?>
        </main>

        <script nonce="<?php echo h($nonce); ?>">
            function toggleSourceFields() {
                var source = document.getElementById('image_source');
                if (!source) return;
                source = source.value;
                var fields = {url_field: 'url', file_field: 'file', collection_field: 'collection'};
                for (var id in fields) {
                    var el = document.getElementById(id);
                    if (el) el.hidden = source !== fields[id];
                }
            }
            function bindValue(id) {
                var input = document.getElementById(id);
                var label = document.getElementById(id + '_value');
                if (input && label) {
                    input.addEventListener('input', function () { label.textContent = input.value; });
                }
            }
            document.addEventListener('DOMContentLoaded', function () {
                var source = document.getElementById('image_source');
                if (source) source.addEventListener('change', toggleSourceFields);
                ['bits', 'brightness', 'contrast'].forEach(bindValue);
                toggleSourceFields();
            });
        </script>
    </body>
    </html>
    <?php
}

/* ------------------------------------------------------------------------
 * Controller
 * --------------------------------------------------------------------- */

/**
 * Report an error to the client. Plain text for API-style requests, an HTML
 * page for requests coming from the form.
 *
 * @param Throwable $e The error
 * @param array $s Settings
 */
function respondError($e, $s) {
    if ($e instanceof DthException) {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        $message = $e->getMessage();
    } else {
        error_log('DitherBox: ' . get_class($e) . ': ' . $e->getMessage());
        $status = 500;
        $message = 'Internal error while processing the image';
    }
    http_response_code($status);
    if ($s['ui']) {
        displayWebPage('DitherBox - Error', function () use ($message, $s) {
            ?>
            <h1>DitherBox Error</h1>
            <div class="error">Error: <?php echo h($message); ?></div>
            <?php
            displayForm($s);
        });
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error: ' . $message . "\n";
    }
}

/**
 * Application entry point.
 */
function main() {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $s = readSettings();
    $wantsImage = $_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['url']) || isset($_GET['col']);

    if (!$wantsImage) {
        displayWebPage('DitherBox', function () use ($s) {
            displayForm($s);
        });
        return;
    }

    try {
        @set_time_limit(60);
        rateLimit();
        list($data, $cacheable) = acquireImage($s);
        $result = processImage($data, $s);
        unset($data);

        if ($s['ui']) {
            $previewFmt = in_array($s['fmt'], ['png', 'jpg', 'gif'], true) ? $s['fmt'] : 'png';
            list($mime, $bytes) = encodeImage($result, $previewFmt);
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            displayWebPage('DitherBox - Result', function () use ($dataUri, $result, $s) {
                displayResult($dataUri, $result, $s);
                displayForm($s);
            });
            return;
        }

        list($mime, $bytes) = encodeImage($result, $s['fmt']);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: ' . ($cacheable ? 'public, max-age=3600' : 'no-store'));
        echo $bytes;
    } catch (Throwable $e) {
        respondError($e, $s);
    }
}

if (!defined('DTHBOX_NO_RUN')) {
    main();
}
