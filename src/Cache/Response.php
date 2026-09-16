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

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;
use ScandiPWA\PersistedQuery\Model\PurgeCache;
use ScandiPWA\PersistedQuery\Model\PurgeCacheException;
use Zend_Cache;

class Response extends TagScope
{
    public const string TYPE_IDENTIFIER = 'persisted_query_response';

    public const string CACHE_TAG = 'PERSISTED_QUERY_RESPONSE';

    public const string POOL_TAG = 'persisted_q_resp';

    /**
     * @param FrontendPool $frontendPool
     * @param PurgeCache $purgeCache
     */
    public function __construct(
        FrontendPool $frontendPool,
        private readonly PurgeCache $purgeCache
    ) {
        parent::__construct($frontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }

    /**
     * @param string $mode
     * @param array $tags
     * @return bool
     * @throws PurgeCacheException
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        return $this->purgeCache->sendPoolPurgeRequest(self::POOL_TAG);
    }
}
