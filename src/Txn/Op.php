<?php

declare(strict_types=1);

namespace Thesis\Etcd\Txn;

use Etcdserverpb\RequestOp;
use Thesis\Etcd\GetOptions;
use Thesis\Etcd\Internal\Command;
use Thesis\Etcd\Internal\Range;
use Thesis\Etcd\PutOptions;
use Thesis\Etcd\Txn;

/**
 * A single operation applied inside a transaction branch.
 *
 * @api
 */
final readonly class Op
{
    public static function put(string $key, string $value, PutOptions $options = new PutOptions()): self
    {
        return new self(new RequestOp(new RequestOp\RequestRequestPut(Command::put($key, $value, $options))));
    }

    public static function get(string $key, GetOptions $options = new GetOptions()): self
    {
        return new self(new RequestOp(new RequestOp\RequestRequestRange(Command::range($key, '', $options))));
    }

    public static function getRange(string $from, string $to, GetOptions $options = new GetOptions()): self
    {
        return new self(new RequestOp(new RequestOp\RequestRequestRange(Command::range($from, $to, $options))));
    }

    public static function getPrefix(string $prefix, GetOptions $options = new GetOptions()): self
    {
        $range = Range::fromPrefix($prefix);

        return self::getRange($range->start, $range->end, $options);
    }

    public static function delete(string $key): self
    {
        return new self(new RequestOp(new RequestOp\RequestRequestDeleteRange(Command::deleteRange($key, ''))));
    }

    public static function txn(Txn $txn): self
    {
        return new self(new RequestOp(new RequestOp\RequestRequestTxn($txn->proto())));
    }

    public function proto(): RequestOp
    {
        return $this->requestOp;
    }

    private function __construct(
        private RequestOp $requestOp,
    ) {}
}
