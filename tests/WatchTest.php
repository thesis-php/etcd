<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Assert;
use Testo\Test;
use function Amp\delay;

#[Test]
final class WatchTest extends EtcdTestCase
{
    public function deliversPutAndDeleteWithPrevKv(): void
    {
        /** @var list<WatchEvent> $events */
        $events = [];

        $watch = $this->etcd()->watch(
            '/watch/k',
            static function (WatchEvent $event) use (&$events): void {
                $events[] = $event;
            },
            new WatchOptions(prevKv: true),
        );

        delay(0.3);

        $this->etcd()->put('/watch/k', 'one');
        $this->etcd()->put('/watch/k', 'two');
        $this->etcd()->delete('/watch/k');

        delay(0.5);
        $watch->close();

        Assert::count($events, 3);

        $first = $events[0] ?? null;
        Assert::notNull($first);
        Assert::same($first->type, WatchEventType::Put);
        Assert::same($first->kv->value, 'one');

        $second = $events[1] ?? null;
        Assert::notNull($second);
        Assert::same($second->type, WatchEventType::Put);
        Assert::same($second->kv->value, 'two');
        Assert::notNull($second->prev);
        Assert::same($second->prev->value, 'one');

        $third = $events[2] ?? null;
        Assert::notNull($third);
        Assert::same($third->type, WatchEventType::Delete);
        Assert::same($third->kv->key, '/watch/k');
    }

    public function startRevisionReplaysHistory(): void
    {
        $put = $this->etcd()->put('/watch/history', 'v1');

        /** @var list<string> $values */
        $values = [];

        $watch = $this->etcd()->watch(
            '/watch/history',
            static function (WatchEvent $event) use (&$values): void {
                $values[] = $event->kv->value;
            },
            new WatchOptions(startRevision: $put->revision),
        );

        delay(0.5);
        $watch->close();

        Assert::same($values, ['v1']);
    }

    public function watchPrefixCatchesEveryKey(): void
    {
        /** @var list<string> $keys */
        $keys = [];

        $watch = $this->etcd()->watchPrefix(
            '/watch/prefix/',
            static function (WatchEvent $event) use (&$keys): void {
                $keys[] = $event->kv->key;
            },
        );

        delay(0.3);

        $this->etcd()->put('/watch/prefix/a', '1');
        $this->etcd()->put('/watch/prefix/b', '2');

        delay(0.5);
        $watch->close();

        Assert::same($keys, ['/watch/prefix/a', '/watch/prefix/b']);
    }
}
