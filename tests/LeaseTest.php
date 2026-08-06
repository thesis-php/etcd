<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;
use Thesis\Time\TimeSpan;
use function Amp\delay;
use function Amp\now;

#[Test]
final class LeaseTest extends EtcdTestCase
{
    public function grantAttachRevokeDeletesKeys(): void
    {
        $lease = $this->etcd()->grantLease(TimeSpan::fromSeconds(60));
        Assert::true($lease->id !== 0);
        Assert::same($lease->ttl->toSeconds(), 60);

        $this->etcd()->put('/lease/k', 'v', new PutOptions(leaseId: $lease->id));

        $info = $this->etcd()->leaseTimeToLive($lease->id, withKeys: true);
        Assert::same($info->id, $lease->id);
        Assert::same($info->grantedTtl->toSeconds(), 60);
        Assert::true($info->ttl->isPositive());
        Assert::same($info->keys, ['/lease/k']);

        $lease->release();
        Assert::null($this->etcd()->get('/lease/k'));
    }

    public function expiredLeaseDeletesKeys(): void
    {
        $lease = $this->etcd()->grantLease(TimeSpan::fromSeconds(1));
        $this->etcd()->put('/lease/short', 'v', new PutOptions(leaseId: $lease->id));

        Assert::notNull($this->etcd()->get('/lease/short'));

        $this->awaitGone('/lease/short');
    }

    public function keepAliveHoldsKeyBeyondTtlThenExpiresAfterClose(): void
    {
        $lease = $this->etcd()->grantLease(TimeSpan::fromSeconds(2));
        $this->etcd()->put('/lease/kept', 'v', new PutOptions(leaseId: $lease->id));

        $keepAlive = $this->etcd()->keepAlive($lease->id, $lease->ttl);

        delay(3.0);
        Assert::notNull($this->etcd()->get('/lease/kept'));

        $keepAlive->close();

        $this->awaitGone('/lease/kept');
    }

    private function awaitGone(string $key, float $timeout = 5.0): void
    {
        $deadline = now() + $timeout;

        while ($this->etcd()->get($key) !== null && now() < $deadline) {
            delay(0.1);
        }

        Assert::null($this->etcd()->get($key), "key {$key} was not deleted within {$timeout}s");
    }
}
