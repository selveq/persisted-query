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

use Closure;
use Exception;
use GraphQL\Language\AST\DocumentNode;
use Laminas\Http\Response as HttpResponse;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as MagentoHttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\GraphQl\Exception\ExceptionFormatter;
use Magento\Framework\GraphQl\Query\Fields as QueryFields;
use Magento\Framework\GraphQl\Query\QueryParser;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Webapi\Response;
use Magento\GraphQl\Model\Query\ContextFactoryInterface;
use Magento\GraphQlCache\Model\CacheableQuery;
use Psr\Log\LoggerInterface;
use ScandiPWA\PersistedQuery\Cache\Response as ResponseCache;
use ScandiPWA\PersistedQuery\Model\QueryHash;
use ScandiPWA\PersistedQuery\RedisClient;
use Throwable;

class PersistedQuery
{
    /** fallback Cache-control max-age when neither a stored TTL nor a configured default applies */
    protected const int QUERY_TTL = 60;

    /** longest query text a client may register, bounding the body before the parser walks it */
    protected const int MAX_QUERY_LENGTH = 65536;

    /** the theme sends a 32-bit MurmurHash3 as decimal; this shape keeps arbitrary text out of a Redis key */
    protected const string HASH_PATTERN = '/^-?[A-Za-z0-9_]{1,64}$/';

    protected const string XML_PATH_DEFAULT_TTL = 'scandipwa_persisted_query/cache/default_ttl';

    protected const string XML_PATH_MAX_TTL = 'scandipwa_persisted_query/cache/max_ttl';

    protected const string XML_PATH_VERIFY_HASH = 'scandipwa_persisted_query/registration/verify_hash';

    /**
     * not promoted: the property is protected and its name does not match the $redisClient parameter
     * @var RedisClient
     */
    protected $client;

    /**
     * @var bool
     */
    protected $cacheState;

    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var MagentoHttpResponse
     */
    protected $httpResponse;

    /**
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @param Response $response
     * @param RedisClient $redisClient
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     * @param StateInterface $cacheState
     * @param QueryFields $queryFields
     * @param SchemaGeneratorInterface $schemaGenerator
     * @param QueryProcessor $queryProcessor
     * @param ExceptionFormatter $graphQlError
     * @param ScopeConfigInterface $scopeConfig
     * @param QueryParser $queryParser
     * @param QueryHash $queryHash
     * @param CacheableQuery $cacheableQuery
     * @param JsonFactory|null $jsonFactory
     * @param MagentoHttpResponse|null $httpResponse
     * @param ContextFactoryInterface|null $contextFactory
     */
    public function __construct(
        private readonly Response $response,
        RedisClient $redisClient,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        StateInterface $cacheState,
        private readonly QueryFields $queryFields,
        private readonly SchemaGeneratorInterface $schemaGenerator,
        private readonly QueryProcessor $queryProcessor,
        private readonly ExceptionFormatter $graphQlError,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly QueryParser $queryParser,
        private readonly QueryHash $queryHash,
        private readonly CacheableQuery $cacheableQuery,
        ?JsonFactory $jsonFactory = null,
        ?MagentoHttpResponse $httpResponse = null,
        ?ContextFactoryInterface $contextFactory = null
    ) {
        $this->client = $redisClient;
        $this->cacheState = $cacheState->isEnabled(strtolower(ResponseCache::CACHE_TAG));
        $this->jsonFactory = $jsonFactory ?: ObjectManager::getInstance()->get(JsonFactory::class);
        $this->httpResponse = $httpResponse ?: ObjectManager::getInstance()->get(MagentoHttpResponse::class);
        $this->contextFactory = $contextFactory ?: ObjectManager::getInstance()->get(ContextFactoryInterface::class);
    }

    /**
     * intercept GET/PUT requests carrying a persisted query hash before regular dispatch
     * @param InterceptorInterface $interceptor
     * @param Closure $next
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws Throwable
     */
    public function aroundDispatch(
        InterceptorInterface $interceptor,
        Closure $next,
        RequestInterface $request
    ): ResponseInterface {
        // OPTIONS reaches this endpoint in some setups and has to pass through untouched
        if (!in_array($request->getMethod(), ['GET', 'PUT'])) {
            return $next($request);
        }

        if (!array_key_exists('hash', $request->getParams())) {
            return $interceptor->___callParent('dispatch', [$request]);
        }

        return $this->processRequest($request);
    }

    /**
     * resolve a persisted query by hash, executing it on GET and registering it on PUT
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws Throwable
     */
    public function processRequest(
        RequestInterface $request
    ): ResponseInterface {
        $queryHash = $request->getParam('hash');

        // the hash becomes a Redis key, so it is checked against the shape a client may send
        if (!is_string($queryHash) || !preg_match(self::HASH_PATTERN, $queryHash)) {
            return $this->unknownQuery();
        }

        if ($request->getMethod() === 'PUT') {
            return $this->saveQuery($request, $queryHash);
        }

        $persistedValue = $this->client->getQuery($queryHash);

        if ($persistedValue === null) {
            return $this->unknownQuery();
        }

        try {
            $documentNode = $this->queryParser->parse($persistedValue);
        } catch (Throwable $error) {
            // an unparseable document can only have come from an older storage format or a bad write
            $this->logger->error('PersistedQuery: stored query failed to parse, dropping it', [
                'hash' => $queryHash,
                'exception' => $error
            ]);
            $this->client->deleteQuery($queryHash);

            return $this->unknownQuery();
        }

        $variables = $this->processVariables($request->getParams());

        return $this->processGraphqlRequest(
            $queryHash,
            $documentNode,
            $variables
        );
    }

    /**
     * execute the parsed GraphQL document and write the response headers and body
     * @param string $queryHash
     * @param DocumentNode $documentNode
     * @param array $variables
     * @return MagentoHttpResponse
     * @throws Throwable
     */
    public function processGraphqlRequest(
        string $queryHash,
        $documentNode,
        array $variables
    ): MagentoHttpResponse {
        $statusCode = 200;
        $result = [];
        $jsonResult = $this->jsonFactory->create();

        try {
            // the schema generator prunes to the fields this call records, so it must run before generate()
            $this->queryFields->setQuery($documentNode, $variables);
            $schema = $this->schemaGenerator->generate();

            $result = $this->queryProcessor->process(
                $schema,
                $documentNode,
                $this->contextFactory->create(),
                $variables
            );
        } catch (Exception $error) {
            $this->logger->error('PersistedQuery: GraphQL execution failed', ['exception' => $error]);
            $result['errors'][] = $this->graphQlError->create($error);
            $statusCode = ExceptionFormatter::HTTP_GRAPH_QL_SCHEMA_ERROR_STATUS;
        }

        $responseHasError = array_key_exists('errors', $result) && count($result['errors']);

        $jsonResult->setHttpResponseCode($statusCode);
        $jsonResult->setData($result);
        $jsonResult->renderResult($this->httpResponse);

        // renderResult() has already written the body, so the headers go on the response itself
        if (!$responseHasError && $statusCode === 200) {
            $this->applyCacheHeaders($queryHash);
        }

        return $this->httpResponse;
    }

    /**
     * offer the response to the shared cache, unless a resolver declared the query uncacheable
     * @param string $queryHash
     * @return void
     */
    protected function applyCacheHeaders(string $queryHash): void
    {
        // a resolver said cacheable: false, so this body must not reach a shared cache at all
        if (!$this->cacheableQuery->isCacheable()) {
            $this->httpResponse->setNoCacheHeaders();

            return;
        }

        $queryTTL = $this->cacheState ? $this->client->getTtl($queryHash) : null;
        $this->httpResponse->setHeader('X-Pool', ResponseCache::POOL_TAG);
        $this->httpResponse->setHeader('Cache-control', 'max-age=' . ($queryTTL ?? $this->getDefaultTTL()));
    }

    /**
     * admin-configured Cache-control max-age, in seconds
     * @return int
     */
    protected function getDefaultTTL(): int
    {
        $configuredTTL = $this->scopeConfig->getValue(self::XML_PATH_DEFAULT_TTL);

        // system.xml validates the field with validate-zero-or-greater, so a configured 0 means "do not cache"
        if ($configuredTTL === null || $configuredTTL === '') {
            return self::QUERY_TTL;
        }

        return max(0, (int)$configuredTTL);
    }

    /**
     * ceiling for the lifetime a client may ask for, in seconds, never above the registration's own lifetime
     * @return int
     */
    protected function getMaxTTL(): int
    {
        $configuredTTL = $this->scopeConfig->getValue(self::XML_PATH_MAX_TTL);

        if ($configuredTTL === null || $configuredTTL === '') {
            return RedisClient::KEY_LIFETIME;
        }

        // a cached response must not outlive the key it was registered under
        return min(RedisClient::KEY_LIFETIME, max(0, (int)$configuredTTL));
    }

    /**
     * whether a registration has to prove the hash belongs to the query text
     * @return bool
     */
    protected function isHashVerified(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_VERIFY_HASH);
    }

    /**
     * decode raw request parameters into GraphQL variables
     * @param array $args
     * @return array
     */
    protected function processVariables($args): array
    {
        unset($args['hash']);

        return array_map([$this, 'decodeVariable'], $args);
    }

    /**
     * decode a single raw request parameter
     * @param array|string $value
     * @return mixed
     */
    protected function decodeVariable($value)
    {
        // a list parameter (?foo[]=1&foo[]=2) reaches us as an array, which rawurldecode() cannot take
        if (is_array($value)) {
            return array_map([$this, 'decodeVariable'], $value);
        }

        return json_decode(rawurldecode((string)$value), true);
    }

    /**
     * register a query document under a hash the client chose, without executing it
     * @param RequestInterface $request
     * @param string $queryHash
     * @return ResponseInterface
     * @throws Throwable
     */
    protected function saveQuery(RequestInterface $request, string $queryHash): ResponseInterface
    {
        $requestQuery = $this->readQueryText($request);

        if ($requestQuery === null) {
            return $this->cannotSave();
        }

        if (strlen($requestQuery) > self::MAX_QUERY_LENGTH) {
            return $this->modelledError(
                HttpResponse::STATUS_CODE_413,
                sprintf('Query document exceeds %d bytes', self::MAX_QUERY_LENGTH)
            );
        }

        try {
            // core's parser is the syntax check and the nesting-depth guard in one
            $this->queryParser->parse($requestQuery);
        } catch (Throwable $error) {
            $this->logger->error(
                'GraphQL syntax error while saving query to persistence layer',
                ['exception' => $error]
            );

            return $this->cannotSave();
        }

        if ($this->isHashVerified() && !$this->queryHash->matches($requestQuery, $queryHash)) {
            return $this->modelledError(HttpResponse::STATUS_CODE_400, 'Query hash does not match the query');
        }

        $stored = $this->client->getQuery($queryHash);

        if ($stored !== null) {
            // re-registering the same document is the client retrying, not a conflict
            return $stored === $requestQuery
                ? $this->queryRegistered()
                : $this->modelledError(HttpResponse::STATUS_CODE_409, 'Query hash is already registered');
        }

        if (!$this->client->saveQuery($queryHash, $requestQuery)) {
            $this->logger->error('Redis failed to save query', ['hash' => $queryHash]);

            return $this->cannotSave();
        }

        $this->saveRequestedTTL($request, $queryHash);

        return $this->queryRegistered();
    }

    /**
     * the query document carried by a registration request, or null when the body is unusable
     * @param RequestInterface $request
     * @return string|null
     */
    protected function readQueryText(RequestInterface $request): ?string
    {
        try {
            $requestQuery = $this->serializer->unserialize($request->getContent());
        } catch (Exception $error) {
            $this->logger->error('PersistedQuery: registration body is not JSON', ['exception' => $error]);

            return null;
        }

        if (is_array($requestQuery) && array_key_exists('query', $requestQuery)) {
            $requestQuery = $requestQuery['query'];
        }

        return is_string($requestQuery) && $requestQuery !== '' ? $requestQuery : null;
    }

    /**
     * record the shared-cache lifetime the client asked for, clamped to the configured ceiling
     * @param RequestInterface $request
     * @param string $queryHash
     * @return void
     * @throws Exception
     */
    protected function saveRequestedTTL(RequestInterface $request, string $queryHash): void
    {
        $requested = $request->getHeader('SW-cache-age');

        // an absent or unusable header leaves no key, which is what makes an explicit 0 mean 0
        if (!is_string($requested) || !is_numeric($requested) || (int)$requested < 0) {
            return;
        }

        $this->client->saveTtl($queryHash, min((int)$requested, $this->getMaxTTL()));
    }

    /**
     * the 410 every unusable or unknown hash gets, so the client registers the query again
     * @return ResponseInterface
     */
    protected function unknownQuery(): ResponseInterface
    {
        return $this->modelledError(HttpResponse::STATUS_CODE_410, 'Query hash is unknown');
    }

    /**
     * the 502 a body this module cannot turn into a query document gets
     * @return ResponseInterface
     */
    protected function cannotSave(): ResponseInterface
    {
        return $this->modelledError(HttpResponse::STATUS_CODE_502, 'Can not save the query');
    }

    /**
     * the 201 that tells the client its hash is ready to execute
     * @return ResponseInterface
     */
    protected function queryRegistered(): ResponseInterface
    {
        return $this->writeJson(
            HttpResponse::STATUS_CODE_201,
            ['error' => false, 'code' => '1', 'message' => 'Query registered']
        );
    }

    /**
     * every failure this module models answers in the same shape, and never with a trace
     * @param int $statusCode
     * @param string $message
     * @return ResponseInterface
     */
    protected function modelledError(int $statusCode, string $message): ResponseInterface
    {
        return $this->writeJson($statusCode, [
            'error' => true,
            'code' => (string)$statusCode,
            'message' => $message
        ]);
    }

    /**
     * write one of this module's own JSON answers onto the shared Webapi response
     * @param int $statusCode
     * @param array $body
     * @return ResponseInterface
     */
    private function writeJson(int $statusCode, array $body): ResponseInterface
    {
        $this->response->setStatusCode($statusCode);
        $this->response->setHeader('Content-Type', 'application/json');
        // Chrome keeps a 410 with no freshness fresh forever, so the retry after registering would read it back
        $this->response->setHeader('Cache-Control', 'no-store', true);
        $this->response->setBody(json_encode($body));

        return $this->response;
    }
}
