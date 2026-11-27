<?php

// php tests/CartTest.php

declare(strict_types=1);

require __DIR__ . '/../src/Cart.php';

$cart = new Cart();
$cart->add(12.5, quantity: 2);
$cart->add(5.0);

$total = $cart->total();
if ($total !== 30.0) {
    fwrite(STDERR, "FAIL: expected a total of 30.0, got {$total}\n");
    exit(1);
}

echo "OK\n";
