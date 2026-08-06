<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\TxnRequest;
use Thesis\Etcd\Txn\Compare;
use Thesis\Etcd\Txn\Op;

/**
 * An immutable etcd transaction, decoupled from the client.
 *
 * @api
 */
final readonly class Txn
{
    /**
     * @param list<Compare> $compare
     * @param list<Op> $success
     * @param list<Op> $failure
     */
    private function __construct(
        private array $compare,
        private array $success,
        private array $failure,
    ) {}

    /**
     * @no-named-arguments
     */
    public static function compare(Compare ...$compare): self
    {
        return new self($compare, [], []);
    }

    /**
     * @no-named-arguments
     */
    public function then(Op ...$ops): self
    {
        return new self($this->compare, $ops, $this->failure);
    }

    /**
     * @no-named-arguments
     */
    public function otherwise(Op ...$ops): self
    {
        return new self($this->compare, $this->success, $ops);
    }

    public function proto(): TxnRequest
    {
        return new TxnRequest(
            compare: array_map(
                static fn(Compare $compare) => $compare->proto(),
                $this->compare,
            ),
            success: array_map(
                static fn(Op $op) => $op->proto(),
                $this->success,
            ),
            failure: array_map(
                static fn(Op $op) => $op->proto(),
                $this->failure,
            ),
        );
    }
}
