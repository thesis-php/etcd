<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

#[Test]
final class LockTest extends EtcdTestCase
{
    public function withLockReturnsCallbackResultAndReleases(): void
    {
        $result = $this->etcd()->withLock('/lock/a', TimeSpan::fromSeconds(10), static fn(Lock $lock): string => "held:{$lock->key}");

        Assert::true(str_starts_with($result, 'held:/lock/a/'));

        $again = $this->etcd()->withLock('/lock/a', TimeSpan::fromSeconds(10), static fn(): string => 'acquired');
        Assert::same($again, 'acquired');
    }

    public function lockWithoutLeaseManagesItsOwnLease(): void
    {
        $etcd = $this->etcd();

        $held = $etcd->lock('/lock/auto');

        Assert::null($etcd->tryLock('/lock/auto'));

        $held->unlock();

        $again = $etcd->lock('/lock/auto');
        $again->unlock();
    }

    public function tryLockWithoutLeaseManagesItsOwnLease(): void
    {
        $etcd = $this->etcd();

        $held = $etcd->tryLock('/lock/auto-try');
        Assert::notNull($held);

        Assert::null($etcd->tryLock('/lock/auto-try'));

        $held->unlock();

        $again = $etcd->tryLock('/lock/auto-try');
        Assert::notNull($again);
        $again->unlock();
    }

    public function tryLockAcquiresWhenFreeAndReturnsNullWhenHeld(): void
    {
        $etcd = $this->etcd();

        $first = $etcd->grantLease(TimeSpan::fromSeconds(10));
        $second = $etcd->grantLease(TimeSpan::fromSeconds(10));

        $held = $etcd->tryLock('/lock/try', $first->id);
        Assert::notNull($held);

        Assert::null($etcd->tryLock('/lock/try', $second->id));

        $held->unlock();

        $taken = $etcd->tryLock('/lock/try', $second->id);
        Assert::notNull($taken);
        $taken->unlock();

        $etcd->revokeLease($first->id);
        $etcd->revokeLease($second->id);
    }

    public function tryLockYieldsToAnAlreadyWaitingBlockingLock(): void
    {
        $etcd = $this->etcd();

        $holder = $etcd->grantLease(TimeSpan::fromSeconds(10));
        $held = $etcd->tryLock('/lock/mixed', $holder->id);
        Assert::notNull($held);

        $waiter = $etcd->grantLease(TimeSpan::fromSeconds(10));
        $acquiredKey = null;
        $blocked = async(static function () use ($etcd, $waiter, &$acquiredKey): void {
            $lock = $etcd->lock('/lock/mixed', $waiter->id);
            $acquiredKey = $lock->key;
            $lock->unlock();
        });
        delay(0.2);

        $late = $etcd->grantLease(TimeSpan::fromSeconds(10));
        Assert::null($etcd->tryLock('/lock/mixed', $late->id));

        $held->unlock();
        $blocked->await();
        Assert::true(str_starts_with((string) $acquiredKey, '/lock/mixed/'));

        $etcd->revokeLease($holder->id);
        $etcd->revokeLease($waiter->id);
        $etcd->revokeLease($late->id);
    }

    public function contendingLockWaitsForTheHolderToRelease(): void
    {
        /** @var list<string> $order */
        $order = [];

        $first = async(function () use (&$order): void {
            $this->etcd()->withLock('/lock/contended', TimeSpan::fromSeconds(10), static function () use (&$order): void {
                $order[] = 'first-acquired';
                delay(1.0);
                $order[] = 'first-releasing';
            });
        });

        delay(0.2);

        $second = async(function () use (&$order): void {
            $this->etcd()->withLock('/lock/contended', TimeSpan::fromSeconds(10), static function () use (&$order): void {
                $order[] = 'second-acquired';
            });
        });

        await([$first, $second]);

        Assert::same($order, ['first-acquired', 'first-releasing', 'second-acquired']);
    }
}
