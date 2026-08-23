<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Command;

use c975L\PaymentBundle\Service\BasketRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:payment:baskets:retention',
    description: 'Deletes the baskets nothing keeps any more and archives the orders that are no longer current business',
)]
class BasketRetentionCommand extends Command
{
    public function __construct(
        private readonly BasketRetentionService $basketRetentionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $counts = $this->basketRetentionService->run();

        $io->table(
            ['Step', 'Baskets'],
            [
                ['Unvalidated deleted (' . BasketRetentionService::UNVALIDATED_DAYS . ' days)', $counts['unvalidated']],
                ['Abandoned deleted (' . BasketRetentionService::ABANDONED_DAYS . ' days)', $counts['abandoned']],
                ['Archived (' . BasketRetentionService::ARCHIVE_YEARS . ' years)', $counts['archived']],
                ['Expired deleted (' . BasketRetentionService::RETENTION_YEARS . ' years)', $counts['expired']],
            ],
        );

        $io->success('Basket retention applied.');

        return Command::SUCCESS;
    }
}
