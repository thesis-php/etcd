<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\RangeResponse;

/**
 * @api
 */
final readonly class RangeResult
{
    /**
     * @param list<KeyValue> $kvs
     */
    public function __construct(
        public array $kvs,
        public int $revision,
        public int $count,
        public bool $more,
    ) {}

    public static function fromPb(RangeResponse $response): self
    {
        return new self(
            kvs: array_map(KeyValue::fromPb(...), $response->kvs),
            revision: $response->header->revision ?? 0,
            count: $response->count,
            more: $response->more,
        );
    }
}
