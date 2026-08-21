<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests\Form;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PaymentBundle\Form\CoordinatesType;
use c975L\PaymentBundle\Form\PaymentFormFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Translation\TranslatableMessage;

// The one form of the checkout, built here so the terms urls are read from the config rather than spelled out in the type
class PaymentFormFactoryTest extends TestCase
{
    // The coordinates form carries the two terms links as translatable messages of the site catalog, their urls coming from the config
    public function testTheCoordinatesFormCarriesTheConfiguredTermsUrls(): void
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['url-terms-of-use', '/terms-of-use'],
            ['url-terms-of-sales', '/terms-of-sales'],
        ]);

        $object = new \stdClass();
        $form = $this->createStub(Form::class);

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects($this->once())
            ->method('create')
            ->with(
                CoordinatesType::class,
                $object,
                $this->callback(function (array $options) {
                    $config = $options['config'];

                    $this->assertInstanceOf(TranslatableMessage::class, $config['touUrl']);
                    $this->assertSame('label.accept_tou', $config['touUrl']->getMessage());
                    $this->assertSame(['%touUrl%' => '/terms-of-use'], $config['touUrl']->getParameters());
                    $this->assertSame('site', $config['touUrl']->getDomain());

                    $this->assertSame('label.accept_tos', $config['tosUrl']->getMessage());
                    $this->assertSame(['%tosUrl%' => '/terms-of-sales'], $config['tosUrl']->getParameters());

                    return true;
                }),
            )
            ->willReturn($form);

        $this->assertSame($form, new PaymentFormFactory($formFactory, $configService)->create('coordinates', $object));
    }

    // Asking for a form this factory does not build is a wiring mistake, and must say so rather than reach the form factory
    public function testAnUnknownFormThrowsBeforeReachingTheFormFactory(): void
    {
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->expects($this->never())->method('create');

        $factory = new PaymentFormFactory($formFactory, $this->createStub(ConfigServiceInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown form "payment"');

        $factory->create('payment', new \stdClass());
    }
}
