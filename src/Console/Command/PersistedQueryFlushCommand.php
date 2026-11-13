<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © 2019 Scandiweb, Ltd (https://scandiweb.com)
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

namespace ScandiPWA\PersistedQuery\Console\Command;

use ScandiPWA\PersistedQuery\Cache\Query;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PersistedQueryFlushCommand extends Command
{
    /**
     * @param Query $query
     * @param string|null $name
     */
    public function __construct(
        private readonly Query $query,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('scandipwa:pq:flush')
            ->setDescription('Flush persisted query storage(Redis)');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->query->clean()) {
            $output->writeln(
                '<error>persisted query caches were not flushed: the Varnish purge or the Redis flush failed</error>'
            );

            return Command::FAILURE;
        }

        $output->writeln('Persisted query caches flushed (redis + varnish)');

        return Command::SUCCESS;
    }
}
