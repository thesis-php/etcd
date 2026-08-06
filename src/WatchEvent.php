<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Mvccpb\Event;

/**
 * @api
 */
final readonly class WatchEvent
{
    public function __construct(
        public WatchEventType $type,
        public KeyValue $kv,
        public ?KeyValue $prev = null,
    ) {}

    /**
     * @throws Exception\UnexpectedResponseException
     */
    public static function fromPb(Event $event): self
    {
        $kv = $event->kv ?? throw new Exception\UnexpectedResponseException('etcd returned a watch event without a key-value pair');

        return new self(
            type: match ($event->type) {
                Event\EventType::PUT => WatchEventType::Put,
                Event\EventType::DELETE => WatchEventType::Delete,
            },
            kv: KeyValue::fromPb($kv),
            prev: $event->prevKv !== null ? KeyValue::fromPb($event->prevKv) : null,
        );
    }
}
