<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © Magento, Inc. All rights reserved.
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\PersistedQuery\Query;

use Exception;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\GraphQl\Exception\ExceptionFormatter;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ErrorHandlerInterface;
use Magento\Framework\GraphQl\Query\QueryComplexityLimiter;
use Magento\Framework\GraphQl\Query\QueryDataFormatter;
use Magento\Framework\GraphQl\Query\QueryParser;
use Magento\Framework\GraphQl\Query\QueryProcessor as CoreQueryProcessor;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema;

class QueryProcessor extends CoreQueryProcessor
{
    /**
     * the parent keeps its own private copy; this one is wired by src/etc/di.xml and,
     * mirroring core's own constructor, falls back to the ObjectManager when the di.xml
     * argument is absent — so the 2.4.9 pre-execution DoS guards below can never be
     * silently disarmed by a lost or shadowed di entry.
     * @var QueryParser
     */
    private QueryParser $queryParser;

    /**
     * @param ExceptionFormatter $exceptionFormatter
     * @param QueryComplexityLimiter $queryComplexityLimiter
     * @param ErrorHandlerInterface $errorHandler
     * @param ResponseInterface $response
     * @param QueryDataFormatter $formatter
     * @param QueryParser|null $queryParser
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    public function __construct(
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly QueryComplexityLimiter $queryComplexityLimiter,
        private readonly ErrorHandlerInterface $errorHandler,
        private readonly ResponseInterface $response,
        private readonly QueryDataFormatter $formatter,
        ?QueryParser $queryParser = null
    ) {
        $this->queryParser = $queryParser ?: ObjectManager::getInstance()->get(QueryParser::class);

        parent::__construct(
            $exceptionFormatter,
            $queryComplexityLimiter,
            $errorHandler,
            $formatter,
            $queryParser
        );
    }

    /**
     * process a GraphQl query according to defined schema
     * @param Schema $schema
     * @param string $source
     * @param ContextInterface|null $contextValue
     * @param array|null $variableValues
     * @param string|null $operationName
     * @return array
     * @throws GraphQlInputException
     * @throws Exception
     */
    public function process(
        Schema $schema,
        $source, // can be both string and parsed document
        ?ContextInterface $contextValue = null,
        ?array $variableValues = null,
        ?string $operationName = null
    ): array {
        if (is_string($source)) {
            // QueryParser memoizes the AST and enforces the nesting depth before the recursive parser runs
            $source = $this->queryParser->parse($source);
        }

        // core runs these three guards here, and this preference replaces core for every GraphQL request
        if ($source instanceof DocumentNode && !$this->exceptionFormatter->shouldShowDetail()) {
            $this->queryComplexityLimiter->validateQueryLength($source);
            $this->queryComplexityLimiter->validateAliasCount($source);
            $this->queryComplexityLimiter->validateFieldCount($source);
        }

        // unconditional, unlike core: this registers the QueryComplexity rule the header below reads back
        $this->queryComplexityLimiter->execute();

        /** @var QueryComplexity $queryComplexity */
        $queryComplexity = DocumentValidator::getRule(QueryComplexity::class);

        $rootValue = null;
        $result = GraphQL::executeQuery(
            $schema,
            $source,
            $rootValue,
            $contextValue,
            $variableValues,
            $operationName
        )->setErrorsHandler(
            [$this->errorHandler, 'handle']
        )->toArray(
            $this->exceptionFormatter->shouldShowDetail() ?
                DebugFlag::INCLUDE_DEBUG_MESSAGE : 0
        );

        $this->response->setHeader('Query-Complexity', $queryComplexity->getMaxQueryComplexity(), true);

        return $this->formatter->formatResponse($result);
    }
}
