<?php

namespace Rasskar\RoadRunnerBundle\Kv;

use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\KeyValue\Factory;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

/**
 * Symfony cache adapter for a named RoadRunner KV storage (`kv:` section of .rr.yaml).
 * Every operation is an RPC call to RoadRunner; values are serialized with PHP serialize().
 */
final class KvCacheAdapter extends Psr16Adapter
{
    /**
     * @param string $dsn
     * @param array{rpc: RPCInterface, storage: string} $options
     * @return self
     */
    public static function createConnection(string $dsn, array $options = []): self
    {
        $factory = new Factory($options['rpc']);
        $storage = $factory->select($options['storage']);

        return new self($storage);
    }
}
