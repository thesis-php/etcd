<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Etcd\Exception\CompactedException;

#[Test]
final class RevisionTest extends EtcdTestCase
{
    public function readsAtAHistoricalRevision(): void
    {
        $first = $this->etcd()->put('/rev/k', '1')->revision;
        $this->etcd()->put('/rev/k', '2');

        $historical = $this->etcd()->get('/rev/k', new GetOptions(revision: $first));
        Assert::notNull($historical);
        Assert::same($historical->value, '1');

        $current = $this->etcd()->get('/rev/k');
        Assert::notNull($current);
        Assert::same($current->value, '2');
    }

    public function readingACompactedRevisionThrows(): void
    {
        $first = $this->etcd()->put('/rev/k', '1')->revision;
        $second = $this->etcd()->put('/rev/k', '2')->revision;

        $this->etcd()->compact($second, physical: true);

        Expect::exception(CompactedException::class);
        $this->etcd()->get('/rev/k', new GetOptions(revision: $first));
    }
}
