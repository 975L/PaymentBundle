<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Command;

use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Gateway\RevolutGateway;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'c975l:payment:revolut:webhook',
    description: 'Declares the site\'s webhook endpoint at Revolut and stores the signing secret it answers',
    help: <<<'TXT'
Revolut offers no screen to declare a webhook, and answers its signing secret once. This does both
steps of that setup - the call and the storing - for the space the payment test mode names, the
sandbox and the live account being two separate accounts each with a webhook of their own:

  <info>php %command.full_name%</info>

The endpoint is built from the "site-url" config; a site whose url is not stored there, or a site being
set up behind another address, names it itself:

  <info>php %command.full_name% --url=https://example.com</info>

Revolut takes a second webhook on the same url rather than replacing the first, and then delivers every
event twice - one accepted on the secret stored here, the other refused and retried. So a url already
declared stops this command, unless it is told to take the old one down first:

  <info>php %command.full_name% --replace</info>

The secret is printed before it is stored, so it is not lost when storing it is refused - which is what
happens to a sensitive value while C975L_VAULT_KEY is undefined.
TXT
)]
class RevolutWebhookCommand extends Command
{
    public function __construct(
        private readonly RevolutGateway $revolutGateway,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly PaymentTestModeInterface $testMode,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Site url the endpoint is built from, when "site-url" is not the one to use')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Delete the webhooks already declared for this endpoint before declaring a new one')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The sandbox and the live space each hold their own webhook and their own secret, and the mode decides which one this run talks to - the same mode the gateway itself reads to pick its key
        $isTestMode = $this->testMode->isEnabled();
        $io->text('Space: ' . ($isTestMode ? 'sandbox (payment test mode is on)' : 'live'));

        if (!$this->revolutGateway->isConfigured()) {
            $io->error('No Revolut secret key is stored for the ' . ($isTestMode ? 'test' : 'live') . ' mode. Store it first, the webhook is declared with it.');

            return Command::FAILURE;
        }

        $siteUrl = $input->getOption('url') ?? $this->siteUrlResolver->siteUrl();
        if (!is_string($siteUrl) || '' === $siteUrl) {
            $io->error('No site url to build the endpoint from: store "site-url" in the configuration, or pass --url.');

            return Command::FAILURE;
        }

        // The route the bundle answers events on, its last segment being the gateway's own slug
        $endpoint = rtrim($siteUrl, '/') . '/payment/webhook/' . RevolutGateway::SLUG;

        $status = $this->clearEndpoint($endpoint, (bool) $input->getOption('replace'), $io);
        if (Command::SUCCESS !== $status) {
            return $status;
        }

        try {
            $secret = $this->revolutGateway->registerWebhook($endpoint);
        } catch (\Exception $e) {
            $io->error('Revolut refused to declare ' . $endpoint . ': ' . $e->getMessage());

            return Command::FAILURE;
        }

        // Said before it is stored, and said whatever storing it does next: Revolut answers this secret once, and a run that printed nothing would leave the operator with a webhook they cannot check the events of
        $io->success('Webhook declared at ' . $endpoint);
        $io->text('Signing secret: ' . $secret);

        return $this->store($isTestMode ? 'revolut-webhook-secret-test' : 'revolut-webhook-secret', $secret, $io, $output);
    }

    /**
     * Takes down what is already declared for this endpoint, or stops the run rather than stacking on it.
     *
     * Revolut has no call that replaces a webhook: a second one on the same url is simply added, and both then
     * deliver. Only the one whose secret is stored here is accepted, the other being refused and retried three
     * times - a setup that looks finished and fills the provider's dashboard with failures.
     */
    private function clearEndpoint(string $endpoint, bool $replace, SymfonyStyle $io): int
    {
        try {
            $existing = array_filter($this->revolutGateway->listWebhooks(), fn (array $webhook): bool => ($webhook['url'] ?? null) === $endpoint);
        } catch (\Exception $e) {
            $io->error('Revolut could not be asked what is already declared: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $existing) {
            return Command::SUCCESS;
        }

        if (!$replace) {
            $io->error(\count($existing) . ' webhook(s) already declared for ' . $endpoint . '. Run again with --replace to take them down and declare a new one, or delete them from Revolut yourself.');

            return Command::FAILURE;
        }

        foreach ($existing as $webhook) {
            $id = $webhook['id'] ?? null;
            if (!is_string($id) || '' === $id) {
                continue;
            }

            try {
                $this->revolutGateway->deleteWebhook($id);
                $io->text('Deleted the webhook ' . $id);
            } catch (\Exception $e) {
                $io->error('The webhook ' . $id . ' could not be deleted, and a new one beside it would be delivered to twice: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    // Stored through c975l:config:set rather than by writing the row here: it is the one place that knows when a sensitive value may be written and how it is encrypted, and a second copy of that rule in this bundle would be one to keep in step
    private function store(string $slug, string $secret, SymfonyStyle $io, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if (null === $application) {
            $io->warning('Store it yourself: php bin/console c975l:config:set ' . $slug . ' "' . $secret . '"');

            return Command::SUCCESS;
        }

        $status = $application->find('c975l:config:set')->run(
            new ArrayInput(['slug' => $slug, 'value' => $secret]),
            $output
        );

        if (Command::SUCCESS !== $status) {
            $io->warning('The secret was not stored. Once the reason above is fixed: php bin/console c975l:config:set ' . $slug . ' "' . $secret . '"');
        }

        return $status;
    }
}
