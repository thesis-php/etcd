<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class LeaseInfo
{
    /**
     * @param TimeSpan $ttl remaining time-to-live
     * @param TimeSpan $grantedTtl the TTL the lease was granted with
     * @param list<string> $keys keys attached to the lease (empty unless requested)
     */
    public function __construct(
        public int $id,
        public TimeSpan $ttl,
        public TimeSpan $grantedTtl,
        public array $keys,
    ) {}
}
