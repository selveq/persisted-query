<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery;

use Credis_Client;
use Exception;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Psr\Log\LoggerInterface;

class RedisClient
{
    protected const string PERSISTENT_QUERY_CONFIG = 'cache/persisted-query';

    protected const string QUERY_KEY_PREFIX = 'pq:query:';

    protected const string TTL_KEY_PREFIX = 'pq:ttl:';

    /** keys expire and are never refreshed, so an abandoned or squatted hash frees itself */
    public const int KEY_LIFETIME = 2592000;

    /**
     * @var mixed|null
     */
    private $cacheConfig;

    /**
     * @var Credis_Client
     */
    private $client;

    /**
     * @param DeploymentConfig $config
     * @param LoggerInterface $logger
     * @param string $redisClientClass
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function __construct(
        DeploymentConfig $config,
        private readonly LoggerInterface $logger,
        private readonly string $redisClientClass = Credis_Client::class
    ) {
        $this->cacheConfig = $config->get(self::PERSISTENT_QUERY_CONFIG);

        /** Do not initialize client, if configuration is missing */
        if ($this->configExists()) {
            $this->client = $this->redisClientFactory($this->cacheConfig['redis']);
        } else {
            $this->logConfigurationWarning();
            $this->client = null;
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function validateConfiguration()
    {
        if (!$this->configExists()) {
            throw new Exception('Redis is not configured for persistent queries');
        }
    }

    /**
     * @param array $redisConfig
     * @return Credis_Client
     */
    private function redisClientFactory(array $redisConfig): Credis_Client
    {
        return new $this->redisClientClass(
            $redisConfig['host'],
            $redisConfig['port'],
            10,
            '',
            $redisConfig['database'],
            // Credis authenticates on connect; without these a requirepass Redis answers NOAUTH to every call
            $redisConfig['password'] ?? null,
            $redisConfig['username'] ?? null
        );
    }

    /**
     * @return bool
     */
    private function configExists()
    {
        return (($this->cacheConfig !== null) && array_key_exists('redis', $this->cacheConfig));
    }

    /**
     * store a query document under its hash, expiring with every other key this module writes
     * @param string $hash
     * @param string $query
     * @return bool
     * @throws Exception
     */
    public function saveQuery(string $hash, string $query): bool
    {
        $this->validateConfiguration();
        return (bool)$this->client->setex(self::QUERY_KEY_PREFIX . $hash, self::KEY_LIFETIME, $query);
    }

    /**
     * the query document registered under a hash, or null when nothing is stored
     * @param string $hash
     * @return string|null
     * @throws Exception
     */
    public function getQuery(string $hash): ?string
    {
        $this->validateConfiguration();

        return $this->readKey(self::QUERY_KEY_PREFIX . $hash);
    }

    /**
     * forget a hash entirely, so the client re-registers it on its next 410
     * @param string $hash
     * @return void
     * @throws Exception
     */
    public function deleteQuery(string $hash): void
    {
        $this->validateConfiguration();
        $this->client->del(self::QUERY_KEY_PREFIX . $hash, self::TTL_KEY_PREFIX . $hash);
    }

    /**
     * store the shared-cache lifetime the client asked for, already clamped by the caller
     * @param string $hash
     * @param int $ttl
     * @return bool
     * @throws Exception
     */
    public function saveTtl(string $hash, int $ttl): bool
    {
        $this->validateConfiguration();

        return (bool)$this->client->setex(self::TTL_KEY_PREFIX . $hash, self::KEY_LIFETIME, (string)$ttl);
    }

    /**
     * the lifetime registered for a hash, or null when the client never asked for one
     * @param string $hash
     * @return int|null
     * @throws Exception
     */
    public function getTtl(string $hash): ?int
    {
        $this->validateConfiguration();
        $stored = $this->readKey(self::TTL_KEY_PREFIX . $hash);

        return $stored !== null && is_numeric($stored) ? (int)$stored : null;
    }

    /**
     * read a key, mapping both a missing key and an empty value onto null
     * @param string $key
     * @return string|null
     */
    private function readKey(string $key): ?string
    {
        $value = $this->client->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * warn once that the module's Redis is not configured, so the caller can no-op instead of failing
     * @return void
     */
    private function logConfigurationWarning()
    {
        $this->logger->warning(
            'Cache flush attempt, while Redis is not configured for persistent queries. Flush request ignored. '
            . 'Update env.php with "cache/persisted-query" configuration.'
        );
    }

    /**
     * drop every persisted query and TTL key, or no-op when Redis is not configured
     * @return bool
     */
    public function flushDb(): bool
    {
        if (!$this->configExists()) {
            $this->logConfigurationWarning();

            return true;
        }

        return (bool)$this->client->flushdb();
    }
}
