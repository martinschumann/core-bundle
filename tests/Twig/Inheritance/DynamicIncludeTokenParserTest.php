<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Twig\Inheritance;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Tests\TestCase;
use Contao\CoreBundle\Twig\Extension\ContaoExtension;
use Contao\CoreBundle\Twig\Global\ContaoVariable;
use Contao\CoreBundle\Twig\Inheritance\DynamicIncludeTokenParser;
use Contao\CoreBundle\Twig\Inspector\InspectorNodeVisitor;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Twig\Environment;
use Twig\Lexer;
use Twig\Loader\ArrayLoader;
use Twig\Loader\LoaderInterface;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Parser;
use Twig\Source;

class DynamicIncludeTokenParserTest extends TestCase
{
    public function testGetTag(): void
    {
        $tokenParser = new DynamicIncludeTokenParser($this->createMock(ContaoFilesystemLoader::class));

        $this->assertSame('include', $tokenParser->getTag());
    }

    #[DataProvider('provideSources')]
    public function testHandlesContaoIncludes(string $code, string ...$expectedStrings): void
    {
        $filesystemLoader = $this->createMock(ContaoFilesystemLoader::class);
        $filesystemLoader
            ->method('getAllFirstByThemeSlug')
            ->willReturnCallback(
                static function (string $name) {
                    $hierarchy = [
                        'foo.html.twig' => '<foo-template>',
                        'bar.html.twig' => '<bar-template>',
                    ];

                    if (null !== ($resolved = $hierarchy[$name] ?? null)) {
                        return ['' => $resolved];
                    }

                    throw new \LogicException('Template not found in hierarchy.');
                },
            )
        ;

        $environment = new Environment($this->createMock(LoaderInterface::class));
        $environment->addTokenParser(new DynamicIncludeTokenParser($filesystemLoader));

        $source = new Source($code, 'template.html.twig');
        $tokenStream = (new Lexer($environment))->tokenize($source);
        $serializedTree = (string) (new Parser($environment))->parse($tokenStream);

        foreach ($expectedStrings as $expectedString) {
            $this->assertStringContainsString($expectedString, $serializedTree);
        }
    }

    public static function provideSources(): iterable
    {
        yield 'regular include' => [
            "{% include '@Foo/bar.html.twig' %}",
            '@Foo/bar.html.twig',
        ];

        yield 'Contao include' => [
            "{% include '@Contao/foo.html.twig' %}",
            '<foo-template>',
        ];

        yield 'conditional includes' => [
            "{% include x == 1 ? '@Contao/foo.html.twig' : '@Foo/bar.html.twig' %}",
            '<foo-template>', '@Foo/bar.html.twig',
        ];

        yield 'conditional Contao includes' => [
            "{% include x == 1 ? '@Contao/foo.html.twig' : '@Contao/bar.html.twig' %}",
            '<foo-template>', '<bar-template>',
        ];

        yield 'optional includes' => [
            "{% include ['a.html.twig', 'b.html.twig'] %}",
            'a.html.twig', 'b.html.twig',
        ];

        yield 'optional Contao includes' => [
            // Files missing in the hierarchy should be ignored in this case
            "{% include ['@Contao/missing.html.twig', '@Contao/bar.html.twig'] %}",
            '@Contao/missing.html.twig', '<bar-template>',
        ];
    }

    public function testHandlesContaoIncludesWithThemeDifferentContexts(): void
    {
        $filesystemLoader = $this->createMock(ContaoFilesystemLoader::class);
        $filesystemLoader
            ->method('getAllFirstByThemeSlug')
            ->with('foo.html.twig')
            ->willReturn(['theme' => '@Contao_Theme_theme/foo.html.twig', '' => '@Contao_ContaoCoreBundle/foo.html.twig'])
        ;

        $filesystemLoader
            ->method('getCurrentThemeSlug')
            ->willReturn('theme')
        ;

        $environment = new Environment(new ArrayLoader([
            'template.twig' => '{% include "@Contao/foo.html.twig" %}',
            '@Contao_ContaoCoreBundle/foo.html.twig' => '<foo-core>',
            '@Contao_Theme_theme/foo.html.twig' => '<foo-theme>',
        ]));

        $environment->addTokenParser(new DynamicIncludeTokenParser($filesystemLoader));
        $environment->addExtension(new ContaoExtension(
            $environment,
            $filesystemLoader,
            $this->createMock(ContaoCsrfTokenManager::class),
            $this->createMock(ContaoVariable::class),
            new InspectorNodeVisitor(new NullAdapter(), $environment),
        ));

        $this->assertSame('<foo-theme>', $environment->render('template.twig'));
    }

    public function testEnhancesErrorMessageWhenIncludingAnInvalidTemplate(): void
    {
        $filesystemLoader = $this->createMock(ContaoFilesystemLoader::class);
        $filesystemLoader
            ->method('getAllFirstByThemeSlug')
            ->with('foo')
            ->willThrowException(new \LogicException('<original message>'))
        ;

        $environment = new Environment($this->createMock(LoaderInterface::class));
        $environment->addTokenParser(new DynamicIncludeTokenParser($filesystemLoader));

        // Use a conditional expression here, so that we can test rethrowing exceptions
        // in case the parent node is not an ArrayExpression
        $source = new Source("{% include true ? '@Contao/foo' : '' %}", 'template.html.twig');
        $tokenStream = (new Lexer($environment))->tokenize($source);
        $parser = new Parser($environment);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('<original message> Did you try to include a non-existent template or a template from a theme directory?');

        $parser->parse($tokenStream);
    }

    #[DataProvider('provideTokens')]
    public function testParsesArguments(string $source, AbstractExpression|null $variables, bool $only, bool $ignoreMissing): void
    {
        $environment = new Environment($this->createMock(LoaderInterface::class));
        $environment->addTokenParser(new DynamicIncludeTokenParser($this->createMock(ContaoFilesystemLoader::class)));

        $tokenStream = (new Lexer($environment))->tokenize(new Source($source, 'foo.html.twig'));
        $parser = new Parser($environment);
        $includeNode = $parser->parse($tokenStream)->getNode('body')->getNode('0');

        if ($variables) {
            $this->assertSame((string) $variables, (string) $includeNode->getNode('variables'));
        } else {
            $this->assertFalse($includeNode->hasNode('variables'));
        }

        $this->assertSame($only, $includeNode->getAttribute('only'));
        $this->assertSame($ignoreMissing, $includeNode->getAttribute('ignore_missing'));
    }

    public static function provideTokens(): iterable
    {
        yield 'with data' => [
            "{% include 'bar.html.twig' with {a: 1} %}",
            new ArrayExpression([new ConstantExpression('a', 0), new ConstantExpression(1, 0)], 0),
            false,
            false,
        ];

        yield 'with data only' => [
            "{% include 'bar.html.twig' with {} only %}",
            new ArrayExpression([], 0),
            true,
            false,
        ];

        yield 'ignore missing' => [
            "{% include 'bar.html.twig' ignore missing %}",
            null,
            false,
            true,
        ];
    }
}
