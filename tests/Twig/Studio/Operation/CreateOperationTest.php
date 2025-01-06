<?php

declare(strict_types=1);

namespace Contao\CoreBundle\Tests\Twig\Studio\Operation;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Contao\CoreBundle\Twig\Studio\Operation\CreateOperation;
use Contao\CoreBundle\Twig\Studio\Operation\OperationContext;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class CreateOperationTest extends AbstractOperationTest
{
    /**
     * @dataProvider provideCommonContextsForExistingAndNonExistingUserTemplates
     */
    public function testCanExecute(OperationContext $context, bool $userTemplateExists): void
    {
        $this->assertSame(
            !$userTemplateExists,
            $this->getCreateOperation()->canExecute($context),
        );
    }

    /**
     * @dataProvider provideCommonThemeAndPathForExistingUserTemplate
     */
    public function testFailToCreateUserTemplateBecauseItAlreadyExists(string|null $themeSlug): void
    {
        $storage = $this->mockUserTemplatesStorage();
        $storage
            ->expects($this->never())
            ->method('write')
        ;

        $twig = $this->mockTwigEnvironment();
        $twig
            ->expects($this->once())
            ->method('render')
            ->with(
                '@Contao/backend/template_studio/operation/default_result.stream.html.twig',
                $this->anything(),
            )
            ->willReturn('error.stream')
        ;

        $operation = $this->getCreateOperation(storage: $storage, twig: $twig);

        $response = $operation->execute(
            new Request(),
            $this->getOperationContext('content_element/existing_user_template', $themeSlug),
        );

        $this->assertSame('error.stream', $response->getContent());
    }

    /**
     * @dataProvider provideCommonThemeAndPathForNonExistingUserTemplate
     */
    public function testCreateUserTemplate(string|null $themeSlug, string $path): void
    {
        $loader = $this->mockContaoFilesystemLoader();
        $loader
            ->expects($this->once())
            ->method('warmUp')
            ->with(true)
        ;

        $storage = $this->mockUserTemplatesStorage();
        $storage
            ->expects($this->once())
            ->method('write')
            ->with($path, 'new template content')
        ;

        $twig = $this->mockTwigEnvironment();
        $twig
            ->expects($this->once())
            ->method('render')
            ->with(
                '@Contao/backend/template_studio/operation/create_result.stream.html.twig',
                ['identifier' => 'content_element/no_user_template'],
            )
            ->willReturn('create_result.stream')
        ;

        $operation = $this->getCreateOperation($loader, $storage, $twig);

        $response = $operation->execute(
            new Request(),
            $this->getOperationContext('content_element/no_user_template', $themeSlug),
        );

        $this->assertSame('create_result.stream', $response->getContent());
    }

    private function getCreateOperation(ContaoFilesystemLoader|null $loader = null, VirtualFilesystemInterface|null $storage = null, Environment|null $twig = null): CreateOperation
    {
        $operation = new CreateOperation();
        $operation->setContainer($this->getContainer($loader, $storage, $twig));
        $operation->setName('create_*');

        return $operation;
    }
}
