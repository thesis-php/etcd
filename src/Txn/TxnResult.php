<?php

declare(strict_types=1);

namespace Thesis\Etcd\Txn;

use Etcdserverpb\ResponseOp;
use Etcdserverpb\TxnResponse;
use Thesis\Etcd\Exception;
use Thesis\Etcd\PutResult;
use Thesis\Etcd\RangeResult;

/**
 * The outcome of a transaction.
 *
 * `$succeeded` tells which branch ran (`then` when true, `otherwise` when
 * false). `$responses` holds one entry per operation of that branch, in order:
 * a {@see RangeResult} for a get, a {@see PutResult} for a put, the deleted
 * count (int) for a delete, and a nested {@see TxnResult} for a nested txn.
 * `$revision` is the store revision the transaction was applied at.
 *
 * @api
 */
final readonly class TxnResult
{
    /**
     * @param list<RangeResult|PutResult|int|TxnResult> $responses
     */
    public function __construct(
        public bool $succeeded,
        public array $responses,
        public int $revision,
    ) {}

    /**
     * @internal
     */
    public static function fromResponse(TxnResponse $response): self
    {
        return new self(
            succeeded: $response->succeeded,
            responses: array_map(self::mapResponse(...), $response->responses),
            revision: $response->header->revision ?? 0,
        );
    }

    /**
     * @throws Exception\UnexpectedResponseException
     */
    private static function mapResponse(ResponseOp $op): RangeResult|PutResult|int|self
    {
        $response = $op->response;

        return match (true) {
            $response instanceof ResponseOp\ResponseResponseRange => RangeResult::fromPb($response->responseRange ?? throw self::malformed('range')),
            $response instanceof ResponseOp\ResponseResponsePut => PutResult::fromPb($response->responsePut ?? throw self::malformed('put')),
            $response instanceof ResponseOp\ResponseResponseDeleteRange => ($response->responseDeleteRange ?? throw self::malformed('delete'))->deleted,
            $response instanceof ResponseOp\ResponseResponseTxn => self::fromResponse($response->responseTxn ?? throw self::malformed('txn')),
            default => throw self::malformed('unknown'),
        };
    }

    private static function malformed(string $kind): Exception\UnexpectedResponseException
    {
        return new Exception\UnexpectedResponseException("etcd returned a malformed {$kind} response inside a transaction");
    }
}
