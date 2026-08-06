# thesis/etcd

A non-blocking PHP client for [etcd](https://etcd.io) v3, built on
[thesis/grpc](https://github.com/thesis-php/grpc).

**etcd** is a distributed, strongly-consistent key–value store.
Many processes across your fleet read and write the
same ordered key space, watch it for changes, and use it to *agree* on things:
shared configuration, service registration, who holds a lock, which instance is
the leader. etcd keeps that data consistent (Raft) and durable.

Reach for it when several instances of your application need to coordinate through
shared state instead of talking to each other directly.

The client covers the application-facing half of etcd:

- **Key–value** — reads, writes, prefix/range scans, atomic transactions, watches;
- **Leases** — keys that live only while their owner keeps them alive;
- **Locks** — mutual exclusion and single-leader election across processes.

Cluster administration — members, auth, maintenance — is intentionally out of
scope. This is a client for working with data, not for operating a cluster.

## Installation

```shell
composer require thesis/etcd
```

## Connecting

`Thesis\Etcd\Client` wraps a `Thesis\Grpc\Client` transport:

```php
use Thesis\Etcd;
use Thesis\Grpc\Client;

$etcd = new Etcd\Client(
    new Client\Builder()
        ->withHost('127.0.0.1:2379')
        ->build(),
);
```

Everything below is a **scenario**: a problem that several instances of an
application run into, and the etcd primitive that solves it.

## Share configuration across the fleet

You want one source of truth for settings that every instance reads, and you want
to group related keys so you can load them together. etcd is an ordered key space
of byte strings, organise it with `/`-separated prefixes.

```php
$etcd->put('/config/payments/timeout', '30');
$etcd->put('/config/payments/currency', 'EUR');

$kv = $etcd->get('/config/payments/timeout'); // ?KeyValue, null if absent
echo $kv?->value;                             // '30'

// Load a whole namespace at once with prefix scan.
$config = $etcd->getPrefix('/config/payments/');
foreach ($config->kvs as $kv) {
    echo "{$kv->key} = {$kv->value}\n";
}
```

A read also gives you MVCC metadata: `createRevision`, `modRevision`, `version`
and the attached `leaseId`. `getPrefix()` returns the matched pairs, the store
`revision` they were served at, the total `count`, and whether there is `more`
beyond a limit.

`GetOptions` / `PutOptions` tune both sides: limit and sort a scan, read at a
past revision, attach a lease, ask for the previous value, and so on.

`getPrefix()` loads the whole range into one response, which is fine for bounded
sets like config. For a large or unbounded prefix, `streamPrefix()` (and
`streamRange()`) pull it from etcd in chunks and yield one `KeyValue` at a time, so
a scan over millions of keys never materialises at once:

```php
foreach ($etcd->streamPrefix('/events/') as $kv) {
    // handle one key at a time; nothing is held in memory but the current one
}
```

### React to changes instead of polling

When a value changes you want every instance to notice, without a polling loop.
A **watch** streams changes for a key or a prefix into a callback that runs in the
background until you close the returned handle.

```php
use Thesis\Etcd\WatchEvent;
use Thesis\Etcd\WatchEventType;

$watch = $etcd->watchPrefix('/config/payments/', static function (WatchEvent $event): void {
    $verb = $event->type === WatchEventType::Delete ? 'deleted' : 'set';
    printf("%s %s\n", $event->kv->key, $verb);
});

// ... later, when you no longer care ...
$watch->close();
```

To load current state *and* keep it fresh with no gap and no double-delivery, read
the prefix, note its `revision`, then watch from `revision + 1`:

```php
use Thesis\Etcd\WatchOptions;

$snapshot = $etcd->getPrefix('/config/payments/');
// ... apply $snapshot->kvs ...

$watch = $etcd->watchPrefix(
    '/config/payments/',
    static function (WatchEvent $event): void { /* apply each later change */ },
    new WatchOptions(startRevision: $snapshot->revision + 1),
);
```

Use `watch()` for a single key. `WatchOptions` can also deliver each key's
pre-change value (`prevKv`) in `$event->prev`.

## Change a value only if nobody else did

Two instances read a value, both compute a new one, both write and one silently
overwrites the other. A **transaction** avoids the lost update: it checks guard
conditions and, atomically, runs one branch if they all hold and another if they
do not. This is compare-and-swap: write only if the key has not moved under you.

```php
use Thesis\Etcd\Txn;

$current = $etcd->get('/counter');

$result = $etcd->txn(
    Txn::compare(Txn\Compare::modRevision('/counter')->equals($current?->modRevision ?? 0))
        ->then(Txn\Op::put('/counter', (string) (((int) $current?->value) + 1)))
        ->otherwise(Txn\Op::get('/counter')), // someone beat us, read the fresh value
);

if (!$result->succeeded) {
    // retry with $result->responses[0], the current state
}
```

Guard terms pair a field (`Compare::value`, `::version`, `::createRevision`,
`::modRevision`, `::lease`) with an operator (`->equals`, `->notEquals`,
`->greater`, `->less`), and combine with **AND** (etcd has no OR in a
transaction). Branch operations are `Op::put`, `Op::get`, `Op::delete` and
`Op::txn` (nested). `$result->succeeded` says which branch ran, `$result->responses`
holds one entry per operation of that branch, in order.

## Make state disappear when its owner dies

An instance registers itself "worker-7 is alive at 10.0.0.5" and that entry
must vanish if the instance crashes, without anyone cleaning up after it. Bind the
key to a **lease**: a TTL that keys attach to. While the lease lives its keys
exist; when it expires or is revoked, every attached key is deleted at once.

```php
use Thesis\Etcd\PutOptions;
use Thesis\Time\TimeSpan;

$lease = $etcd->grantLease(TimeSpan::fromSeconds(15));

$etcd->put('/workers/worker-7', '10.0.0.5', new PutOptions(leaseId: $lease->id));
```

A lease expires unless renewed. `keepAlive()` renews it in the background (about
every `ttl / 3`) and returns a handle. While the process runs the key stays. The
moment the process dies, renewal stops, the lease lapses, and the registration is
gone.

```php
$keepAlive = $etcd->keepAlive($lease->id, $lease->ttl);

// ... worker runs, the registration stays alive as long as this process does ...

$keepAlive->close(); // stop renewing, the key expires on schedule
// or: $lease->release() to drop the lease and its keys right now
```

`leaseTimeToLive($id, withKeys: true)` reports the remaining TTL and the keys a
lease still holds.

## Run exactly one instance

You deploy the same job to N instances for availability, but only **one** may run
the critical section at a time: a nightly report, a migration, a leader. A
**lock** gives mutual exclusion across processes: acquiring blocks until the lock
is yours, so the others queue instead of running. The lock is held through a
lease, so if the holder crashes, its lease expires and the lock releases on its
own — no stuck locks, no manual recovery.

`withLock()` is the whole pattern in one call: it grants a lease, keeps it alive,
acquires the lock, runs your callback, and releases everything in `finally` — even
if the callback throws. It returns the callback's value.

```php
use Thesis\Etcd\Lock;
use Thesis\Time\TimeSpan;

$report = $etcd->withLock('/locks/report', ttl: TimeSpan::fromSeconds(10), fn: static function (Lock $lock): string {
    // Only one instance is ever inside this block at a time.
    return generateReport();
});
```

Run this on every instance and exactly one proceeds, the rest wait their turn (or
step in if the current holder dies). That *is* leader election: the instance
holding the lock is the leader for as long as it keeps its lease alive.

Below `withLock()` is `lock()`, which blocks until held and hands back a handle you
release yourself. Called without a lease it grants one and keeps it alive for you,
and `unlock()` releases both the lock and that lease:

```php
$lock = $etcd->lock('/locks/report'); // self-leased, blocks until held
try {
    // critical section
} finally {
    $lock->unlock(); // releases the lock and the lease
}
```

Pass a `$leaseId` when you want to own the lease: share one across several locks,
or give it a custom TTL and keep-alive. Then `unlock()` releases only the lock and
revoking the lease is yours to do:

```php
$lease = $etcd->grantLease(TimeSpan::fromSeconds(10));
$lock = $etcd->lock('/locks/report', $lease->id);
try {
    // critical section
} finally {
    $lock->unlock();
    $lease->release();
}
```

## Take over the moment the current leader dies

The natural follow-up: can the client *try* to become leader and, if someone else
holds it, hand you a future that resolves the instant leadership passes to you,
because the previous holder released it or crashed? There are two shapes of answer,
and both are here.

**Wait until it's yours.** `lock()` blocks until the lock is yours. etcd queues
waiters fairly and wakes the next one when the holder's key disappears (explicit
release, or lease expiry on a crash). Because this client is non-blocking, wrapping
that call in `Amp\async` turns "wait until I become leader" into exactly such a
future:

```php
use function Amp\async;

$leadership = async(static fn() => $etcd->lock('/election/my-service'));

$lock = $leadership->await(); // now we are the leader
try {
    // ... lead until we stop ...
} finally {
    $lock->unlock();
}
```

**Try once, don't wait.** When you would rather do something else than queue,
`tryLock()` attempts the lock a single time and returns right away: a `Lock` if it
was free and is now yours, or `null` if another instance holds it.

```php
$lock = $etcd->tryLock('/election/my-service');
if ($lock === null) {
    // Someone else leads this round, carry on as a follower.
    return;
}

try {
    // We are the leader.
} finally {
    $lock->unlock();
}
```
