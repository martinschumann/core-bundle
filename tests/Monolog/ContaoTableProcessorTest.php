<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Monolog;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Monolog\ContaoTableProcessor;
use Contao\CoreBundle\Tests\TestCase;
use Contao\PageModel;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ContaoTableProcessorTest extends TestCase
{
    public function testCanBeInvoked(): void
    {
        $processor = $this->getContaoTableProcessor();
        $record = $this->getRecord(['contao' => false]);

        $this->assertSame($record, $processor($record));
    }

    /**
     * @phpstan-param Level::Alert|Level::Critical|Level::Debug|Level::Emergency|Level::Error|Level::Info|Level::Notice|Level::Warning $logLevel
     */
    #[DataProvider('actionLevelProvider')]
    public function testReturnsDifferentActionsForDifferentErrorLevels(Level $logLevel, string $expectedAction): void
    {
        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__)], $logLevel);

        $processor = $this->getContaoTableProcessor();
        $record = $processor($record);

        /** @var ContaoContext $context */
        $context = $record['extra']['contao'];

        $this->assertSame($expectedAction, $context->getAction());
    }

    /**
     * @phpstan-param Level::Alert|Level::Critical|Level::Debug|Level::Emergency|Level::Error|Level::Info|Level::Notice|Level::Warning $logLevel
     */
    #[DataProvider('actionLevelProvider')]
    public function testDoesNotChangeAnExistingAction(Level $logLevel): void
    {
        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, ContaoContext::CRON)], $logLevel);

        $processor = $this->getContaoTableProcessor();
        $record = $processor($record);

        /** @var ContaoContext $context */
        $context = $record['extra']['contao'];

        $this->assertSame(ContaoContext::CRON, $context->getAction());
    }

    public static function actionLevelProvider(): iterable
    {
        yield [Level::Debug, ContaoContext::GENERAL];
        yield [Level::Info, ContaoContext::GENERAL];
        yield [Level::Notice, ContaoContext::GENERAL];
        yield [Level::Warning, ContaoContext::GENERAL];
        yield [Level::Error, ContaoContext::ERROR];
        yield [Level::Critical, ContaoContext::ERROR];
        yield [Level::Alert, ContaoContext::ERROR];
        yield [Level::Emergency, ContaoContext::ERROR];
    }

    public function testAddsTheUserAgent(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => 'Contao test']);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $processor = $this->getContaoTableProcessor($requestStack);

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, null, null, null, 'foobar')]);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame('foobar', $context->getBrowser());

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__)]);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame('Contao test', $context->getBrowser());

        $requestStack->pop();

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__)]);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame('N/A', $context->getBrowser());
    }

    public function testAddsTheUsername(): void
    {
        $token = $this->createMock(UsernamePasswordToken::class);
        $token
            ->method('getUserIdentifier')
            ->willReturn('k.jones')
        ;

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);

        $processor = $this->getContaoTableProcessor(null, $tokenStorage);

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, null, 'foobar')]);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame('foobar', $context->getUsername());

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__)]);
        $record = $processor($record);

        $context = $record['extra']['contao'];

        $this->assertSame('k.jones', $context->getUsername());

        $tokenStorage->setToken(null);

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__)]);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame('N/A', $context->getUsername());
    }

    #[DataProvider('sourceProvider')]
    public function testAddsTheSource(string|null $scope, string|null $contextSource, string $expectedSource): void
    {
        $requestStack = new RequestStack();

        if (null !== $scope) {
            $request = new Request();
            $request->attributes->set('_scope', $scope);

            $requestStack->push($request);
        }

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, null, null, null, null, $contextSource)]);

        $processor = $this->getContaoTableProcessor($requestStack);
        $result = $processor($record);

        $context = $result->extra['contao'];

        $this->assertSame($expectedSource, $context->getSource());
    }

    public static function sourceProvider(): iterable
    {
        yield [null, 'FE', 'FE'];
        yield [null, 'BE', 'BE'];
        yield [null, null, 'FE'];
        yield [ContaoCoreBundle::SCOPE_FRONTEND, 'FE', 'FE'];
        yield [ContaoCoreBundle::SCOPE_FRONTEND, 'BE', 'BE'];
        yield [ContaoCoreBundle::SCOPE_FRONTEND, null, 'FE'];
        yield [ContaoCoreBundle::SCOPE_BACKEND, 'FE', 'FE'];
        yield [ContaoCoreBundle::SCOPE_BACKEND, 'BE', 'BE'];
        yield [ContaoCoreBundle::SCOPE_BACKEND, null, 'BE'];
    }

    #[DataProvider('requestProvider')]
    public function testAddsTheRequestUri(Request|null $request = null, string|null $uri = null): void
    {
        $requestStack = new RequestStack();

        if ($request) {
            $requestStack->push($request);
        }

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, null, null, null, 'foobar')]);

        $processor = $this->getContaoTableProcessor($requestStack);
        $record = $processor($record);

        $context = $record->extra['contao'];
        $this->assertSame($uri, $context->getUri());
    }

    public static function requestProvider(): iterable
    {
        yield 'regular URL' => [
            Request::create('https://www.contao.org/foo?bar=baz'),
            'https://www.contao.org/foo?bar=baz',
        ];

        yield 'encoded URL' => [
            Request::create('https://www.contao.org/foo?bar=baz&foo=b%20r'),
            'https://www.contao.org/foo?bar=baz&foo=b%20r',
        ];

        yield 'no request' => [null, null];
    }

    #[DataProvider('requestWithPageIdProvider')]
    public function testAddsThePageId(\Closure|Request|null $request = null, int|null $pageId = null): void
    {
        $request = $request instanceof \Closure ? \Closure::bind($request, $this)() : $request;

        $requestStack = new RequestStack();

        if ($request) {
            $requestStack->push($request);
        }

        $record = $this->getRecord(['contao' => new ContaoContext(__METHOD__, null, null, null, 'foobar')]);

        $processor = $this->getContaoTableProcessor($requestStack);
        $record = $processor($record);

        $context = $record->extra['contao'];

        $this->assertSame($pageId, $context->getPageId());
    }

    public static function requestWithPageIdProvider(): iterable
    {
        yield 'request with page model ID' => [new Request([], [], ['pageModel' => '42']), 42];
        yield 'request with page model' => [fn () => new Request([], [], ['pageModel' => $this->mockClassWithProperties(PageModel::class, ['id' => 13])]), 13];
        yield 'request without page model' => [new Request(), null];
        yield 'no request' => [null, null];
    }

    private function getContaoTableProcessor(RequestStack|null $requestStack = null, TokenStorageInterface|null $tokenStorage = null): ContaoTableProcessor
    {
        $requestStack ??= $this->createMock(RequestStack::class);
        $tokenStorage ??= $this->createMock(TokenStorageInterface::class);

        return new ContaoTableProcessor($requestStack, $tokenStorage, $this->mockScopeMatcher());
    }

    /**
     * The processor moves the Contao context into the "extra" section, so pass it as
     * fifth argument to the LogRecord class.
     */
    private function getRecord(array $context, Level $level = Level::Debug): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), '', $level, '', $context, []);
    }
}
