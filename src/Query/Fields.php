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
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Language\Visitor;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\GraphQl\Query\Fields as CoreFields;
use Psr\Log\LoggerInterface;

class Fields extends CoreFields
{
    /**
     * @var string[]
     */
    private $fieldsUsedInQuery = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * resolved on demand: a constructor here would break the parent's own QueryParser wiring
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = ObjectManager::getInstance()->get(LoggerInterface::class);
        }

        return $this->logger;
    }

    /**
     * @param string $query
     * @param array|null $variables
     * @return void
     */
    public function setQuery($query, ?array $variables = null)
    {
        $queryFields = [];

        try {
            if ($query instanceof DocumentNode) {
                $queryAst = $query;
            } else {
                $queryAst = Parser::parse(new Source($query ?: '', 'GraphQL'));
            }

            Visitor::visit(
                $queryAst,
                [
                    'leave' => [
                        NodeKind::NAME => function (Node $node) use (&$queryFields) {
                            $queryFields[$node->value] = $node->value;
                        }
                    ]
                ]
            );

            if (isset($variables)) {
                $queryFields = array_merge($queryFields, $this->extractVariables($variables));
            }
        } catch (Exception $e) {
            // the query processor surfaces the real parse error later, so collecting nothing is enough here
            $this->getLogger()->debug(
                'PersistedQuery: field extraction skipped, query failed to parse',
                ['exception' => $e]
            );
        }

        if (
            isset($queryFields['IntrospectionQuery'])
            || isset($queryFields['__schema'])
            || isset($queryFields['__type'])
        ) {
            // an introspection query must reach every field, so nothing is pruned for it
            $queryFields = [];
        }

        $this->fieldsUsedInQuery = $queryFields;
    }

    /**
     * stateful: relies on setQuery() having run for this request
     * @return string[]
     */
    public function getFieldsUsedInQuery()
    {
        return $this->fieldsUsedInQuery;
    }

    /**
     * reset the slot this class redeclares, which the parent's own _resetState() cannot see
     * @return void
     */
    public function _resetState(): void
    {
        // without this the query fields of one request leak into the next under RoadRunner or Swoole
        $this->fieldsUsedInQuery = [];
    }

    /**
     * @param array $variables
     * @return string[]
     */
    private function extractVariables(array $variables): array
    {
        $fields = [];
        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                // phpcs:ignore Magento2.Performance.ForeachArrayMerge
                $fields = array_merge($fields, $this->extractVariables($value));
            }

            // a list index can never name a GraphQL field, so collecting it only widens the schema
            if (is_string($key)) {
                $fields[$key] = $key;
            }
        }

        return $fields;
    }
}
