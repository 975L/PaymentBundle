<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Entity\GiftCard;
use c975L\PaymentBundle\Service\GiftCardService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// A register and not a form: a card is money somebody paid for, so it is issued by the order that bought it (see GiftCardService::issue()) and never typed in here. What an admin may still do is read a balance, trace a card back to its order, and switch off one that was stolen
class GiftCardCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GiftCardService $giftCardService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GiftCard::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityPermission($this->configService->get('site-role-admin'))
            ->setDefaultSort(['creation' => 'DESC'])
            ->setEntityLabelInSingular(t('label.gift_card', [], 'payment'))
            ->setEntityLabelInPlural(t('label.gift_cards', [], 'payment'))
            ->overrideTemplate('crud/index', '@c975LPayment/management/gift_card_crud_index.html.twig')
        ;
    }

    // No "new": a card nobody bought is money out of nowhere, so a card is minted by "issue" and never typed in. Deleting one is allowed, and it is a real deletion: the balance goes with the card, so an admin who only wants to take a card out of circulation switches "active" off instead
    public function configureActions(Actions $actions): Actions
    {
        // "Issue" and not "new": the amount is written once and the card is minted from it, where a creation form would let a balance be typed - and edited afterwards
        $issue = Action::new('issue', t('label.gift_card_issue', [], 'payment'), 'fa fa-gift')
            ->createAsGlobalAction()
            ->linkToCrudAction('issue')
        ;

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $issue)
            // Icon-only row buttons, as everywhere else in the back office: worded ones widen a table that already carries nine columns
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
        ;
    }

    /**
     * Mints one card by hand - a gesture after an incident, a card sold face to face, the cards a shop already had before this screen existed.
     *
     * The same GiftCardService::issue() a purchase calls, so the code is drawn the same way and the balance is the amount and nothing else. What tells the two apart afterwards is the order column: a card issued here names none, which is exactly what an accountant wants to be able to isolate.
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function issue(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-admin'));

        $form = $this->createFormBuilder()
            ->add('amount', MoneyType::class, [
                'label' => t('label.initial_amount', [], 'payment'),
                'help' => t('help.gift_card_amount', [], 'payment'),
                // Typed in the currency the customer reads, stored in cents like every other amount here
                'divisor' => 100,
                'currency' => strtoupper(trim((string) $this->configService->get('shop-currency'))),
            ])
            ->add('currency', TextType::class, [
                'label' => t('label.currency', [], 'payment'),
                'data' => strtoupper(trim((string) $this->configService->get('shop-currency'))),
            ])
            ->add('validUntil', DateType::class, [
                'label' => t('label.valid_until', [], 'payment'),
                'required' => false,
                'widget' => 'single_text',
                'help' => t('help.gift_card_valid_until', [], 'payment'),
            ])
            // Part of the form rather than written in the template: EasyAdmin's own form theme is what gives it the dashboard's button
            ->add('issue', SubmitType::class, [
                'label' => t('label.gift_card_issue', [], 'payment'),
            ])
            ->getForm()
        ;
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $giftCard = $this->giftCardService->issue((int) round((float) $data['amount']), (string) $data['currency'], null, $data['validUntil']);

            // Issued outside a delivery, so nothing else is going to flush for it
            $this->entityManager->flush();

            // The code is shown once, here: it is what the admin has to hand over, and no screen prints it again in a form they could copy it from
            $this->addFlash('success', $this->translator->trans('flash.gift_card_issued', ['%code%' => $giftCard->getCode()], 'payment'));

            return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        return $this->render('@c975LPayment/management/gift_card_issue.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('code')
                ->setLabel(t('label.gift_card_code', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            MoneyField::new('initialAmount')
                ->setLabel(t('label.initial_amount', [], 'payment'))
                ->setCurrency($this->currency())
                ->setStoredAsCents()
                ->setFormTypeOption('disabled', 'disabled'),
            MoneyField::new('balance')
                ->setLabel(t('label.balance', [], 'payment'))
                ->setCurrency($this->currency())
                ->setStoredAsCents()
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('currency')
                ->setLabel(t('label.currency', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            TextField::new('issuedByBasket')
                ->setLabel(t('label.order_number', [], 'payment'))
                ->setFormTypeOption('disabled', 'disabled'),
            // What the holder was given, which support is asked for the day a customer loses the message it was sent in. Read-only like everything else here: it is the card's address, and rewriting it would take the card away from whoever holds it
            UrlField::new('shareToken')
                ->setLabel(t('label.gift_card_share_url', [], 'payment'))
                ->formatValue(fn (?string $shareToken): ?string => null === $shareToken ? null : $this->urlGenerator->generate('gift_card_display', ['shareToken' => $shareToken], UrlGeneratorInterface::ABSOLUTE_URL))
                ->hideOnIndex()
                ->hideOnForm(),
            DateTimeField::new('validUntil')
                ->setLabel(t('label.valid_until', [], 'payment'))
                ->setRequired(false),
            // The one field an admin writes here, and the whole reason this screen takes an edit at all
            BooleanField::new('active')
                ->setLabel(t('label.active', [], 'payment')),
            BooleanField::new('testMode')
                ->setLabel(t('label.test', [], 'payment'))
                ->setHelp(t('help.code_test_mode', [], 'payment'))
                ->renderAsSwitch(false)
                ->hideOnForm(),
            DateTimeField::new('creation')
                ->setLabel(t('label.creation', [], 'payment'))
                ->hideOnForm(),
        ];
    }

    // The shop's own currency, which is what every amount of this screen is read in
    private function currency(): string
    {
        $currency = strtoupper(trim((string) $this->configService->get('shop-currency')));

        return '' === $currency ? 'EUR' : $currency;
    }
}
