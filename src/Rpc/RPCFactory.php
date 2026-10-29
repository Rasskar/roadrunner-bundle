<?php

namespace Rasskar\RoadRunnerBundle\Rpc;

use RuntimeException;
use Spiral\Goridge\RPC\RPC;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\EnvironmentInterface;

/**
 * Creates the RPC client to RoadRunner from RR_RPC (`rpc.listen` in .rr.yaml).
 * One shared connection per worker process for all bundle features (KV, Jobs, ...).
 */
final class RPCFactory
{
    /**
     * @param EnvironmentInterface $environment
     * @return RPCInterface
     * @throws RuntimeException
     */
    public static function fromEnvironment(EnvironmentInterface $environment): RPCInterface
    {
        $address = $environment->getRPCAddress();

        if (empty($address)) {
            throw new RuntimeException(
                'RoadRunner RPC is not configured. Add an "rpc:" section (with "listen:") to your .rr.yaml.'
            );
        }

        return RPC::create($address);
    }
}
