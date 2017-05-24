<?php

namespace Amp\Cache;

use Memcached;
use Amp\Deferred;
use Amp\Success;

class MemcachedCache {
    /** @var Memcached */
    private $client;

    /**
     * {@inheritdoc}
     */
    public function __construct(Memcached $client) {
        // adding another layer of in-memory cache. if we try to resolve the 
        // same domain multiple times w/in one client request, we can memoize 
        // the result instead of hitting memcached again
        $this->arrayCache = new ArrayCache;
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key) {
        $deferred = new Deferred();

        $this->arrayCache->get($key)->when(function($err = null, $value = null) use($key, $deferred) {
            if ($value !== null) {
                $deferred->succeed($value);
                return;
            }
            // if nothing found in array cache, get from memcached
            $exists = $this->client->getDelayed([$key], false, function($client, $value) use($key, $deferred) {
                $value = $value['value'];
                $this->arrayCache->set($key, $value, 300);
                $deferred->succeed($value);
            });

            if (!$exists) {
                $deferred->succeed(null);
            }
        });

        return $deferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl === null ? 0 : $ttl;
        $this->arrayCache->set($key, $value, $ttl);
        $this->client->set($key, $value, $ttl);
        return new Success;
    }

    /**
     * {@inheritdoc}
     */
    public function del($key) {
        $this->arrayCache->del($key);
        return new Success($this->client->delete($key));
    }
}
