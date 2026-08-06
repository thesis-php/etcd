<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\RangeRequest;

/**
 * @api
 */
enum SortOrder
{
    case None;
    case Ascend;
    case Descend;

    public function proto(): RangeRequest\SortOrder
    {
        return match ($this) {
            self::None => RangeRequest\SortOrder::NONE,
            self::Ascend => RangeRequest\SortOrder::ASCEND,
            self::Descend => RangeRequest\SortOrder::DESCEND,
        };
    }
}
