<?php

namespace App\Services;

final class FieldSnapshot
{
    public function __construct(
        public readonly ?FieldState $primary,
        public readonly ?FieldOverlayState $overlay,
        /** @var list<FieldState> */
        public readonly array $echoes = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, []);
    }
}
