<?php

declare(strict_types=1);

namespace Contao\CoreBundle\Twig\Studio\Operation;

use Contao\CoreBundle\DependencyInjection\Attribute\AsOperationForTemplateStudioElement;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @experimental
 */
#[AsOperationForTemplateStudioElement]
class CreateOperation extends AbstractOperation
{
    public function canExecute(OperationContext $context): bool
    {
        return !$this->userTemplateExists($context);
    }

    public function execute(Request $request, OperationContext $context): Response|null
    {
        $storage = $this->getUserTemplatesStorage();

        if ($storage->fileExists($context->getUserTemplatesStoragePath())) {
            return $this->error($context);
        }

        // Create the user template file with some default content
        $skeleton = $this->getTemplateSkeletonFactory()
            ->create()
            ->getContent($context->getManagedNamespaceName())
        ;

        $this->getUserTemplatesStorage()->write($context->getUserTemplatesStoragePath(), $skeleton);

        $this->refreshTemplateHierarchy();

        return $this->render(
            '@Contao/backend/template_studio/operation/create_result.stream.html.twig',
            ['identifier' => $context->getIdentifier()],
        );
    }
}
