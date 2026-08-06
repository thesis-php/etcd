<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;

#[Test]
final class KvTest extends EtcdTestCase
{
    public function putGet(): void
    {
        $put = $this->etcd()->put('/kv/a', '1');
        Assert::true($put->revision > 0);
        Assert::null($put->previous);

        $kv = $this->etcd()->get('/kv/a');
        Assert::notNull($kv);
        Assert::same($kv->key, '/kv/a');
        Assert::same($kv->value, '1');
        Assert::same($kv->version, 1);
    }

    public function streamPrefixYieldsEveryKey(): void
    {
        $etcd = $this->etcd();
        for ($i = 0; $i < 10; ++$i) {
            $etcd->put(\sprintf('/stream/%02d', $i), (string) $i);
        }

        $keys = [];
        foreach ($etcd->streamPrefix('/stream/') as $kv) {
            $keys[] = $kv->key;
        }

        Assert::count($keys, 10);
        Assert::contains($keys, '/stream/00');
        Assert::contains($keys, '/stream/09');
    }

    public function putWithPrevKvReturnsTheOverwrittenValue(): void
    {
        $this->etcd()->put('/kv/a', '1');

        $put = $this->etcd()->put('/kv/a', '2', new PutOptions(prevKv: true));
        Assert::notNull($put->previous);
        Assert::same($put->previous->value, '1');
    }

    public function getMissingKeyReturnsNull(): void
    {
        Assert::null($this->etcd()->get('/kv/missing'));
    }

    public function delete(): void
    {
        $this->etcd()->put('/kv/a', '1');

        Assert::same($this->etcd()->delete('/kv/a'), 1);
        Assert::null($this->etcd()->get('/kv/a'));
    }

    public function deleteMissingKeyReturnsZero(): void
    {
        Assert::same($this->etcd()->delete('/kv/missing'), 0);
    }

    public function keysOnlyOmitsValues(): void
    {
        $this->etcd()->put('/kv/a', 'value');

        $result = $this->etcd()->getPrefix('/kv/', new GetOptions(keysOnly: true));

        $kv = $result->kvs[0] ?? null;
        Assert::notNull($kv);
        Assert::same($kv->key, '/kv/a');
        Assert::same($kv->value, '');
    }

    public function getRangeReturnsAHalfOpenInterval(): void
    {
        $this->etcd()->put('/r/a', '1');
        $this->etcd()->put('/r/b', '2');
        $this->etcd()->put('/r/c', '3');

        $result = $this->etcd()->getRange('/r/a', '/r/c', new GetOptions(sortOrder: SortOrder::Ascend));

        Assert::same($result->count, 2);

        $keys = array_map(static fn($kv): string => $kv->key, $result->kvs);
        Assert::same($keys, ['/r/a', '/r/b']);
    }
}
