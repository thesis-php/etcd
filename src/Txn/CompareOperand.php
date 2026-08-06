<?php

declare(strict_types=1);

namespace Thesis\Etcd\Txn;

use Etcdserverpb\Compare as PbCompare;
use Etcdserverpb\Compare\CompareResult;
use Etcdserverpb\Compare\CompareTarget;
use Etcdserverpb\Compare\TargetUnion;
use Etcdserverpb\Compare\TargetUnionCreateRevision;
use Etcdserverpb\Compare\TargetUnionLease;
use Etcdserverpb\Compare\TargetUnionModRevision;
use Etcdserverpb\Compare\TargetUnionValue;
use Etcdserverpb\Compare\TargetUnionVersion;

/**
 * The intermediate step of a {@see Compare} term: a key and the field to
 * compare, still missing the operator. Its only methods produce a finished
 * {@see Compare}, so a guard term cannot be left half-built.
 *
 * @api
 */
final readonly class CompareOperand
{
    /**
     * @internal
     */
    public function __construct(
        private CompareTarget $target,
        private string $key,
    ) {}

    public function equals(int|string $operand): Compare
    {
        return $this->build(CompareResult::EQUAL, $operand);
    }

    public function notEquals(int|string $operand): Compare
    {
        return $this->build(CompareResult::NOT_EQUAL, $operand);
    }

    public function greater(int|string $operand): Compare
    {
        return $this->build(CompareResult::GREATER, $operand);
    }

    public function less(int|string $operand): Compare
    {
        return $this->build(CompareResult::LESS, $operand);
    }

    private function build(CompareResult $result, int|string $operand): Compare
    {
        return new Compare(new PbCompare(
            result: $result,
            target: $this->target,
            key: $this->key,
            targetUnion: $this->union($operand),
        ));
    }

    private function union(int|string $operand): TargetUnion
    {
        return match ($this->target) {
            CompareTarget::VALUE => new TargetUnionValue((string) $operand),
            CompareTarget::VERSION => new TargetUnionVersion((int) $operand),
            CompareTarget::CREATE => new TargetUnionCreateRevision((int) $operand),
            CompareTarget::MOD => new TargetUnionModRevision((int) $operand),
            CompareTarget::LEASE => new TargetUnionLease((int) $operand),
        };
    }
}
