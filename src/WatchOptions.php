<?php

declare(strict_types=1);

namespace Thesis\Etcd;

/**
 * @api
 */
final readonly class WatchOptions
{
    /**
     * @param int $startRevision revision to replay from (inclusive), 0 starts from "now"
     * @param bool $prevKv also deliver each key's previous value in {@see WatchEvent::$prev}
     * @param bool $progressNotify periodically receive an empty response to track the current revision
     */
    public function __construct(
        public int $startRevision = 0,
        public bool $prevKv = false,
        public bool $progressNotify = false,
    ) {}
}
