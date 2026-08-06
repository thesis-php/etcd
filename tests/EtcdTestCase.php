<?php

declare(strict_types=1);

namespace Thesis\Etcd;

use Testo\Lifecycle\BeforeTest;
use Thesis\Grpc;

abstract class EtcdTestCase
{
    private ?Client $client = null;

    final protected function etcd(): Client
    {
        return $this->client ??= self::connect();
    }

    #[BeforeTest]
    final public function reset(): void
    {
        $this->etcd()->deletePrefix('');
    }

    private static function connect(): Client
    {
        $host = getenv('ETCD_HOST');

        return new Client(
            new Grpc\Client\Builder()
                ->withHost($host !== false && $host !== '' ? $host : '127.0.0.1:2379')
                ->build(),
        );
    }
}
