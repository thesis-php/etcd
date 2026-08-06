<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;
use Thesis\Etcd\Txn\Compare;
use Thesis\Etcd\Txn\Op;

#[Test]
final class TxnTest extends EtcdTestCase
{
    public function thenBranchRunsWhenGuardHolds(): void
    {
        $put = $this->etcd()->put('/txn/k', 'init');

        $result = $this->etcd()->txn(
            Txn::compare(Compare::modRevision('/txn/k')->equals($put->revision))
                ->then(Op::put('/txn/k', 'next'))
                ->otherwise(Op::get('/txn/k')),
        );

        Assert::true($result->succeeded);

        $kv = $this->etcd()->get('/txn/k');
        Assert::notNull($kv);
        Assert::same($kv->value, 'next');
    }

    public function otherwiseBranchRunsWhenGuardFails(): void
    {
        $this->etcd()->put('/txn/k', 'init');

        $result = $this->etcd()->txn(
            Txn::compare(Compare::modRevision('/txn/k')->equals(999_999))
                ->then(Op::put('/txn/k', 'next'))
                ->otherwise(Op::get('/txn/k')),
        );

        Assert::false($result->succeeded);
        Assert::count($result->responses, 1);

        $response = $result->responses[0] ?? null;
        Assert::instanceOf($response, RangeResult::class);

        $kv = $response->kvs[0] ?? null;
        Assert::notNull($kv);
        Assert::same($kv->value, 'init');
    }

    public function combinesMultipleConditionsWithAnd(): void
    {
        $put = $this->etcd()->put('/txn/k', 'init');

        $result = $this->etcd()->txn(
            Txn::compare(Compare::version('/txn/k')->equals(1), Compare::modRevision('/txn/k')->equals($put->revision))
                ->then(Op::put('/txn/k', 'next')),
        );

        Assert::true($result->succeeded);
    }

    public function branchCanRangeReadAPrefixAndReportsTheRevision(): void
    {
        $etcd = $this->etcd();
        $etcd->put('/txn/r/a', '1');
        $etcd->put('/txn/r/b', '2');
        $last = $etcd->put('/txn/r/c', '3');

        $result = $etcd->txn(
            Txn::compare(Compare::value('/txn/r/a')->equals('1'))
                ->then(Op::getPrefix('/txn/r/')),
        );

        Assert::true($result->succeeded);
        Assert::same($result->revision, $last->revision);

        $range = $result->responses[0] ?? null;
        Assert::instanceOf($range, RangeResult::class);
        Assert::count($range->kvs, 3);
    }

    public function runsNestedTransactions(): void
    {
        $this->etcd()->put('/txn/outer', 'o');
        $this->etcd()->put('/txn/inner', 'i');

        $result = $this->etcd()->txn(
            Txn::compare(Compare::value('/txn/outer')->equals('o'))
                ->then(
                    Op::txn(
                        Txn::compare(Compare::value('/txn/inner')->equals('i'))
                            ->then(Op::put('/txn/inner', 'i2')),
                    ),
                ),
        );

        Assert::true($result->succeeded);

        $kv = $this->etcd()->get('/txn/inner');
        Assert::notNull($kv);
        Assert::same($kv->value, 'i2');
    }
}
