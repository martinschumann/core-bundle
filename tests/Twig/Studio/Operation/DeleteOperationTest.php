<?php

declare(strict_types=1);

namespace Contao\CoreBundle\Tests\Twig\Studio\Operation;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Contao\CoreBundle\Twig\Studio\Operation\DeleteOperation;
use Contao\CoreBundle\Twig\Studio\Operation\OperationContext;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class DeleteOperationTest extends AbstractOperationTest
{
    /**
     * @dataProvider provideCommonContextsForExistingAndNonExistingUserTemplates
     */
    public function testCanExecute(OperationContext $context, bool $userTemplateExists): void
    {
        $this->assertSame(
            $userTemplateExists,
            $this->getDeleteOperation()->canExecute($context),
        );
    }

    /**
     * @dataProvider provideCommonThemeAndPathForNonExistingUserTemplate
     */
    public function testFailToDeleteUserTemplateBecauseItDoesNotExists(string|null $themeSlug): void
    {
        $storage = $this->mockUserTemplatesStorage();
        $storage
            ->expects($this->never())
            ->method('delete')
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

        $operation = $this->getDeleteOperation(storage: $storage, twig: $twig);

        $response = $operation->execute(
            new Request(),
            $this->getOperationContext('content_element/no_user_template', $themeSlug),
        );

        $this->assertSame('error.stream', $response->getContent());
    }

    /**
     * @dataProvider provideCommonThemeAndPathForExistingUserTemplate
     */
    public function testStreamConfirmDialogWhenDeletingUserTemplate(string|null $themeSlug): void
    {
        $storage = $this->mockUserTemplatesStorage();
        $storage
            ->expects($this->never())
            ->method('delete')
        ;

        $twig = $this->mockTwigEnvironment();
        $twig
            ->expects($this->once())
            ->method('render')
            ->with(
                '@Contao/backend/template_studio/operation/delete_confirm.stream.html.twig',
                ['identifier' => 'content_element/existing_user_template'],
            )
            ->willReturn('delete_confirm.stream')
        ;

        $operation = $this->getDeleteOperation(storage: $storage, twig: $twig);

        $response = $operation->execute(
            new Request(),
            $this->getOperationContext('content_element/existing_user_template', $themeSlug),
        );

        $this->assertSame('delete_confirm.stream', $response->getContent());
    }

    /**
     * @dataProvider provideCommonThemeAndPathForExistingUserTemplate
     */
    public function testDeleteUserTemplate(string|null $themeSlug, string $path): void
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
            ->method('delete')
            ->with($path)
        ;

        $twig = $this->mockTwigEnvironment();
        $twig
            ->expects($this->once())
            ->method('render')
            ->with(
                '@Contao/backend/template_studio/operation/delete_result.stream.html.twig',
                ['identifier' => 'content_element/existing_user_template', 'was_last' => true],
            )
            ->willReturn('delete_result.stream')
        ;

        $operation = $this->getDeleteOperation($loader, $storage, $twig);

        $response = $operation->execute(
            new Request(request: ['confirm_delete' => true]),
            $this->getOperationContext('content_element/existing_user_template', $themeSlug),
        );

        $this->assertSame('delete_result.stream', $response->getContent());
    }

    private function getDeleteOperation(ContaoFilesystemLoader|null $loader = null, VirtualFilesystemInterface|null $storage = null, Environment|null $twig = null): DeleteOperation
    {
        $operation = new DeleteOperation();
        $operation->setContainer($this->getContainer($loader, $storage, $twig));
        $operation->setName('delete');

        return $operation;
    }
}
