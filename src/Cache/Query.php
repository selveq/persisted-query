<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery\Cache;

use ScandiPWA\PersistedQuery\RedisClient;
use Zend_Cache;

class Query
{
    public const string TYPE_IDENTIFIER = 'PERSISTED_QUERY';

    public const string CACHE_TAG = 'PERSISTED_QUERY';

    /**
     * @param RedisClient $redisClient
     * @param Response $responseCache
     */
    public function __construct(
        private readonly RedisClient $redisClient,
        private readonly Response $responseCache
    ) {}

    /**
     * @param string $mode
     * @param array $tags
     * @return bool
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $varnishPurge = $this->responseCache->clean();
        $redisFlush = $this->redisClient->flushDb();

        return $varnishPurge && $redisFlush;
    }
}
