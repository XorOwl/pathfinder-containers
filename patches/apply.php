<?php
$vendor = '/app/vendor';

// Fix 1: F3 Cache::set() passes [] or float TTL to phpredis 5.3+ which rejects it
$f = $vendor . '/bcosca/fatfree-core/base.php';
$c = file_get_contents($f);
$c = str_replace(
    "return \$this->ref->set(\$ndx,\$data,\$ttl?['ex'=>\$ttl]:[]);",
    "\$ttl=(int)round(\$ttl); return \$ttl>0?\$this->ref->set(\$ndx,\$data,['ex'=>\$ttl]):\$this->ref->set(\$ndx,\$data);",
    $c
);
file_put_contents($f, $c);
echo "Patched: base.php\n";

// Fix 2: PSR-6 Redis adapter passes negative TTL (expired ESI responses) to setex()
$f = $vendor . '/cache/redis-adapter/RedisCachePool.php';
$c = file_get_contents($f);
$c = str_replace(
    'if ($ttl === null || $ttl === 0) {',
    'if ($ttl === null || $ttl === 0 || $ttl < 1) {',
    $c
);
file_put_contents($f, $c);
echo "Patched: RedisCachePool.php\n";
