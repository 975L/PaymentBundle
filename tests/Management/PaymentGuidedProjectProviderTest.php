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
        $generator->method('set')->willReturnSelf();
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
            ['payment-gateway-setup', 'payment-shop-identity', 'payment-order-emails', 'payment-test-mode', 'payment-transaction-review', 'payment-payment-link', 'payment-gift-card-issue', 'payment-discount-code', 'payment-shipping-grid', 'payment-shipping', 'payment-archived-invoice', 'payment-basket-integrity', 'payment-export'],
            array_column($projects, 'slug'),
        );
        // 7005, 7007, 7008, 7055 and 7065 slip between two tens rather than being appended: the keys, the shop's identity and its email sender come before the test mode rehearsing against them, the delivery grid stands just before the parcel round it prices, and the archive follows the round that ends an order's life
        $this->assertSame([7005, 7007, 7008, 7010, 7020, 7030, 7040, 7050, 7055, 7060, 7065, 7070, 7080], array_column($projects, 'order'));
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

    // The test-mode toggle lives on the dashboard and the order checks on ConfigBundle's health check screen, neither of them on a CRUD one
    public function testTheProjectsOpeningOnAPlainRouteNameIt(): void
    {
        $controllers = [];
        $routes = [];
        $this->createProvider($controllers, $routes)->getGuidedProjects();

        $this->assertSame(['management', 'management_health_check_index'], $routes);
    }

    // Each parcours opens on the listing the task starts from, the four written from the baskets one included, and the keys and the shop's settings on ConfigBundle's own screen
    public function testEachCrudProjectOpensOnItsOwnListing(): void
    {
        $controllers = [];
        $routes = [];
        $this->createProvider($controllers, $routes)->getGuidedProjects();

        $this->assertSame(['ConfigCrudController', 'ConfigCrudController', 'ConfigCrudController', 'PaymentCrudController', 'BasketCrudController', 'GiftCardCrudController', 'DiscountCrudController', 'ShippingZoneCrudController', 'BasketCrudController', 'BasketCrudController', 'BasketCrudController'], array_map(
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
        $project = $this->project('payment-test-mode');
        $highlights = array_column($project['steps'], 'highlight');

        $this->assertSame(
            ['form[action$="/payment/test-mode-toggle"] button', 'form[action$="/payment/test-mode-toggle"] button'],
            array_values(array_filter($highlights)),
        );
    }

    // The rows this bundle fills on a screen it does not own: the parcours points at them, and at what each of them carries, by the very kind the provider declares, so a renamed kind fails here rather than silently highlighting nothing - or the first row of another check
    public function testTheIntegrityStepPointsAtTheKindTheProviderDeclares(): void
    {
        $row = 'tr[data-kind="' . BasketIntegrityHealthCheckProvider::KIND . '"]:not([hidden])';

        $this->assertSame(
            ['form[action$="/health-check/run"] button', '[data-health-check-table-target="status"]', $row, $row . ' .health-check-advice-items', $row . ' [data-health-check-acknowledge]'],
            array_values(array_filter(array_column($this->project('payment-basket-integrity')['steps'], 'highlight'))),
        );
    }

    // The table opens on what is left to handle, hiding every row a healthy shop has: the status select comes before the rows, or they highlight nothing
    public function testTheStatusStepComesBeforeTheIntegrityRows(): void
    {
        $steps = array_column($this->project('payment-basket-integrity')['steps'], 'label');

        $this->assertLessThan(
            array_search('label.guided_step_payment_basket_integrity_rows', $steps, true),
            array_search('label.guided_step_payment_basket_integrity_status', $steps, true),
        );
    }

    // ConfigBundle draws the sensitive toggle itself, the attribute it carries being what the gateway parcours points at
    public function testTheGatewayDefaultStepHighlightsTheSensitiveToggle(): void
    {
        $this->assertContains('[data-config-sensitive-toggle]', array_column($this->project('payment-gateway-setup')['steps'], 'highlight'));
    }

    // TomSelect hides a multiple select behind a widget of its own, so the step points at that widget
    public function testTheShippingCountriesStepPointsAtTheTomSelectWidget(): void
    {
        $this->assertContains('#ShippingZone_countries + .ts-wrapper', array_column($this->project('payment-shipping-grid')['steps'], 'highlight'));
    }

    // The kind keeps its own id only as a native select, TomSelect hiding it otherwise - the step highlighting a 1px element
    public function testTheDiscountKindStepPointsAtANativeSelect(): void
    {
        $this->assertContains('#Discount_kind', array_column($this->project('payment-discount-code')['steps'], 'highlight'));
        $this->assertMatchesRegularExpression(
            "/ChoiceField::new\\('kind'\\)(?:(?!ChoiceField::new|Field::new).)*->renderAsNativeWidget\\(\\)/s",
            (string) file_get_contents(__DIR__ . '/../../src/Controller/Management/DiscountCrudController.php'),
        );
    }

    // A discount is only live once saved: the parcours walks to the button rather than stopping on the last field
    public function testTheDiscountProjectEndsOnTheSaveButton(): void
    {
        $highlights = array_values(array_filter(array_column($this->project('payment-discount-code')['steps'], 'highlight')));

        $this->assertSame(['#Discount_active', '.action-saveAndReturn'], array_slice($highlights, -2));
    }

    // The card's address shows on its detail page only, which the listing has to offer for the step to point at anything
    public function testTheGiftCardLinkStepPointsAtTheDetailAction(): void
    {
        $this->assertContains('.action-detail', array_column($this->project('payment-gift-card-issue')['steps'], 'highlight'));
        $this->assertStringContainsString(
            '->add(Crud::PAGE_INDEX, Action::DETAIL)',
            (string) file_get_contents(__DIR__ . '/../../src/Controller/Management/GiftCardCrudController.php'),
        );
    }

    // EasyAdmin puts no id on a collection's row, so the grid step points at an attribute ShippingZoneCrudController poses itself - a rename on either side highlighting nothing at all
    public function testTheShippingGridStepPointsAtTheAttributeTheCrudDeclares(): void
    {
        $highlights = [];

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $highlights = [...$highlights, ...array_column($project['steps'], 'highlight')];
        }

        $this->assertContains('[data-shipping-rates]', $highlights);
        $this->assertStringContainsString(
            "'row_attr', ['data-shipping-rates' => 'true']",
            (string) file_get_contents(__DIR__ . '/../../src/Controller/Management/ShippingZoneCrudController.php'),
        );
    }

    // EasyAdmin renders a group as a dropdown carrying its name as a data attribute, which the class check above does not see - a renamed group highlighting nothing at all
    public function testTheExportStepPointsAtTheGroupTheCrudDeclares(): void
    {
        $this->assertContains('[data-action-group-name="export"]', array_column($this->project('payment-export')['steps'], 'highlight'));
        $this->assertStringContainsString(
            "ActionGroup::new('export'",
            (string) file_get_contents(__DIR__ . '/../../src/Controller/Management/BasketCrudController.php'),
        );
    }

    // The keys are sensitive entries, listed by ConfigBundle's screen only once asked to: a parcours landing on the group without the toggle shows the settings around them and none of the keys - the shop's settings and the email sender, none of them sensitive, being opened without it
    public function testTheGatewayProjectOpensOnTheSensitiveEntriesOfThePaymentGroup(): void
    {
        $parameters = [];
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('set')->willReturnCallback(function (string $name, mixed $value) use ($generator, &$parameters) {
            $parameters[] = [$name, $value];

            return $generator;
        });
        $generator->method('generateUrl')->willReturn('/management/config');
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_ADMIN');

        new PaymentGuidedProjectProvider($generator, $this->createUrlGenerator(), $configService)->getGuidedProjects();

        $this->assertSame([['group', 'payment'], ['showSensitive', 1], ['group', 'shop'], ['group', 'payment']], $parameters);
    }

    // Read by slug rather than by position, so a parcours slipped between two others leaves the tests of its neighbours alone
    private function project(string $slug): array
    {
        $projects = array_column($this->createProvider()->getGuidedProjects(), null, 'slug');

        return $projects[$slug];
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
