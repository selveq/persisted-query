<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery\Model;

use Exception;
use Laminas\Uri\Uri;
use Magento\CacheInvalidate\Model\PurgeCache as CorePurgeCache;
use Magento\CacheInvalidate\Model\SocketFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Cache\InvalidateLogger;
use Magento\PageCache\Model\Cache\Server;
use Magento\PageCache\Model\Config;
use Magento\Store\Model\ScopeInterface;

class PurgeCache extends CorePurgeCache
{
    /**
     * @param Server $cacheServer
     * @param SocketFactory $socketAdapterFactory
     * @param InvalidateLogger $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Server $cacheServer,
        SocketFactory $socketAdapterFactory,
        private readonly InvalidateLogger $logger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct(
            $cacheServer,
            $socketAdapterFactory,
            $logger
        );
    }

    /**
     * whether the store's caching application is Varnish; nothing else understands a PURGE
     * @return bool
     */
    protected function getIsPurgeNecessary(): bool
    {
        $cachingApp = $this->scopeConfig->getValue(Config::XML_PAGECACHE_TYPE, ScopeInterface::SCOPE_STORE);
        return $cachingApp == Config::VARNISH;
    }

    /**
     * ban the whole persisted-query pool on every Varnish this store talks to
     * @param string $poolTag
     * @return bool
     * @throws PurgeCacheException
     */
    public function sendPoolPurgeRequest(string $poolTag): bool
    {
        if (!$this->getIsPurgeNecessary()) {
            return true;
        }

        $socketAdapter = $this->socketAdapterFactory->create();
        $servers = $this->cacheServer->getUris();
        $socketAdapter->setOptions(['timeout' => 10]);
        $headers = ['X-Pool' => $poolTag];
        $successful = true;

        foreach ($servers as $server) {
            $headers['Host'] = $server->getHost();

            try {
                $socketAdapter->connect($server->getHost(), $server->getPort());
                $socketAdapter->write(
                    'PURGE',
                    $server,
                    '1.1',
                    $headers
                );
                $response = $socketAdapter->read();
                $socketAdapter->close();
            } catch (Exception $e) {
                $this->logger->critical('PersistedQuery: could not reach Varnish for pool purge', ['exception' => $e]);
                throw new PurgeCacheException(sprintf('Error reaching Varnish: %s', $e->getMessage()));
            }

            $successful = $this->validateResponse($server, $response) && $successful;
        }

        return $successful;
    }

    /**
     * whether a PURGE answered 200; an unrecognisable reply is a failure, not a silent success
     * @param Uri $server
     * @param string $response
     * @return bool
     * @throws PurgeCacheException
     */
    private function validateResponse(Uri $server, string $response): bool
    {
        $regexParse = [];
        preg_match('/^HTTP\/\d+.\d+\s(\d+)([\s\S]+)Date:/', $response, $regexParse);

        if (count($regexParse) < 2) {
            $this->logger->critical(
                'PersistedQuery: unreadable Varnish PURGE response',
                ['host' => $server->getHost()]
            );

            return false;
        }

        $responseCode = $regexParse[1];

        if ($responseCode === '200') {
            return true;
        }

        $message = sprintf(
            'Error flushing Varnish server. Host: "%s". PURGE response code: %s',
            $server->getHost(),
            $responseCode
        );

        if (isset($regexParse[2])) {
            $responseMessage = $regexParse[2];
            $message .= sprintf(' message: %s', trim($responseMessage));
        }

        throw new PurgeCacheException($message);
    }
}
