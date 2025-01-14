<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\EventListener\DataContainer\LegacyTemplatesListener;
use Contao\CoreBundle\Tests\TestCase;
use Contao\Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class LegacyTemplatesListenerTest extends TestCase
{
    public function testAddsInfoMessage(): void
    {
        $message = $this->mockAdapter(['addInfo']);
        $message
            ->expects($this->once())
            ->method('addInfo')
            ->with('<message>')
        ;

        $framework = $this->mockContaoFramework([Message::class => $message]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->with('tl_templates.twig_studio_hint', ['<a href="contao_template_studio">Template Studio</a>'], 'contao_templates')
            ->willReturn('<message>')
        ;

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->willReturnArgument(0)
        ;

        $listener = new LegacyTemplatesListener($framework, $translator, $urlGenerator);
        $listener->addInfoMessage();
    }
}
