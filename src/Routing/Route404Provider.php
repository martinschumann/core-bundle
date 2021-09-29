<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Routing;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\Page\PageRoute;
use Contao\CoreBundle\Util\LocaleUtil;
use Contao\PageModel;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Cmf\Component\Routing\Candidates\CandidatesInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Route404Provider extends AbstractPageRouteProvider
{
    /**
     * @var PageRegistry
     */
    private $pageRegistry;

    /**
     * @internal Do not inherit from this class; decorate the "contao.routing.route_404_provider" service instead
     */
    public function __construct(ContaoFramework $framework, CandidatesInterface $candidates, PageRegistry $pageRegistry)
    {
        parent::__construct($framework, $candidates);

        $this->pageRegistry = $pageRegistry;
    }

    public function getRouteCollectionForRequest(Request $request): RouteCollection
    {
        $this->framework->initialize(true);

        $collection = new RouteCollection();

        $routes = array_merge(
            $this->getNotFoundRoutes(),
            $this->getLocaleFallbackRoutes($request)
        );

        $this->sortRoutes($routes, $request->getLanguages());

        foreach ($routes as $name => $route) {
            $collection->add($name, $route);
        }

        return $collection;
    }

    public function getRouteByName($name): Route
    {
        $this->framework->initialize(true);

        $ids = $this->getPageIdsFromNames([$name]);

        if (empty($ids)) {
            throw new RouteNotFoundException('Route name does not match a page ID');
        }

        /** @var PageModel $pageModel */
        $pageModel = $this->framework->getAdapter(PageModel::class);
        $page = $pageModel->findByPk($ids[0]);

        if (null === $page) {
            throw new RouteNotFoundException(sprintf('Page ID "%s" not found', $ids[0]));
        }

        $routes = [];

        $this->addNotFoundRoutesForPage($page, $routes);
        $this->addLocaleRedirectRoute($this->pageRegistry->getRoute($page), null, $routes);

        if (!\array_key_exists($name, $routes)) {
            throw new RouteNotFoundException('Route "'.$name.'" not found');
        }

        return $routes[$name];
    }

    public function getRoutesByNames($names): array
    {
        $this->framework->initialize(true);

        /** @var PageModel $pageAdapter */
        $pageAdapter = $this->framework->getAdapter(PageModel::class);

        if (null === $names) {
            $pages = $pageAdapter->findAll();
        } else {
            $ids = $this->getPageIdsFromNames($names);

            if (empty($ids)) {
                return [];
            }

            $pages = $pageAdapter->findBy('tl_page.id IN ('.implode(',', $ids).')', []);
        }

        $routes = [];

        foreach ($pages as $page) {
            $this->addNotFoundRoutesForPage($page, $routes);
            $this->addLocaleRedirectRoute($this->pageRegistry->getRoute($page), null, $routes);
        }

        $this->sortRoutes($routes);

        return $routes;
    }

    private function getNotFoundRoutes(): array
    {
        $this->framework->initialize(true);

        /** @var PageModel $pageModel */
        $pageModel = $this->framework->getAdapter(PageModel::class);
        $pages = $pageModel->findByType('error_404');

        if (null === $pages) {
            return [];
        }

        $routes = [];

        foreach ($pages as $page) {
            $this->addNotFoundRoutesForPage($page, $routes);
        }

        return $routes;
    }

    private function addNotFoundRoutesForPage(PageModel $page, array &$routes): void
    {
        if ('error_404' !== $page->type) {
            return;
        }

        $page->loadDetails();

        $defaults = [
            '_token_check' => true,
            '_controller' => 'Contao\FrontendIndex::renderPage',
            '_scope' => ContaoCoreBundle::SCOPE_FRONTEND,
            '_format' => 'html',
            '_canonical_route' => 'tl_page.'.$page->id,
            'pageModel' => $page,
        ];

        if ($page->rootLanguage) {
            $defaults['_locale'] = LocaleUtil::formatAsLocale($page->rootLanguage);
        }

        $requirements = ['_url_fragment' => '.*'];
        $path = '/{_url_fragment}';

        $routes['tl_page.'.$page->id.'.error_404'] = new Route(
            $path,
            $defaults,
            $requirements,
            ['utf8' => true],
            $page->domain,
            $page->rootUseSSL ? 'https' : 'http'
        );

        if (!$page->urlPrefix) {
            return;
        }

        $path = '/'.$page->urlPrefix.$path;

        $routes['tl_page.'.$page->id.'.error_404.locale'] = new Route(
            $path,
            $defaults,
            $requirements,
            ['utf8' => true],
            $page->domain,
            $page->rootUseSSL ? 'https' : 'http'
        );
    }

    private function getLocaleFallbackRoutes(Request $request): array
    {
        if ('/' === $request->getPathInfo()) {
            return [];
        }

        $routes = [];

        foreach ($this->findCandidatePages($request) as $page) {
            $this->addLocaleRedirectRoute($this->pageRegistry->getRoute($page), $request, $routes);
        }

        return $routes;
    }

    private function addLocaleRedirectRoute(PageRoute $route, ?Request $request, array &$routes): void
    {
        $length = \strlen($route->getUrlPrefix());

        if (0 === $length) {
            return;
        }

        $redirect = new Route(
            substr($route->getPath(), $length + 1),
            $route->getDefaults(),
            $route->getRequirements(),
            $route->getOptions(),
            $route->getHost(),
            $route->getSchemes(),
            $route->getMethods()
        );

        $path = $route->getPath();

        if (null !== $request) {
            $path = '/'.$route->getUrlPrefix().$request->getPathInfo();
        }

        $redirect->addDefaults([
            '_controller' => RedirectController::class,
            'path' => $path,
            'permanent' => false,
        ]);

        $routes['tl_page.'.$route->getPageModel()->id.'.locale'] = $redirect;
    }

    /**
     * Sorts routes so that the FinalMatcher will correctly resolve them.
     *
     * 1. Sort locale-aware routes first, so e.g. /de/not-found.html renders the german error page
     * 2. Then sort by hostname, so the ones with empty host are only taken if no hostname matches
     * 3. Lastly pages must be sorted by accept language and fallback, so the best language matches first
     */
    private function sortRoutes(array &$routes, array $languages = null): void
    {
        // Convert languages array so key is language and value is priority
        if (null !== $languages) {
            $languages = $this->convertLanguagesForSorting($languages);
        }

        uasort(
            $routes,
            function (Route $a, Route $b) use ($languages, $routes) {
                $errorA = false !== strpos('.error_404', array_search($a, $routes, true));
                $errorB = false !== strpos('.error_404', array_search($a, $routes, true), -7);
                $localeA = '.locale' === substr(array_search($a, $routes, true), -7);
                $localeB = '.locale' === substr(array_search($b, $routes, true), -7);

                if ($errorA && !$errorB) {
                    return 1;
                }

                if ($errorB && !$errorA) {
                    return -1;
                }

                if ($localeA && !$localeB) {
                    return -1;
                }

                if ($localeB && !$localeA) {
                    return 1;
                }

                return $this->compareRoutes($a, $b, $languages);
            }
        );
    }
}
