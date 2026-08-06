<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\RangeRequest;

/**
 * @api
 */
enum SortTarget
{
    case Key;
    case Version;
    case Create;
    case Mod;
    case Value;

    public function proto(): RangeRequest\SortTarget
    {
        return match ($this) {
            self::Key => RangeRequest\SortTarget::KEY,
            self::Version => RangeRequest\SortTarget::VERSION,
            self::Create => RangeRequest\SortTarget::CREATE,
            self::Mod => RangeRequest\SortTarget::MOD,
            self::Value => RangeRequest\SortTarget::VALUE,
        };
    }
}
