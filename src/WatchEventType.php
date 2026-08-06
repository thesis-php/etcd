<?php

declare(strict_types=1);

namespace Thesis\Etcd;

/**
 * @api
 */
enum WatchEventType
{
    case Put;
    case Delete;
}
