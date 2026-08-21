<?php

/*
 * (c) 2025: 975L <contact@975l.com>
 * (c) 2025: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Command;

use c975L\PaymentBundle\Service\BasketServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:shop:baskets:delete',
    description: 'Deletes new baskets that have not been validated after 7 days',
)]
class DeleteUnvalidatedBasketsCommand extends Command
{
    public function __construct(
        private readonly BasketServiceInterface $basketService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->basketService->deleteUnvalidated();

        $io->success('Unvalidate baskets deleted.');

        return Command::SUCCESS;
    }
}
