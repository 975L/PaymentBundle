<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Form;

use c975L\PaymentBundle\Entity\Basket;
use c975L\PaymentBundle\Form\CoordinatesType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

// The checkout's own form, checked on the two things a mistake here costs: an address asked of an order that has none to deliver, and a consent that is not one
class CoordinatesTypeTest extends TestCase
{
    /** @return array<string, array{type: ?string, options: array<string, mixed>}> */
    private function build(int $contentFlags): array
    {
        $added = [];
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, ?string $formType = null, array $options = []) use (&$added, $builder) {
            $added[$name] = ['type' => $formType, 'options' => $options];

            return $builder;
        });

        $basket = new Basket();
        $basket->setContentFlags($contentFlags);

        new CoordinatesType()->buildForm($builder, [
            'data' => $basket,
            'config' => ['touUrl' => 'tou', 'tosUrl' => 'tos'],
        ]);

        return $added;
    }

    // An order of files alone is delivered by email: its postal address is only offered for a business invoice, which has to carry one, and never required of a private buyer
    public function testAnOrderOfFilesAloneOnlyOffersAnAddressForABusinessInvoice(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_DIGITAL);

        $this->assertArrayHasKey('email', $added);
        $this->assertArrayNotHasKey('name', $added);
        foreach (['address', 'zip', 'city', 'country', 'company', 'vatNumber'] as $field) {
            $this->assertFalse($added[$field]['options']['required'], $field);
        }
    }

    // A company alone is an invoice made out to nobody's address, and a VAT number starts with its country's letters
    public function testABusinessInvoiceNeedsTheAddressAndAValidVatNumber(): void
    {
        $basket = new Basket()->setCompany('ACME')->setVatNumber('12345');
        $paths = $this->violations($basket);

        $this->assertSame(['vatNumber', 'address', 'zip', 'city', 'country'], $paths);
    }

    // A private buyer is asked nothing more, and a complete business one passes
    public function testAPrivateOrACompleteBusinessInvoicePasses(): void
    {
        $this->assertSame([], $this->violations(new Basket()));
        $this->assertSame([], $this->violations(new Basket()->setCompany('ACME')->setVatNumber('fr 12.345.678.901')->setAddress('1 rue')->setZip('74000')->setCity('Annecy')->setCountry('FR')));
    }

    // The VAT number is stored as its tax office writes it, whatever spaces or dots were typed, and an empty company means no business at all
    public function testTheBusinessDetailsAreNormalized(): void
    {
        $basket = new Basket()->setCompany('  ')->setVatNumber('fr 12.345-678 901');

        $this->assertNull($basket->getCompany());
        $this->assertSame('FR12345678901', $basket->getVatNumber());
    }

    // Emptying the prefilled company is all a buyer ordering for themselves has to do: the VAT number goes with it, and the address too on a digital order, where it was only asked for the business
    public function testAnEmptiedCompanyTakesTheBusinessDetailsWithIt(): void
    {
        $type = new CoordinatesType();
        $digital = new Basket()->setVatNumber('FR12345678901')->setAddress('1 rue')->setZip('74000')->setCity('Annecy')->setCountry('FR');
        $digital->setContentFlags(Basket::CONTENT_FLAG_DIGITAL);
        $type->forgetBusiness($digital);

        $this->assertNull($digital->getVatNumber());
        $this->assertNull($digital->getAddress());
        $this->assertNull($digital->getCountry());

        $shipped = new Basket()->setVatNumber('FR12345678901')->setAddress('1 rue');
        $shipped->setContentFlags(Basket::CONTENT_FLAG_PHYSICAL);
        $type->forgetBusiness($shipped);

        $this->assertNull($shipped->getVatNumber());
        $this->assertSame('1 rue', $shipped->getAddress());

        $business = new Basket()->setCompany('ACME')->setVatNumber('FR12345678901');
        $type->forgetBusiness($business);

        $this->assertSame('FR12345678901', $business->getVatNumber());
    }

    /** @return list<string> */
    private function violations(Basket $basket): array
    {
        $paths = [];
        $builder = $this->createStub(ConstraintViolationBuilderInterface::class);
        $context = $this->createStub(ExecutionContextInterface::class);
        $context->method('buildViolation')->willReturn($builder);
        $builder->method('setTranslationDomain')->willReturnSelf();
        $builder->method('atPath')->willReturnCallback(function (string $path) use (&$paths, $builder) {
            $paths[] = $path;

            return $builder;
        });

        new CoordinatesType()->validateCompany($basket, $context);

        return $paths;
    }

    // Anything to ship needs somewhere to ship it to
    public function testAnOrderToShipIsAskedWhereToSendIt(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertArrayHasKey('address', $added);
        $this->assertArrayHasKey('city', $added);
        $this->assertTrue($added['name']['options']['required']);
    }

    // The delivery is priced on the country, and a zone naming "FR" recognises nothing in "france", "France" or "FRANCE": a list is what makes the two comparable, the ISO code being what it stores
    public function testTheCountryIsPickedFromAListAndNeverTyped(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertSame(CountryType::class, $added['country']['type']);
    }

    // The reminder of an unpaid order is the follow-up of that order and not prospection, so no box asks for it: what the customer is offered is the way out at the foot of each one. No GDPR box either - the checkout processes what the contract needs, and a consent that cannot be refused is none. Every box left here is contractual, and one they have to tick
    public function testEveryBoxLeftInTheFormIsRequired(): void
    {
        $added = $this->build(Basket::CONTENT_FLAG_PHYSICAL);

        $this->assertArrayNotHasKey('reminderConsent', $added);
        $this->assertArrayNotHasKey('gdpr', $added);

        foreach (['cgu', 'cgv'] as $box) {
            $this->assertSame(CheckboxType::class, $added[$box]['type']);
            $this->assertTrue($added[$box]['options']['required']);
            $this->assertFalse($added[$box]['options']['mapped']);
        }
    }
}
