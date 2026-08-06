<?php

declare(strict_types=1);

namespace Thesis\Etcd\Internal;

use Etcdserverpb\DeleteRangeRequest;
use Etcdserverpb\PutRequest;
use Etcdserverpb\RangeRequest;
use Thesis\Etcd\GetOptions;
use Thesis\Etcd\PutOptions;

/**
 * @internal
 */
final readonly class Command
{
    public static function range(
        string $key,
        string $rangeEnd,
        GetOptions $options,
    ): RangeRequest {
        return new RangeRequest(
            key: $key,
            rangeEnd: $rangeEnd,
            limit: $options->limit,
            revision: $options->revision,
            sortOrder: $options->sortOrder->proto(),
            sortTarget: $options->sortTarget->proto(),
            serializable: $options->serializable,
            keysOnly: $options->keysOnly,
            countOnly: $options->countOnly,
        );
    }

    public static function put(
        string $key,
        string $value,
        PutOptions $options,
    ): PutRequest {
        return new PutRequest(
            key: $key,
            value: $value,
            lease: $options->leaseId,
            prevKv: $options->prevKv,
            ignoreValue: $options->ignoreValue,
            ignoreLease: $options->ignoreLease,
        );
    }

    public static function deleteRange(
        string $key,
        string $rangeEnd,
    ): DeleteRangeRequest {
        return new DeleteRangeRequest(
            key: $key,
            rangeEnd: $rangeEnd,
        );
    }
}
