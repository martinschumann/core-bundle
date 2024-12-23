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
class DeleteOperation extends AbstractOperation
{
    public function canExecute(OperationContext $context): bool
    {
        return $this->userTemplateExists($context);
    }

    public function execute(Request $request, OperationContext $context): Response|null
    {
        $storage = $this->getUserTemplatesStorage();

        if (!$storage->fileExists($context->getUserTemplatesStoragePath())) {
            return $this->error($context);
        }

        // Show a confirmation dialog
        if (!$request->request->has('confirm_delete')) {
            return $this->render('@Contao/backend/template_studio/operation/delete_confirm.stream.html.twig', [
                'identifier' => $context->getIdentifier(),
            ]);
        }

        // Delete the user template file
        $this->getUserTemplatesStorage()->delete($context->getUserTemplatesStoragePath());

        $this->refreshTemplateHierarchy();

        return $this->render('@Contao/backend/template_studio/operation/delete_result.stream.html.twig', [
            'identifier' => $context->getIdentifier(),
            'was_last' => !isset($this->getContaoFilesystemLoader()->getInheritanceChains()[$context->getIdentifier()]),
        ]);
    }
}
