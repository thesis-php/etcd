<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Etcdserverpb\LeaseKeepAliveRequest;
use Etcdserverpb\LeaseKeepAliveResponse;
use Thesis\Grpc\Client\BidirectionalStreamChannel;
use Thesis\Grpc\Exception\ClientStreamIsClosed;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\delay;

/**
 * A running background renewal of a lease.
 *
 * On construction it opens a `LeaseKeepAlive` stream and spawns a coroutine that
 * renews the lease every ~ttl/3 seconds until {@see close()} is called.
 *
 * @api
 */
final readonly class LeaseKeepAlive
{
    private DeferredCancellation $canceller;

    /** @var Future<mixed> */
    private Future $future;

    /**
     * @param BidirectionalStreamChannel<LeaseKeepAliveRequest, LeaseKeepAliveResponse> $channel
     */
    public function __construct(
        BidirectionalStreamChannel $channel,
        int $id,
        TimeSpan $ttl,
    ) {
        $this->canceller = new DeferredCancellation();

        $cancellation = $this->canceller->getCancellation();

        $this->future = async(static function () use (
            $channel,
            $id,
            $ttl,
            $cancellation,
        ): void {
            $interval = max(1.0, $ttl->toSeconds() / 3);

            try {
                while (!$cancellation->isRequested()) {
                    $channel->send(new LeaseKeepAliveRequest($id));
                    $channel->receive();
                    delay($interval, cancellation: $cancellation);
                }
            } catch (CancelledException|ClientStreamIsClosed) {
            } finally {
                $channel->close();
            }
        });
    }

    public function close(): void
    {
        if ($this->canceller->isCancelled()) {
            return;
        }

        $this->canceller->cancel();

        $this->future->await();
    }
}
