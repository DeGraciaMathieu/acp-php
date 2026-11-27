<?php

declare(strict_types=1);

final class Cart
{
    /** @var list<array{price: float, quantity: int}> */
    private array $lines = [];

    public function add(float $price, int $quantity = 1): void
    {
        $this->lines[] = ['price' => $price, 'quantity' => $quantity];
    }

    public function total(): float
    {
        $total = 0.0;
        foreach ($this->lines as $line) {
            $total += $line['price'];
        }

        return $total;
    }
}
