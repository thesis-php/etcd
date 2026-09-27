<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Etcdserverpb\CompactionRequest;
use Etcdserverpb\KVClient;
use Etcdserverpb\LeaseClient;
use Etcdserverpb\LeaseGrantRequest;
use Etcdserverpb\LeaseRevokeRequest;
use Etcdserverpb\LeaseTimeToLiveRequest;
use Etcdserverpb\WatchClient;
use Etcdserverpb\WatchCreateRequest;
use Etcdserverpb\WatchRequest;
use Etcdserverpb\WatchRequest\RequestUnionCreateRequest;
use Thesis\Etcd\Internal\Command;
use Thesis\Etcd\Txn\TxnResult;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc;
use Thesis\Grpc\GrpcException;
use Thesis\Grpc\InvokeError;
use Thesis\Time\TimeSpan;
use V3lockpb\LockClient;
use V3lockpb\LockRequest;
use V3lockpb\UnlockRequest;

/**
 * @api
 */
final readonly class Client
{
    private KVClient $kvs;

    private LeaseClient $leases;

    private WatchClient $watches;

    private LockClient $locks;

    public function __construct(Grpc\Client $client)
    {
        $this->kvs = new KVClient($client);
        $this->leases = new LeaseClient($client);
        $this->watches = new WatchClient($client);
        $this->locks = new LockClient($client);
    }

    /**
     * @throws EtcdException
     */
    public function get(string $key, GetOptions $options = new GetOptions()): ?KeyValue
    {
        $response = $this->call(
            $this->kvs->range(...),
            Command::range($key, '', $options),
        );

        $kv = $response->kvs[0] ?? null;

        return $kv !== null ? KeyValue::fromPb($kv) : null;
    }

    /**
     * @throws EtcdException
     */
    public function getPrefix(string $prefix, GetOptions $options = new GetOptions()): RangeResult
    {
        $range = Internal\Range::fromPrefix($prefix);

        $response = $this->call(
            $this->kvs->range(...),
            Command::range($range->start, $range->end, $options),
        );

        return RangeResult::fromPb($response);
    }

    /**
     * @throws EtcdException
     */
    public function getRange(string $from, string $to, GetOptions $options = new GetOptions()): RangeResult
    {
        $response = $this->call(
            $this->kvs->range(...),
            Command::range($from, $to, $options),
        );

        return RangeResult::fromPb($response);
    }

    /**
     * Streams every key under `$prefix` lazily, one at a time.
     *
     * Unlike {@see getPrefix()}, which loads the whole range into a single
     * response, this pulls the range from etcd in chunks and yields each key as it
     * arrives — so a scan over millions of keys never materialises at once. Use it
     * for large or unbounded prefixes.
     *
     * @return iterable<KeyValue>
     * @throws EtcdException
     */
    public function streamPrefix(string $prefix, GetOptions $options = new GetOptions()): iterable
    {
        $range = Internal\Range::fromPrefix($prefix);

        yield from $this->streamRange($range->start, $range->end, $options);
    }

    /**
     * Streams the half-open range `[$from, $to)` lazily, one key at a time.
     *
     * @return iterable<KeyValue>
     * @throws EtcdException
     */
    public function streamRange(string $from, string $to, GetOptions $options = new GetOptions()): iterable
    {
        try {
            $channel = $this->kvs->rangeStream(Command::range($from, $to, $options));

            foreach ($channel as $response) {
                foreach ($response->rangeResponse->kvs ?? [] as $kv) {
                    yield KeyValue::fromPb($kv);
                }
            }
        } catch (GrpcException $e) {
            throw self::mapError($e);
        }
    }

    /**
     * @throws EtcdException
     */
    public function put(string $key, string $value, PutOptions $options = new PutOptions()): PutResult
    {
        $response = $this->call(
            $this->kvs->put(...),
            Command::put($key, $value, $options),
        );

        return PutResult::fromPb($response);
    }

    /**
     * @throws EtcdException
     */
    public function delete(string $key): int
    {
        $response = $this->call(
            $this->kvs->deleteRange(...),
            Command::deleteRange($key, ''),
        );

        return $response->deleted;
    }

    /**
     * @throws EtcdException
     */
    public function deletePrefix(string $prefix): int
    {
        $range = Internal\Range::fromPrefix($prefix);

        $response = $this->call(
            $this->kvs->deleteRange(...),
            Command::deleteRange($range->start, $range->end),
        );

        return $response->deleted;
    }

    /**
     * @throws EtcdException
     */
    public function count(string $prefix): int
    {
        $range = Internal\Range::fromPrefix($prefix);

        $response = $this->call(
            $this->kvs->range(...),
            Command::range($range->start, $range->end, new GetOptions(countOnly: true)),
        );

        return $response->count;
    }

    /**
     * @throws EtcdException
     */
    public function txn(Txn $txn): TxnResult
    {
        $response = $this->call(
            $this->kvs->txn(...),
            $txn->proto(),
        );

        return TxnResult::fromResponse($response);
    }

    /**
     * @throws EtcdException
     */
    public function compact(int $revision, bool $physical = false): void
    {
        $this->call(
            $this->kvs->compact(...),
            new CompactionRequest(revision: $revision, physical: $physical),
        );
    }

    /**
     * Grants a lease that expires after `$ttl` unless kept alive.
     *
     * @param TimeSpan $ttl advisory time-to-live (etcd's granularity is seconds)
     * @param ?int $id a specific lease id to request, null lets the server choose
     * @throws EtcdException
     */
    public function grantLease(TimeSpan $ttl, ?int $id = null): Lease
    {
        $response = $this->call(
            $this->leases->leaseGrant(...),
            new LeaseGrantRequest(TTL: $ttl->toSeconds(), ID: $id ?? 0),
        );

        return new Lease(
            id: $response->ID,
            ttl: TimeSpan::fromSeconds($response->TTL),
            release: $this->revokeLease(...),
        );
    }

    /**
     * @throws EtcdException
     */
    public function revokeLease(int $id): void
    {
        $this->call(
            $this->leases->leaseRevoke(...),
            new LeaseRevokeRequest(ID: $id),
        );
    }

    /**
     * @throws EtcdException
     */
    public function leaseTimeToLive(int $id, bool $withKeys = false): LeaseInfo
    {
        $response = $this->call(
            $this->leases->leaseTimeToLive(...),
            new LeaseTimeToLiveRequest(
                ID: $id,
                keys: $withKeys,
            ),
        );

        return new LeaseInfo(
            id: $response->ID,
            ttl: TimeSpan::fromSeconds($response->TTL),
            grantedTtl: TimeSpan::fromSeconds($response->grantedTTL),
            keys: $response->keys,
        );
    }

    /**
     * Starts renewing the lease in the background (~every ttl/3) until the
     * returned handle is closed.
     */
    public function keepAlive(int $id, TimeSpan $ttl): LeaseKeepAlive
    {
        $channel = $this->leases->leaseKeepAlive();

        return new LeaseKeepAlive($channel, $id, $ttl);
    }

    /**
     * Acquires the lock `$name`, blocking until it is held.
     *
     * With a `$leaseId` the lock is bound to that lease and you own its lifecycle,
     * {@see Lock::unlock()} only releases the lock. Without one, a lease of `$ttl`
     * (default 60s) is granted and kept alive in the background for you, and
     * {@see Lock::unlock()} also stops the renewal and revokes that lease.
     *
     * @param non-empty-string $name
     * @throws EtcdException
     */
    public function lock(
        string $name,
        ?int $leaseId = null,
        ?TimeSpan $ttl = null,
    ): Lock {
        if ($leaseId !== null) {
            $response = $this->call(
                $this->locks->lock(...),
                new LockRequest(name: $name, lease: $leaseId),
            );

            \assert($response->key !== '');

            return new Lock(key: $response->key, unlock: $this->unlock(...));
        }

        // A lease we own: keep it alive during the wait (a queued lock can outlast
        // the TTL, and etcd does not renew it for us) and release it on unlock.
        $lease = $this->grantLease($ttl ?? TimeSpan::fromSeconds(60));
        $keepAlive = $this->keepAlive($lease->id, $lease->ttl);

        try {
            $response = $this->call(
                $this->locks->lock(...),
                new LockRequest(name: $name, lease: $lease->id),
            );
        } catch (EtcdException $e) {
            $keepAlive->close();
            $lease->release();

            throw $e;
        }

        \assert($response->key !== '');
        $key = $response->key;

        return new Lock(key: $key, unlock: function () use (
            $key,
            $keepAlive,
            $lease,
        ): void {
            $this->unlock($key);
            $keepAlive->close();
            $lease->release();
        });
    }

    /**
     * Tries to acquire the lock `$name` without waiting.
     *
     * Returns a {@see Lock} if the lock is free and now held, or `null` if it is
     * currently held by someone else — never blocks. Contended with {@see lock()}
     * and {@see withLock()} on the same name.
     *
     * To wait for the lock instead of giving up, wrap the blocking {@see lock()}
     * in `Amp\async()` and await the returned future.
     *
     * Lease handling matches {@see lock()}: pass a `$leaseId` you own, or let a
     * lease of `$ttl` (default 60s) be granted, kept alive and — only if the lock
     * is taken — revoked on {@see Lock::unlock()}.
     *
     * @param non-empty-string $name
     * @throws EtcdException
     */
    public function tryLock(string $name, ?int $leaseId = null, ?TimeSpan $ttl = null): ?Lock
    {
        $lease = null;
        if ($leaseId === null) {
            $lease = $this->grantLease($ttl ?? TimeSpan::fromSeconds(60));
            $leaseId = $lease->id;
        }

        $prefix = "{$name}/";
        $key = $prefix . dechex($leaseId);

        $owner = new GetOptions(
            limit: 1,
            sortOrder: SortOrder::Ascend,
            sortTarget: SortTarget::Create,
        );

        // Enter the queue of contenders and read the current holder atomically:
        // create our lease-bound key if it is not already there, and fetch the
        // oldest key under the prefix — the one that holds the lock.
        $result = $this->txn(
            Txn::compare(Txn\Compare::createRevision($key)->equals(0))
                ->then(
                    Txn\Op::put($key, '', new PutOptions(leaseId: $leaseId)),
                    Txn\Op::getPrefix($prefix, $owner),
                )
                ->otherwise(
                    Txn\Op::get($key),
                    Txn\Op::getPrefix($prefix, $owner),
                ),
        );

        // Our candidacy revision: the revision the txn created our key at, or the
        // existing key's creation revision if we were already queued.
        $revision = $result->revision;
        if (!$result->succeeded) {
            $mine = $result->responses[0] ?? null;
            \assert($mine instanceof RangeResult);

            $revision = $mine->kvs[0]->createRevision ?? $revision;
        }

        $holder = $result->responses[1] ?? null;
        \assert($holder instanceof RangeResult);

        // The oldest key wins. If it is ours (or the prefix is empty), we hold it.
        if ($holder->kvs === [] || $holder->kvs[0]->createRevision === $revision) {
            if ($lease === null) {
                return new Lock(key: $key, unlock: $this->unlock(...));
            }

            // We own the granted lease: keep it alive and release it on unlock.
            $keepAlive = $this->keepAlive($lease->id, $lease->ttl);

            return new Lock(key: $key, unlock: function () use (
                $key,
                $keepAlive,
                $lease,
            ): void {
                $this->unlock($key);
                $keepAlive->close();
                $lease->release();
            });
        }

        // An older contender is ahead of us — withdraw and report contention.
        $this->delete($key);

        $lease?->release();

        return null;
    }

    /**
     * @param non-empty-string $key
     * @throws EtcdException
     */
    public function unlock(string $key): void
    {
        $this->call(
            $this->locks->unlock(...),
            new UnlockRequest(key: $key),
        );
    }

    /**
     * Runs `$fn` while holding the lock `$name`, releasing everything afterwards.
     *
     * A dedicated lease of `$ttl` is granted and kept alive in the background for
     * the duration; the lock is acquired, `$fn` is invoked with the held
     * {@see Lock}, and the lock, keep-alive and lease are all released in
     * `finally` — even if `$fn` throws.
     *
     * @template T
     * @param non-empty-string $name
     * @param callable(Lock): T $fn
     * @return T
     * @throws EtcdException
     */
    public function withLock(string $name, TimeSpan $ttl, callable $fn): mixed
    {
        $lock = $this->lock($name, ttl: $ttl);

        try {
            return $fn($lock);
        } finally {
            $lock->unlock();
        }
    }

    /**
     * Watches a single key, invoking `$onEvent` for every change until the
     * returned handle is closed.
     *
     * @param callable(WatchEvent): void $onEvent
     */
    public function watch(
        string $key,
        callable $onEvent,
        WatchOptions $options = new WatchOptions(),
    ): Watch {
        return $this->watchChanges(
            key: $key,
            onEvent: $onEvent,
            options: $options,
        );
    }

    /**
     * Watches every key under `$prefix`, invoking `$onEvent` for every change
     * until the returned handle is closed.
     *
     * @param callable(WatchEvent): void $onEvent
     */
    public function watchPrefix(
        string $prefix,
        callable $onEvent,
        WatchOptions $options = new WatchOptions(),
    ): Watch {
        $range = Internal\Range::fromPrefix($prefix);

        return $this->watchChanges(
            key: $range->start,
            onEvent: $onEvent,
            options: $options,
            rangeEnd: $range->end,
        );
    }

    /**
     * @template In of object
     * @template Out
     * @param \Closure(In): Out $call
     * @param In $request
     * @return Out
     * @throws EtcdException
     */
    private function call(\Closure $call, mixed $request): mixed
    {
        try {
            return $call($request);
        } catch (GrpcException $e) {
            throw self::mapError($e);
        }
    }

    /**
     * @param callable(WatchEvent): void $onEvent
     */
    private function watchChanges(
        string $key,
        callable $onEvent,
        WatchOptions $options = new WatchOptions(),
        string $rangeEnd = '',
    ): Watch {
        $channel = $this->watches->watch();

        $channel->send(new WatchRequest(
            new RequestUnionCreateRequest(
                new WatchCreateRequest(
                    key: $key,
                    rangeEnd: $rangeEnd,
                    startRevision: $options->startRevision,
                    progressNotify: $options->progressNotify,
                    prevKv: $options->prevKv,
                ),
            ),
        ));

        return new Watch(
            channel: $channel,
            onEvent: $onEvent,
        );
    }

    private static function mapError(GrpcException $e): EtcdException
    {
        if ($e instanceof InvokeError) {
            $message = $e->statusMessage ?? $e->getMessage();

            return match ($e->statusCode) {
                Code::OUT_OF_RANGE => new Exception\CompactedException($message, previous: $e),
                Code::PERMISSION_DENIED => new Exception\PermissionDeniedException($message, previous: $e),
                Code::NOT_FOUND => new Exception\KeyNotFoundException($message, previous: $e),
                Code::UNAVAILABLE, Code::DEADLINE_EXCEEDED => new Exception\ConnectionException($message, previous: $e),
                default => new Exception\UnexpectedResponseException($message, previous: $e),
            };
        }

        return new Exception\ConnectionException($e->getMessage(), previous: $e);
    }
}
