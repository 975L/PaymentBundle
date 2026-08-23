<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Command;

use c975L\PaymentBundle\Service\BasketReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:payment:baskets:remind',
    description: 'Reminds the customers who validated an order and never paid it, on the first and the seventh day',
)]
class RemindAbandonedBasketsCommand extends Command
{
    public function __construct(
        private readonly BasketReminderService $basketReminderService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->basketReminderService->send();

        $io->success(sprintf('%d reminder(s) sent.', $count));

        return Command::SUCCESS;
    }
}
