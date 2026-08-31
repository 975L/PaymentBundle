<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Management\BasketIntegrityHealthCheckProvider;
use c975L\PaymentBundle\Management\PaymentGuidedProjectProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentGuidedProjectProviderTest extends TestCase
{
    private function createAdminUrlGenerator(array &$controllers = []): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnCallback(function (string $controller) use ($generator, &$controllers) {
            $controllers[] = $controller;

            return $generator;
        });
        $generator->method('setAction')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/payment');

        return $generator;
    }

    private function createUrlGenerator(array &$routes = []): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static function (string $route) use (&$routes): string {
                $routes[] = $route;

                return '/management/' . $route;
            }
        );

        return $generator;
    }

    private function createProvider(array &$controllers = [], array &$routes = []): PaymentGuidedProjectProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_ADMIN');

        return new PaymentGuidedProjectProvider(
            $this->createAdminUrlGenerator($controllers),
            $this->createUrlGenerator($routes),
            $configService,
        );
    }

    // The 7000 block GuidedProjectProviderInterface reserves this bundle, at the step of 10 it states - an order shared with another provider's leaves their sequence to the order the providers happen to be registered in, which is what a block per bundle exists to prevent
    public function testGetGuidedProjectsContinuesTheOrderSequence(): void
    {
        $projects = $this->createProvider()->getGuidedProjects();

        $this->assertSame(
            ['payment-test-mode', 'payment-email-attachments', 'payment-transaction-review', 'payment-payment-link', 'payment-gift-card-issue', 'payment-discount-code', 'payment-shipping-grid', 'payment-shipping', 'payment-basket-integrity'],
            array_column($projects, 'slug'),
        );
        // 7015 and 7055 slip between two tens rather than being appended: the documents switch is set beside the test mode, before a first real order, and the delivery grid stands just before the parcel round it prices
        $this->assertSame([7010, 7015, 7020, 7030, 7040, 7050, 7055, 7060, 7070], array_column($projects, 'order'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('payment-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    public function testEveryProjectCarriesThePaymentTranslationDomainAndSteps(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('payment', $project['translation_domain']);
            $this->assertNotEmpty($project['steps']);
        }
    }

    // Every payment management screen sits behind the site's admin role, so a parcours walking them is dropped for anybody else
    public function testEveryProjectCarriesTheAdminRole(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('ROLE_ADMIN', $project['role']);
        }
    }

    public function testNoStepSetsBothUrlAndHighlight(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                $this->assertFalse(
                    isset($step['url']) && isset($step['highlight']),
                    sprintf('Step %d of "%s" sets both url and highlight', $index, $project['slug'])
                );
            }
        }
    }

    // Only the opening step leaves the screen, everything after it walking the one the user has been sent to
    public function testOnlyTheFirstStepOfEachProjectCarriesAnUrl(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $steps = $project['steps'];

            $this->assertArrayHasKey('url', $steps[0], sprintf('Project "%s" does not open on a screen', $project['slug']));

            foreach (array_slice($steps, 1) as $index => $step) {
                $this->assertArrayNotHasKey('url', $step, sprintf('Step %d of "%s" leaves the screen again', $index + 1, $project['slug']));
            }
        }
    }

    // The two toggles live on the dashboard and the order checks on ConfigBundle's health check screen, none of them on a CRUD one
    public function testTheProjectsOpeningOnAPlainRouteNameIt(): void
    {
        $controllers = [];
        $routes = [];
        $this->createProvider($controllers, $routes)->getGuidedProjects();

        $this->assertSame(['management', 'management', 'management_health_check_index'], $routes);
    }

    // Each parcours opens on the listing the task starts from, the two written from the baskets one included
    public function testEachCrudProjectOpensOnItsOwnListing(): void
    {
        $controllers = [];
        $routes = [];
        $this->createProvider($controllers, $routes)->getGuidedProjects();

        $this->assertSame(['PaymentCrudController', 'BasketCrudController', 'GiftCardCrudController', 'DiscountCrudController', 'ShippingZoneCrudController', 'BasketCrudController'], array_map(
            static fn (string $fqcn): string => basename(str_replace('\\', '/', $fqcn)),
            $controllers,
        ));
    }

    // EasyAdmin renders a button as `action-<actionName>`, so a highlight guessing at the name points at nothing
    public function testEveryHighlightedActionIsAnEasyAdminOne(): void
    {
        $actions = $this->easyAdminActionNames();

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                if (!isset($step['highlight']) || !preg_match('/^\.action-(\w+)$/', $step['highlight'], $matches)) {
                    continue;
                }

                $this->assertContains(
                    $matches[1],
                    $actions,
                    sprintf('Step %d of "%s" highlights an action EasyAdmin does not render', $index, $project['slug'])
                );
            }
        }
    }

    private function easyAdminActionNames(): array
    {
        $constants = new \ReflectionClass(Action::class)->getConstants();

        return [...array_values(array_filter(
            $constants,
            static fn (string $name): bool => !str_starts_with($name, 'TYPE_'),
            ARRAY_FILTER_USE_KEY
        )), ...$this->customActionNames()];
    }

    // The names this bundle's CRUD controllers declare themselves, read off their source: EasyAdmin renders `action-<name>` for them just the same, and a highlight pointing at one would fail the check above otherwise
    private function customActionNames(): array
    {
        $names = [];
        foreach (glob(\dirname(__DIR__, 2) . '/src/Controller/Management/*CrudController.php') ?: [] as $file) {
            preg_match_all("/Action::new\\('(\\w+)'/", (string) file_get_contents($file), $matches);
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }

    // Both toggle steps highlight the same shortcut button PaymentShortcutController's route renders on the dashboard
    public function testTheTestModeToggleStepsHighlightTheShortcutButton(): void
    {
        $project = $this->createProvider()->getGuidedProjects()[0];
        $highlights = array_column($project['steps'], 'highlight');

        $this->assertSame(
            ['form[action$="/payment/test-mode-toggle"] button', 'form[action$="/payment/test-mode-toggle"] button'],
            array_values(array_filter($highlights)),
        );
    }

    // The same shape for the documents tile, whose route is the other half of PaymentShortcutController - a parcours pointing at a toggle no tile posts to highlights nothing
    public function testTheDocumentsToggleStepsHighlightTheOtherShortcutButton(): void
    {
        $project = $this->createProvider()->getGuidedProjects()[1];
        $highlights = array_column($project['steps'], 'highlight');

        $this->assertSame(
            ['form[action$="/payment/email-attachments-toggle"] button', 'form[action$="/payment/email-attachments-toggle"] button'],
            array_values(array_filter($highlights)),
        );
    }

    // The rows this bundle fills on a screen it does not own: the parcours points at them by the very kind the provider declares, so a renamed kind fails here rather than silently highlighting nothing
    public function testTheIntegrityStepPointsAtTheKindTheProviderDeclares(): void
    {
        $project = $this->createProvider()->getGuidedProjects()[8];

        $this->assertContains(
            'tr[data-kind="' . BasketIntegrityHealthCheckProvider::KIND . '"]',
            array_column($project['steps'], 'highlight'),
        );
    }

    // A label or description with no translation reads as its own key in the panel, in whichever locale it is missing from
    public function testEveryLabelAndDescriptionIsTranslatedInEveryLocale(): void
    {
        foreach (['en', 'fr', 'es'] as $locale) {
            $translated = $this->translatedKeys($locale);

            foreach ($this->createProvider()->getGuidedProjects() as $project) {
                foreach ([$project, ...$project['steps']] as $item) {
                    $this->assertContains($item['label'], $translated, sprintf('"%s" is missing from the %s catalogue', $item['label'], $locale));
                    if (isset($item['description'])) {
                        $this->assertContains($item['description'], $translated, sprintf('"%s" is missing from the %s catalogue', $item['description'], $locale));
                    }
                }
            }
        }
    }

    private function translatedKeys(string $locale): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/payment.' . $locale . '.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
