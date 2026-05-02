<?php
$vendor = '/app/vendor';

// ── F3 base.php: all patches in one read/write pass ──────────────────────────
$f = $vendor . '/bcosca/fatfree-core/base.php';
$c = file_get_contents($f);
$applied = [];

// Fix 1: Cache::set() passes [] or float TTL to phpredis which rejects it
$c = str_replace(
    "return \$this->ref->set(\$ndx,\$data,\$ttl?['ex'=>\$ttl]:[]);",
    "\$ttl=(int)round(\$ttl); return \$ttl>0?\$this->ref->set(\$ndx,\$data,['ex'=>\$ttl]):\$this->ref->set(\$ndx,\$data);",
    $c, $n
);
if ($n) $applied[] = 'Redis TTL';

// Fix 2: PHP 8.1+ bans $GLOBALS += [...] compound assignment
$c = str_replace(
    "\$GLOBALS+=['_ENV'=>\$_ENV,'_REQUEST'=>\$_REQUEST];",
    "\$GLOBALS['_ENV']=\$_ENV; \$GLOBALS['_REQUEST']=\$_REQUEST;",
    $c, $n
);
if ($n) $applied[] = 'GLOBALS compound assign';

// Fix 3: PHP 8.x — parse_str() rejects null; coalesce both call-sites
$c = str_replace(
    "parse_str(@\$url['query'],\$GLOBALS['_GET']);",
    "parse_str(@\$url['query']??'',\$GLOBALS['_GET']);",
    $c, $n
);
if ($n) $applied[] = 'parse_str site 1';
$c = str_replace(
    "parse_str(\$query,\$GLOBALS['_GET']);",
    "parse_str(\$query??'',\$GLOBALS['_GET']);",
    $c, $n
);
if ($n) $applied[] = 'parse_str site 2';

// Fix 4b: PHP 8.4 deprecated the E_STRICT constant (no-op since PHP 8.0 anyway)
$c = str_replace(
    'error_reporting((E_ALL|E_STRICT)&~(E_NOTICE|E_USER_NOTICE));',
    'error_reporting(E_ALL&~(E_NOTICE|E_USER_NOTICE|E_DEPRECATED|E_USER_DEPRECATED));',
    $c, $n
);
if ($n) $applied[] = 'E_STRICT removed';

// Fix 4: F3 error handler turns ALL warnings/deprecations into 500.
// PHP 8 promoted "Undefined variable/array key" from E_NOTICE to E_WARNING,
// so also skip those — they were silently ignored in PHP 7.
$old = "if (\$level & error_reporting())\n\t\t\t\t\t\$this->error(500,\$text,NULL,\$level);";
$new = "if (\$level & error_reporting() && !(\$level & (E_DEPRECATED|E_USER_DEPRECATED))\n\t\t\t\t\t\t&& !(\$level===E_WARNING && preg_match('/Undefined (variable|array key)|Trying to access array offset on/i',\$text)))\n\t\t\t\t\t\$this->error(500,\$text,NULL,\$level);";
$c = str_replace($old, $new, $c, $n);
if ($n) $applied[] = 'error handler skip E_DEPRECATED + undef-var E_WARNING';

// Fix 5: PHP 8.4 — implicit nullable typed parameters are deprecated
$c = preg_replace('/\b(array|string|int|float|bool)\s+(\$\w+\s*=\s*NULL)/', '?$1 $2', $c, -1, $n);
if ($n) $applied[] = "nullable types x$n";

// Fix 6: PHP 8.1+ ArrayAccess return type compatibility
$attr = "\t#[\\ReturnTypeWillChange]\n";
foreach (['offsetexists($key)', 'offsetset($key,$val)', '&offsetget($key)', 'offsetunset($key)'] as $sig) {
    $search = "\tfunction $sig {";
    if (str_contains($c, $search) && !str_contains($c, $attr . $search)) {
        $c = str_replace($search, $attr . $search, $c);
        $n = 1;
    } else {
        $n = 0;
    }
    if ($n) $applied[] = "ArrayAccess::$sig";
}

file_put_contents($f, $c);
echo "base.php: " . (count($applied) ? implode(', ', $applied) : 'nothing to patch') . "\n";

// ── F3 auth.php, web.php: nullable type fixes ─────────────────────────────────
foreach (['auth.php', 'web.php'] as $name) {
    $f = $vendor . "/bcosca/fatfree-core/$name";
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    $patched = preg_replace('/\b(array|string|int|float|bool)\s+(\$\w+\s*=\s*NULL)/', '?$1 $2', $c, -1, $n);
    if ($n) {
        file_put_contents($f, $patched);
        echo "Patched: $name (nullable types: $n)\n";
    }
}

// ── Vendor-wide: PHP 8.4 implicit nullable typed params ──────────────────────
// Targets only the packages known to have issues (avoids scanning all of vendor)
$targets = [
    'guzzlehttp/promises/src',
    'react/promise-timer/src',
];
foreach ($targets as $rel) {
    $dir = $vendor . '/' . $rel;
    if (!is_dir($dir)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f2) {
        if ($f2->getExtension() !== 'php') continue;
        $c = file_get_contents($f2);
        // Match any typed param (built-in or class name) defaulting to null
        $patched = preg_replace(
            '/(?<!\?)\b([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+(\$\w+\s*=\s*null\b)/',
            '?$1 $2',
            $c, -1, $n
        );
        if ($n) {
            file_put_contents($f2, $patched);
            echo "Patched: " . str_replace($vendor.'/', '', $f2->getPathname()) . " (nullable types: $n)\n";
        }
    }
}

// ── PSR-6 Redis adapter: negative TTL ────────────────────────────────────────
$f = $vendor . '/cache/redis-adapter/RedisCachePool.php';
if (!file_exists($f)) {
    echo "Skipped: RedisCachePool.php (not present)\n";
} else {
    $c = file_get_contents($f);
    $patched = str_replace(
        'if ($ttl === null || $ttl === 0) {',
        'if ($ttl === null || $ttl === 0 || $ttl < 1) {',
        $c, $n
    );
    if ($n) {
        file_put_contents($f, $patched);
        echo "Patched: RedisCachePool.php (negative TTL)\n";
    } else {
        echo "Skipped: RedisCachePool.php (not needed)\n";
    }
}
