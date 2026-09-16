<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery\Cache\Observer;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ScandiPWA\PersistedQuery\Cache\Response as ResponseCache;

class FlushAllPQ implements ObserverInterface
{
    /**
     * @var bool
     */
    private $cacheState;

    /**
     * @param StateInterface $cacheState
     * @param ResponseCache $responseCache
     */
    public function __construct(
        StateInterface $cacheState,
        private readonly ResponseCache $responseCache
    ) {
        $this->cacheState = $cacheState->isEnabled(strtolower(ResponseCache::CACHE_TAG));
    }

    /**
     * flush the persisted query response cache when the cache type is enabled.
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->cacheState) {
            $this->responseCache->clean();
        }
    }
}
