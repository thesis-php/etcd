<?php

declare(strict_types=1);

namespace Thesis\Etcd;

/**
 * @api
 */
final readonly class GetOptions
{
    /**
     * @param 0|positive-int $limit maximum number of keys to return, 0 for no limit
     * @param int $revision point-in-time revision to read at, 0 reads the newest store
     * @param bool $keysOnly return keys without their values
     * @param bool $countOnly return only the count of matched keys
     * @param bool $serializable serve the read locally from a replica without reaching consensus
     */
    public function __construct(
        public int $limit = 0,
        public int $revision = 0,
        public bool $keysOnly = false,
        public bool $countOnly = false,
        public bool $serializable = false,
        public SortOrder $sortOrder = SortOrder::None,
        public SortTarget $sortTarget = SortTarget::Key,
    ) {}
}
