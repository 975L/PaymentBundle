<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Command;

use c975L\PaymentBundle\Service\InAppCatalogPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:payment:store:publish',
    description: 'Creates or updates the in-app products at Apple and Google from what the site sells',
)]
class StorePublishCommand extends Command
{
    public function __construct(
        private readonly InAppCatalogPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('product', InputArgument::OPTIONAL, 'Only this product id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->publisher->publish($input->getArgument('product'));
        if ([] === $report) {
            $io->warning('Nothing was published: no product for sale, or no store holding the keys to write the catalogue.');

            return Command::SUCCESS;
        }

        $io->table(['Product', 'Store', 'Result'], array_map(static fn (array $line): array => [$line['product'], $line['store'], $line['error'] ?? 'OK'], $report));
        $failed = array_filter($report, static fn (array $line): bool => null !== $line['error']);
        if ([] !== $failed) {
            $io->error(\count($failed) . ' publication(s) failed.');

            return Command::FAILURE;
        }

        $io->success(\count($report) . ' publication(s) done.');

        return Command::SUCCESS;
    }
}
