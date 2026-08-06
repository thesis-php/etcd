<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Mvccpb\KeyValue as PbKeyValue;

/**
 * @api
 */
final readonly class KeyValue
{
    public function __construct(
        public string $key,
        public string $value,
        public int $createRevision,
        public int $modRevision,
        public int $version,
        public int $leaseId,
    ) {}

    public static function fromPb(PbKeyValue $kv): self
    {
        return new self(
            key: $kv->key,
            value: $kv->value,
            createRevision: $kv->createRevision,
            modRevision: $kv->modRevision,
            version: $kv->version,
            leaseId: $kv->lease,
        );
    }
}
