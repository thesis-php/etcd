<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class Lease
{
    /**
     * @param \Closure(int): void $release
     */
    public function __construct(
        public int $id,
        public TimeSpan $ttl,
        private \Closure $release,
    ) {}

    /**
     * Revokes the lease, deleting every key attached to it.
     */
    public function release(): void
    {
        ($this->release)($this->id);
    }
}
