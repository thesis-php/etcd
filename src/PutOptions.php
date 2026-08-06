<?php

declare(strict_types=1);

namespace Thesis\Etcd;

/**
 * @api
 */
final readonly class PutOptions
{
    /**
     * @param int $leaseId lease to attach the key to, 0 for no lease
     * @param bool $prevKv return the previous key-value pair in {@see PutResult::$previous}
     * @param bool $ignoreValue keep the current value, update only the lease
     * @param bool $ignoreLease keep the current lease, update only the value
     */
    public function __construct(
        public int $leaseId = 0,
        public bool $prevKv = false,
        public bool $ignoreValue = false,
        public bool $ignoreLease = false,
    ) {}
}
