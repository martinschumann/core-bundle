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

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\Exception\InvalidThemePathException;
use Contao\CoreBundle\Tests\TestCase;
use Contao\CoreBundle\Twig\Loader\TemplateLocator;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as LegacyDriverException;
use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Filesystem\Path;

class TemplateLocatorTest extends TestCase
{
    public function testFindsThemeDirectories(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance');

        $locator = $this->getTemplateLocator($projectDir, [
            'templates/my/theme',
            'templates/non-existing',
        ]);

        $expectedThemeDirectories = [
            'my_theme' => Path::join($projectDir, 'templates/my/theme'),
        ];

        $this->assertSame($expectedThemeDirectories, $locator->findThemeDirectories());
    }

    public function testFindsThemeDirectoriesOutsideTemplatesDirectory(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance');

        $locator = $this->getTemplateLocator($projectDir, [
            'themes/foo',
        ]);

        $expectedThemeDirectories = [
            '_themes_foo' => Path::join($projectDir, 'themes/foo'),
        ];

        $this->assertSame($expectedThemeDirectories, $locator->findThemeDirectories());
    }

    public function testTriggersDeprecationIfThemeDirectoryContainsInvalidCharacters(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance/themes');
        $locator = $this->getTemplateLocator($projectDir, ['templates/invalid.theme']);

        $this->expectException(InvalidThemePathException::class);
        $this->expectExceptionMessage('The theme path "invalid.theme" contains one or more invalid characters: "."');

        $this->assertEmpty($locator->findThemeDirectories());
    }

    public function testIgnoresTableNotFoundExceptions(): void
    {
        $exception = new TableNotFoundException($this->createMock(LegacyDriverException::class), null);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willThrowException($exception)
        ;

        $locator = new TemplateLocator(
            '',
            $this->createMock(ResourceFinder::class),
            $this->createMock(ThemeNamespace::class),
            $connection,
        );

        $this->assertEmpty($locator->findThemeDirectories());
    }

    public function testIgnoresConnectionExceptions(): void
    {
        $exception = new ConnectionException($this->createMock(LegacyDriverException::class), null);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willThrowException($exception)
        ;

        $locator = new TemplateLocator(
            '',
            $this->createMock(ResourceFinder::class),
            $this->createMock(ThemeNamespace::class),
            $connection,
        );

        $this->assertEmpty($locator->findThemeDirectories());
    }

    public function testIgnoresDriverExceptions(): void
    {
        $exception = new DriverException(PDOException::new(new \PDOException("Access denied for user 'root'@'localhost'")), null);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willThrowException($exception)
        ;

        $locator = new TemplateLocator(
            '',
            $this->createMock(ResourceFinder::class),
            $this->createMock(ThemeNamespace::class),
            $connection,
        );

        $this->assertEmpty($locator->findThemeDirectories());
    }

    public function testFindsResourcesPaths(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance');

        $paths = [
            'foo' => Path::join($projectDir, 'system/modules/foo/templates'),
            'BarBundle' => Path::join($projectDir, 'vendor-bundles/BarBundle/contao/templates'),
            'CoreBundle' => Path::join($projectDir, 'vendor-bundles/CoreBundle/Resources/contao/templates'),
            'App' => Path::join($projectDir, 'contao/templates'),
        ];

        $locator = $this->getTemplateLocator($projectDir, [], $paths);

        $expectedResourcePaths = [
            'App' => [
                Path::join($projectDir, 'contao/templates'),
                Path::join($projectDir, 'contao/templates/other'),
                Path::join($projectDir, 'contao/templates/some'),
                Path::join($projectDir, 'contao/templates/some/random'),
            ],
            'CoreBundle' => [
                Path::join($projectDir, 'vendor-bundles/CoreBundle/Resources/contao/templates'),
            ],
            'BarBundle' => [
                Path::join($projectDir, 'vendor-bundles/BarBundle/contao/templates'),
            ],
            'foo' => [
                Path::join($projectDir, 'system/modules/foo/templates'),
                Path::join($projectDir, 'system/modules/foo/templates/any'),
            ],
        ];

        $paths = $locator->findResourcesPaths();

        // Make sure the order is like specified
        $this->assertSame(array_keys($expectedResourcePaths), array_keys($paths));
        $this->assertSame(array_values($expectedResourcePaths), array_values($paths));
    }

    public function testFindsResourcesPathsIgnoresSubdirectoriesInNamespaceRoots(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/explicit-roots');
        $locator = $this->getTemplateLocator($projectDir, [], ['App' => Path::join($projectDir, 'contao/templates')]);

        $this->assertSame(
            ['App' => [Path::join($projectDir, 'contao/templates')]],
            $locator->findResourcesPaths(),
            'should not contain the "content_element" sub-directory',
        );
    }

    public function testFindsTemplates(): void
    {
        $path = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/inheritance/vendor-bundles/InvalidBundle1/templates');
        $locator = $this->getTemplateLocator('/project/dir');

        $expectedTemplates = [
            'foo.html.twig' => Path::join($path, 'foo.html.twig'),
        ];

        $this->assertSame($expectedTemplates, $locator->findTemplates($path));
    }

    public function testFindsTemplatesWithDirectoryStructure(): void
    {
        $path = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/nested');
        $locator = $this->getTemplateLocator('/project/dir');

        $expectedTemplates = [
            'content-element/text.html.twig' => Path::join($path, 'content-element/text.html.twig'),
            'content-element/text/variant.html.twig' => Path::join($path, 'content-element/text/variant.html.twig'),
        ];

        $this->assertSame($expectedTemplates, $locator->findTemplates($path));
    }

    public function testFindsTemplatesWithImplicitNamespaceRoots(): void
    {
        $projectDir = Path::canonicalize(__DIR__.'/../../Fixtures/Twig/implicit-roots');
        $locator = $this->getTemplateLocator($projectDir, ['templates/my/theme']);

        $expectedTemplates = [
            'content_element/foo.html.twig' => Path::join($projectDir, 'templates/content_element/foo.html.twig'),
            'my/theme/content_element/bar.html.twig' => Path::join($projectDir, 'templates/my/theme/content_element/bar.html.twig'),
        ];

        $expectedThemeTemplates = [
            'content_element/bar.html.twig' => Path::join($projectDir, 'templates/my/theme/content_element/bar.html.twig'),
        ];

        $this->assertEmpty(
            $locator->findTemplates(Path::join($projectDir, 'contao/templates')),
            'expect single depth without implicit root',
        );

        $this->assertSame(
            $expectedTemplates,
            $locator->findTemplates(Path::join($projectDir, 'templates')),
            'expect templates with directory structure but no theme templates',
        );

        $this->assertSame(
            $expectedThemeTemplates,
            $locator->findTemplates(Path::join($projectDir, 'templates/my/theme')),
            'expect theme templates with directory structure',
        );
    }

    public function testFindsNoTemplatesIfPathDoesNotExist(): void
    {
        $locator = $this->getTemplateLocator('/project/dir');

        $this->assertEmpty($locator->findTemplates('/invalid/path'));
    }

    private function getTemplateLocator(string $projectDir = '/', array $themePaths = [], array $paths = []): TemplateLocator
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturn($themePaths)
        ;

        $resourceFinder = $this->createMock(ResourceFinder::class);
        $resourceFinder
            ->method('getExistingSubpaths')
            ->with('templates')
            ->willReturn($paths)
        ;

        return new TemplateLocator($projectDir, $resourceFinder, new ThemeNamespace(), $connection);
    }
}
