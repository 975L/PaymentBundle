<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Command;

use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\PaymentBundle\Command\RevolutWebhookCommand;
use c975L\PaymentBundle\Gateway\RevolutGateway;
use c975L\PaymentBundle\Service\PaymentTestModeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

// The only setup step of a Revolut shop that is not done from the back-office: what it refuses to do matters as much as what it does, a webhook declared twice looking exactly like one declared once
class RevolutWebhookCommandTest extends TestCase
{
    private ?array $stored = null;

    // The endpoint is the site's url plus the route the bundle answers events on, and the secret goes to the entry the mode names
    public function testTheWebhookIsDeclaredAndItsSecretStored(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([]);
        $gateway->expects($this->once())->method('registerWebhook')
            ->with('https://example.com/payment/webhook/revolut')
            ->willReturn('wsk_1');

        $tester = $this->tester($gateway);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('wsk_1', $tester->getDisplay());
        $this->assertSame(['revolut-webhook-secret', 'wsk_1'], $this->stored);
    }

    // The sandbox and the live account are two accounts with a webhook each, and their secrets are two entries
    public function testTheTestModeStoresTheSecretUnderTheTestEntry(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([]);
        $gateway->expects($this->once())->method('registerWebhook')->willReturn('wsk_test_1');

        $tester = $this->tester($gateway, testMode: true);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('sandbox', $tester->getDisplay());
        $this->assertSame(['revolut-webhook-secret-test', 'wsk_test_1'], $this->stored);
    }

    // Revolut adds a second webhook on the same url rather than replacing the first: both then deliver, one is accepted and the other refused and retried
    public function testAnEndpointAlreadyDeclaredStopsTheRun(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([['id' => 'wh_1', 'url' => 'https://example.com/payment/webhook/revolut']]);
        $gateway->expects($this->never())->method('registerWebhook');

        $tester = $this->tester($gateway);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('--replace', $tester->getDisplay());
        $this->assertNull($this->stored);
    }

    // A webhook declared for another site sharing the same Revolut account is none of this run's business
    public function testAWebhookOnAnotherEndpointIsLeftAlone(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([['id' => 'wh_1', 'url' => 'https://autre-site.fr/payment/webhook/revolut']]);
        $gateway->expects($this->never())->method('deleteWebhook');
        $gateway->method('registerWebhook')->willReturn('wsk_1');

        $this->assertSame(Command::SUCCESS, $this->tester($gateway)->execute([]));
    }

    public function testReplaceTakesTheOldWebhookDownFirst(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([['id' => 'wh_1', 'url' => 'https://example.com/payment/webhook/revolut']]);
        $gateway->expects($this->once())->method('deleteWebhook')->with('wh_1');
        $gateway->method('registerWebhook')->willReturn('wsk_2');

        $tester = $this->tester($gateway);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--replace' => true]));
        $this->assertSame(['revolut-webhook-secret', 'wsk_2'], $this->stored);
    }

    // A deletion that fails would leave the old webhook standing and a new one beside it, which is the very state --replace is there to avoid
    public function testAFailedDeletionDeclaresNothing(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([['id' => 'wh_1', 'url' => 'https://example.com/payment/webhook/revolut']]);
        $gateway->method('deleteWebhook')->willThrowException(new \RuntimeException('Revolut is down'));
        $gateway->expects($this->never())->method('registerWebhook');

        $this->assertSame(Command::FAILURE, $this->tester($gateway)->execute(['--replace' => true]));
    }

    // The webhook is declared with the key of the mode in use, and a mode holding none has nothing to declare it with
    public function testAModeWithoutItsKeyDeclaresNothing(): void
    {
        $gateway = $this->gateway(isConfigured: false);
        $gateway->expects($this->never())->method('listWebhooks');

        $tester = $this->tester($gateway);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('secret key', $tester->getDisplay());
    }

    // The endpoint is an absolute url and the console has no request to read the host from
    public function testWithoutASiteUrlNothingIsDeclared(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('registerWebhook');

        $tester = $this->tester($gateway, siteUrl: null);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('site-url', $tester->getDisplay());
    }

    // A site being set up behind another address names the one to declare itself
    public function testTheUrlOptionNamesTheEndpoint(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([]);
        $gateway->expects($this->once())->method('registerWebhook')
            ->with('https://staging.example.com/payment/webhook/revolut')
            ->willReturn('wsk_1');

        $this->assertSame(Command::SUCCESS, $this->tester($gateway)->execute(['--url' => 'https://staging.example.com/']));
    }

    // Revolut refusing the declaration is said as it said it, and nothing is stored on a webhook that does not exist
    public function testARefusedDeclarationStoresNothing(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([]);
        $gateway->expects($this->once())->method('registerWebhook')->willThrowException(new \RuntimeException('422 Unprocessable Content'));

        $tester = $this->tester($gateway);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('422 Unprocessable Content', $tester->getDisplay());
        $this->assertNull($this->stored);
    }

    // Revolut answers the secret once: a run that stored nothing must still leave it on screen, or the webhook it just declared can never be checked
    public function testTheSecretIsShownEvenWhenStoringItFails(): void
    {
        $gateway = $this->gateway();
        $gateway->method('listWebhooks')->willReturn([]);
        $gateway->expects($this->once())->method('registerWebhook')->willReturn('wsk_1');

        $tester = $this->tester($gateway, storeFails: true);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('wsk_1', $tester->getDisplay());

        // The block the warning is printed in wraps at the terminal width, so the command line it hands over is read on a single line before being looked for
        $this->assertStringContainsString('c975l:config:set revolut-webhook-secret', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function gateway(bool $isConfigured = true): RevolutGateway | MockObject
    {
        $gateway = $this->createMock(RevolutGateway::class);
        $gateway->method('isConfigured')->willReturn($isConfigured);

        return $gateway;
    }

    private function tester(RevolutGateway | MockObject $gateway, bool $testMode = false, ?string $siteUrl = 'https://example.com', bool $storeFails = false): CommandTester
    {
        $mode = $this->createStub(PaymentTestModeInterface::class);
        $mode->method('isEnabled')->willReturn($testMode);

        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn($siteUrl);

        $command = new RevolutWebhookCommand($gateway, $siteUrlResolver, $mode);

        // The secret is stored through c975l:config:set, which the command finds on the application rather than writing the row itself - so the application is what the double is hung on
        $application = new Application();
        $application->addCommand($command);
        $application->addCommand($this->configSetCommand($storeFails));

        return new CommandTester($command);
    }

    private function configSetCommand(bool $fails): Command
    {
        $command = new class ($this, $fails) extends Command {
            public function __construct(private readonly RevolutWebhookCommandTest $test, private readonly bool $fails)
            {
                parent::__construct('c975l:config:set');
            }

            protected function configure(): void
            {
                $this
                    ->addArgument('slug', InputArgument::REQUIRED)
                    ->addArgument('value', InputArgument::REQUIRED)
                ;
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if ($this->fails) {
                    return Command::FAILURE;
                }

                $this->test->recordStored((string) $input->getArgument('slug'), (string) $input->getArgument('value'));

                return Command::SUCCESS;
            }
        };

        return $command;
    }

    public function recordStored(string $slug, string $value): void
    {
        $this->stored = [$slug, $value];
    }
}
