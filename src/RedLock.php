<?php
declare(strict_types = 1);

namespace RedLock;

use Redis;

class RedLock
{
    private $retryDelay;
    private $retryCount;
    private $clockDriftFactor = 0.01;
    private $quorum;

    private $instances = array();

    public function __construct(array $redisInstances, int $retryDelay = 200, int $retryCount = 3)
    {
        $this->instances = $redisInstances;
        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;

        $this->quorum = min(count($this->instances), (count($this->instances) / 2 + 1));
    }

    public function lock(string $resource, int $ttl)
    {
        $token = uniqid();
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token' => $token,
                ];

            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = random_int((int) floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);

            $retry--;

        } while ($retry > 0);

        return false;
    }

    public function unlock(string $resource, string $token)
    {
        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function lockInstance(Redis $instance, string $resource, string $token, int $ttl)
    {
        $result = $instance->set($resource, $token, ['NX', 'PX' => $ttl]);

        return $result;
    }

    private function unlockInstance(Redis $instance, string $resource, string $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        return $instance->eval($script, [$resource, $token], 1);
    }
}
