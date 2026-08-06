<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\PutResponse;

/**
 * @api
 */
final readonly class PutResult
{
    public function __construct(
        public int $revision,
        public ?KeyValue $previous,
    ) {}

    public static function fromPb(PutResponse $response): self
    {
        return new self(
            revision: $response->header->revision ?? 0,
            previous: $response->prevKv !== null ? KeyValue::fromPb($response->prevKv) : null,
        );
    }
}
