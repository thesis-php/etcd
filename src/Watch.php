<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Etcdserverpb\WatchCancelRequest;
use Etcdserverpb\WatchRequest;
use Etcdserverpb\WatchRequest\RequestUnionCancelRequest;
use Etcdserverpb\WatchResponse;
use Thesis\Grpc\Client\BidirectionalStreamChannel;
use Thesis\Grpc\Exception\ClientStreamIsClosed;
use function Amp\async;

/**
 * @api
 */
final readonly class Watch
{
    private DeferredCancellation $canceller;

    /** @var Future<mixed> */
    private Future $future;

    /**
     * @param BidirectionalStreamChannel<WatchRequest, WatchResponse> $channel
     * @param callable(WatchEvent): void $onEvent
     */
    public function __construct(
        BidirectionalStreamChannel $channel,
        callable $onEvent,
    ) {
        $this->canceller = new DeferredCancellation();

        $cancellation = $this->canceller->getCancellation();

        $this->future = async(static function () use (
            $channel,
            $onEvent,
            $cancellation,
        ): void {
            /** @var ?string $cancellationId */
            $cancellationId = null;

            try {
                /** @var WatchResponse $response */
                foreach ($channel as $response) {
                    if ($response->created) {
                        $cancellationId ??= $cancellation->subscribe(static function () use (
                            $channel,
                            $response,
                        ): void {
                            $channel->send(new WatchRequest(
                                new RequestUnionCancelRequest(
                                    new WatchCancelRequest(watchId: $response->watchId),
                                ),
                            ));
                        });

                        continue;
                    }

                    if ($response->canceled) {
                        break;
                    }

                    foreach ($response->events as $event) {
                        $onEvent(WatchEvent::fromPb($event));
                    }
                }
            } catch (CancelledException|ClientStreamIsClosed) {
            } finally {
                $channel->close();

                if ($cancellationId !== null) {
                    $cancellation->unsubscribe($cancellationId);
                }
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
