<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;

#[Test]
final class PrefixTest extends EtcdTestCase
{
    public function getPrefixReturnsOnlyKeysUnderThePrefix(): void
    {
        $this->etcd()->put('/p', 'outside');
        $this->etcd()->put('/p/a', '1');
        $this->etcd()->put('/p/b', '2');

        $result = $this->etcd()->getPrefix('/p/', new GetOptions(sortOrder: SortOrder::Ascend));

        Assert::same($result->count, 2);

        $keys = array_map(static fn($kv): string => $kv->key, $result->kvs);
        Assert::same($keys, ['/p/a', '/p/b']);
    }

    public function countReturnsTheNumberOfKeys(): void
    {
        $this->etcd()->put('/p/a', '1');
        $this->etcd()->put('/p/b', '2');

        Assert::same($this->etcd()->count('/p/'), 2);
    }

    public function limitCapsTheResultAndReportsMore(): void
    {
        $this->etcd()->put('/sort/a', '1');
        $this->etcd()->put('/sort/b', '2');
        $this->etcd()->put('/sort/c', '3');

        $result = $this->etcd()->getPrefix('/sort/', new GetOptions(
            limit: 2,
            sortOrder: SortOrder::Ascend,
            sortTarget: SortTarget::Key,
        ));

        Assert::count($result->kvs, 2);

        $keys = array_map(static fn($kv): string => $kv->key, $result->kvs);
        Assert::same($keys, ['/sort/a', '/sort/b']);
        Assert::true($result->more);
        Assert::same($result->count, 3);
    }

    public function ascendingSortOrdersByKey(): void
    {
        $this->etcd()->put('/sort/b', '2');
        $this->etcd()->put('/sort/a', '1');
        $this->etcd()->put('/sort/c', '3');

        $result = $this->etcd()->getPrefix('/sort/', new GetOptions(sortOrder: SortOrder::Ascend));

        $keys = array_map(static fn($kv): string => $kv->key, $result->kvs);
        Assert::same($keys, ['/sort/a', '/sort/b', '/sort/c']);
    }

    public function descendingSortReversesOrder(): void
    {
        $this->etcd()->put('/sort/a', '1');
        $this->etcd()->put('/sort/b', '2');
        $this->etcd()->put('/sort/c', '3');

        $result = $this->etcd()->getPrefix('/sort/', new GetOptions(sortOrder: SortOrder::Descend));

        $keys = array_map(static fn($kv): string => $kv->key, $result->kvs);
        Assert::same($keys, ['/sort/c', '/sort/b', '/sort/a']);
    }

    public function deletePrefixRemovesTheWholeRange(): void
    {
        $this->etcd()->put('/del/a', '1');
        $this->etcd()->put('/del/b', '2');
        $this->etcd()->put('/keep', '3');

        Assert::same($this->etcd()->deletePrefix('/del/'), 2);
        Assert::same($this->etcd()->count('/del/'), 0);
        Assert::notNull($this->etcd()->get('/keep'));
    }
}
