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

use Contao\BackendUser;
use Contao\CoreBundle\EventListener\DataContainer\PreviewLinkListener;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Tests\TestCase;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PreviewLinkListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);

        parent::tearDown();
    }

    public function testRemovesTheBackendModuleWithoutPreviewScript(): void
    {
        $GLOBALS['BE_MOD']['system'] = ['preview_link' => ['foo']];

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework(),
            $this->createMock(Connection::class),
            $this->createMock(Security::class),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            new MockClock(),
            '',
        );

        $listener->unloadModuleWithoutPreviewScript();

        $this->assertSame([], $GLOBALS['BE_MOD']['system']);

        unset($GLOBALS['BE_MOD']);
    }

    public function testUnsetsTheDcaTableWithoutPreviewScript(): void
    {
        $GLOBALS['TL_DCA'] = ['tl_preview_link' => ['config' => ['foo']]];

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework(),
            $this->createMock(Connection::class),
            $this->createMock(Security::class),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            new MockClock(),
            '',
        );

        $listener->unloadTableWithoutPreviewScript('tl_preview_link');

        $this->assertSame([], $GLOBALS['TL_DCA']);
    }

    public function testDoesNotUnloadOtherTables(): void
    {
        $GLOBALS['TL_DCA'] = ['tl_preview_link' => 'foo', 'tl_member' => 'bar'];

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework(),
            $this->createMock(Connection::class),
            $this->createMock(Security::class),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            new MockClock(),
            '',
        );

        $listener->unloadTableWithoutPreviewScript('tl_member');

        $this->assertSame(['tl_preview_link' => 'foo', 'tl_member' => 'bar'], $GLOBALS['TL_DCA']);
    }

    #[DataProvider('defaultDcaValueProvider')]
    public function testSetsTheDefaultValueForDcaFields(string $url, bool $showUnpublished, int $userId): void
    {
        /** @phpstan-var array $GLOBALS (signals PHPStan that the array shape may change) */
        $GLOBALS['TL_DCA']['tl_preview_link'] = [
            'config' => ['notCreatable' => true],
            'fields' => [
                'url' => ['default' => ''],
                'showUnpublished' => ['default' => false],
                'createdAt' => ['default' => 0],
                'expiresAt' => ['default' => 0],
                'createdBy' => ['default' => 0],
            ],
        ];

        $input = $this->mockInputAdapter(['url' => $url, 'showUnpublished' => $showUnpublished]);
        $clock = new MockClock();

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework([Input::class => $input, Message::class => $this->mockAdapter(['addInfo'])]),
            $this->createMock(Connection::class),
            $this->mockSecurity($userId),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            $clock,
            '/preview.php',
        );

        $dc = $this->mockClassWithProperties(DataContainer::class);
        $listener->createFromUrl($dc);

        $this->assertTrue($GLOBALS['TL_DCA']['tl_preview_link']['config']['notCreatable']);
        $this->assertSame($url, $GLOBALS['TL_DCA']['tl_preview_link']['fields']['url']['default']);
        $this->assertSame($showUnpublished, $GLOBALS['TL_DCA']['tl_preview_link']['fields']['showUnpublished']['default']);
        $this->assertSame($clock->now()->getTimestamp(), $GLOBALS['TL_DCA']['tl_preview_link']['fields']['createdAt']['default']);
        $this->assertSame(strtotime($clock->now()->getTimestamp().' +1 day'), $GLOBALS['TL_DCA']['tl_preview_link']['fields']['expiresAt']['default']);
        $this->assertSame($userId, $GLOBALS['TL_DCA']['tl_preview_link']['fields']['createdBy']['default']);
    }

    public static function defaultDcaValueProvider(): iterable
    {
        yield [
            '/preview.php/foo/bar',
            true,
            1,
        ];

        yield [
            '/preview.php/foo/baz',
            false,
            2,
        ];
    }

    public function testEnablesCreateOperationWithPreviewUrl(): void
    {
        /** @phpstan-var array $GLOBALS (signals PHPStan that the array shape may change) */
        $GLOBALS['TL_DCA']['tl_preview_link'] = [
            'config' => ['notCreatable' => true],
        ];

        $input = $this->mockInputAdapter(['act' => 'create', 'url' => '/preview.php/foo/bar']);

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework([Input::class => $input, Message::class => $this->mockAdapter(['addInfo'])]),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            new MockClock(),
            '/preview.php',
        );

        $dc = $this->mockClassWithProperties(DataContainer::class);

        $listener->createFromUrl($dc);

        $this->assertFalse($GLOBALS['TL_DCA']['tl_preview_link']['config']['notCreatable']);
    }

    public function testDoesNotEnableCreateOperationIfPreviewScriptIsNotInUrl(): void
    {
        /** @phpstan-var array $GLOBALS (signals PHPStan that the array shape may change) */
        $GLOBALS['TL_DCA']['tl_preview_link'] = [
            'config' => ['notCreatable' => true],
        ];

        $input = $this->mockInputAdapter(['act' => 'create']);

        $listener = new PreviewLinkListener(
            $this->mockContaoFramework([Input::class => $input, Message::class => $this->mockAdapter(['addInfo'])]),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->createMock(RequestStack::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(UriSigner::class),
            new MockClock(),
            '/preview.php',
        );

        $dc = $this->mockClassWithProperties(DataContainer::class);

        $listener->createFromUrl($dc);

        $this->assertTrue($GLOBALS['TL_DCA']['tl_preview_link']['config']['notCreatable']);
    }

    private function mockSecurity(int $userId = 42): Security&MockObject
    {
        $user = $this->mockClassWithProperties(BackendUser::class, ['id' => $userId]);

        $security = $this->createMock(Security::class);
        $security
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user)
        ;

        $security
            ->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(true)
        ;

        return $security;
    }

    /**
     * @return Adapter<Input>&MockObject
     */
    private function mockInputAdapter(array $inputData): Adapter&MockObject
    {
        $inputAdapter = $this->mockAdapter(['get']);
        $inputAdapter
            ->method('get')
            ->willReturnCallback(static fn ($key) => $inputData[$key] ?? null)
        ;

        return $inputAdapter;
    }
}
