<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery\Plugin;

use Magento\Catalog\Model\Indexer\Category\Product\AbstractAction;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use ScandiPWA\PersistedQuery\Cache\Response as ResponseCache;

class InvalidatePQCache
{
    /**
     * @var bool
     */
    private $cacheState;

    /**
     * @param TypeListInterface $typeList
     * @param StateInterface $cacheState
     */
    public function __construct(
        private readonly TypeListInterface $typeList,
        StateInterface $cacheState
    ) {
        $this->cacheState = $cacheState->isEnabled(strtolower(ResponseCache::CACHE_TAG));
    }

    /**
     * @param AbstractAction $subject
     * @param AbstractAction $result
     * @return AbstractAction
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(AbstractAction $subject, AbstractAction $result)
    {
        if ($this->cacheState) {
            $this->typeList->invalidate(ResponseCache::TYPE_IDENTIFIER);
        }
        return $result;
    }
}
