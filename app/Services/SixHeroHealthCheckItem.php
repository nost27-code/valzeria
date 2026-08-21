<?php

namespace App\Services;

use InvalidArgumentException;

final readonly class SixHeroHealthCheckItem
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAIL = 'fail';

    /**
     * @param  array<string, bool|float|int|string|null>  $metadata
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $message,
        public array $metadata = [],
    ) {
        if (! in_array($status, [
            self::STATUS_PASS,
            self::STATUS_WARNING,
            self::STATUS_FAIL,
        ], true)) {
            throw new InvalidArgumentException("Unknown Six Heroes health status: {$status}");
        }
    }

    /** @return array{key:string,label:string,status:string,message:string,metadata:array<string, bool|float|int|string|null>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
