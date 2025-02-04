<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Fixtures\Controller\Page;

use Contao\CoreBundle\Controller\Page\AbstractLayoutPageController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsPage;
use Contao\CoreBundle\Twig\LayoutTemplate;
use Contao\LayoutModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsPage]
class LayoutPageController extends AbstractLayoutPageController
{
    protected function getResponse(LayoutTemplate $template, LayoutModel $model, Request $request): Response
    {
        return new JsonResponse([...$template->getData(), 'templateName' => $template->getName()]);
    }
}
