<?php

declare(strict_types=1);

namespace Thesis\Etcd;

/**
 * @api
 */
final readonly class Lock
{
    /**
     * @param non-empty-string $key
     * @param \Closure(non-empty-string): void $unlock
     */
    public function __construct(
        public string $key,
        private \Closure $unlock,
    ) {}

    public function unlock(): void
    {
        ($this->unlock)($this->key);
    }
}
