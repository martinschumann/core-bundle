<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Twig\Loader;

use Contao\CoreBundle\Exception\InvalidThemePathException;
use Contao\CoreBundle\Tests\TestCase;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

class ThemeTest extends TestCase
{
    use ExpectDeprecationTrait;

    /**
     * @dataProvider providePaths
     */
    public function testGenerateSlug(string $path, string $expectedSlug): void
    {
        $themeNamespace = new ThemeNamespace();

        $this->assertSame($expectedSlug, $themeNamespace->generateSlug($path), 'create slug');
        $this->assertSame($path, $themeNamespace->getPath($expectedSlug), 'get path from slug');
    }

    public static function providePaths(): iterable
    {
        yield 'simple' => ['foo', 'foo'];

        yield 'with dashes' => ['foo-bar', 'foo-bar'];

        yield 'nested' => ['foo/bar/baz', 'foo_bar_baz'];
    }

    /**
     * @group legacy
     *
     * @dataProvider provideRelativePaths
     */
    public function testGenerateRelativeSlug(string $path, string $expectedSlug): void
    {
        $themeNamespace = new ThemeNamespace();

        $this->expectDeprecation('%sUsing paths outside of the template directory are deprecated and will no longer work in Contao 6.%s');

        $this->assertSame($expectedSlug, $themeNamespace->generateSlug($path));
    }

    public static function provideRelativePaths(): iterable
    {
        yield 'relative (up one)' => ['../foo', '_foo'];

        yield 'relative (up multiple)' => ['../../../foo', '___foo'];

        yield 'relative and nested' => ['../foo/bar', '_foo_bar'];
    }

    public function testGenerateSlugThrowsIfPathIsAbsolute(): void
    {
        $themeNamespace = new ThemeNamespace();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path "/foo/bar" must be relative.');

        $themeNamespace->generateSlug('/foo/bar');
    }

    public function testGenerateSlugThrowsIfPathContainsInvalidCharacters(): void
    {
        $themeNamespace = new ThemeNamespace();

        $this->expectException(InvalidThemePathException::class);

        try {
            $themeNamespace->generateSlug('foo.bar/bar_baz');
        } catch (InvalidThemePathException $e) {
            $this->assertSame(['.', '_'], $e->getInvalidCharacters());

            throw $e;
        }
    }

    public function testGetFromSlug(): void
    {
        $this->assertSame('@Contao_Theme_foo_bar', (new ThemeNamespace())->getFromSlug('foo_bar'));
    }

    /**
     * @dataProvider provideNamespaces
     */
    public function testMatchThemeNamespace(string $name, string|null $expectedSlug): void
    {
        $this->assertSame($expectedSlug, (new ThemeNamespace())->match($name));
    }

    public static function provideNamespaces(): iterable
    {
        yield 'theme namespace' => [
            '@Contao_Theme_foo_bar-baz/a.html.twig',
            'foo_bar-baz',
        ];

        yield 'theme namespace with sub directory' => [
            '@Contao_Theme_foo_bar-baz/b/a.html.twig',
            'foo_bar-baz',
        ];

        yield 'not a theme namespace' => [
            '@Contao_Foo/bar.html.twig',
            null,
        ];

        yield 'not a logical name' => [
            '',
            null,
        ];
    }
}
