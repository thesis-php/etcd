<?php

declare(strict_types=1);

namespace Thesis\Etcd\Txn;

use Etcdserverpb\Compare as PbCompare;
use Etcdserverpb\Compare\CompareTarget;

/**
 * A finished transaction guard term.
 *
 * Build one through a named constructor, which fixes the compared field, and
 * then a comparison operator:
 *
 * ```
 * Txn\Compare::modRevision('/flags/x')->equals($rev);
 * Txn\Compare::value('/lock')->notEquals('');
 * ```
 *
 * @api
 */
final readonly class Compare
{
    /**
     * @internal use the named constructors instead
     */
    public function __construct(
        private PbCompare $proto,
    ) {}

    public static function value(string $key): CompareOperand
    {
        return new CompareOperand(CompareTarget::VALUE, $key);
    }

    public static function version(string $key): CompareOperand
    {
        return new CompareOperand(CompareTarget::VERSION, $key);
    }

    public static function createRevision(string $key): CompareOperand
    {
        return new CompareOperand(CompareTarget::CREATE, $key);
    }

    public static function modRevision(string $key): CompareOperand
    {
        return new CompareOperand(CompareTarget::MOD, $key);
    }

    public static function lease(string $key): CompareOperand
    {
        return new CompareOperand(CompareTarget::LEASE, $key);
    }

    public function proto(): PbCompare
    {
        return $this->proto;
    }
}
