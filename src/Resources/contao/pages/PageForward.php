<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Exception\ForwardPageNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provide methods to handle a forward page.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 * @author Yanick Witschi <https://github.com/Toflar>
 */
class PageForward extends Frontend
{
	/**
	 * Redirect to an internal page
	 *
	 * @param PageModel $objPage
	 *
	 * @deprecated Deprecated since Contao 4.9, to be removed in Contao 5. Use the PageForward::getResponse() method instead.
	 */
	public function generate($objPage)
	{
		@trigger_error('Using PageForward::generate() has been deprecated in Contao 4.9 and will be removed in Contao 5.0. Use the PageForward::getResponse() method instead.', E_USER_DEPRECATED);

		$this->redirect($this->getForwardUrl($objPage), $this->getRedirectStatusCode($objPage));
	}

	/**
	 * Return a response object
	 *
	 * @param PageModel $objPage
	 *
	 * @return RedirectResponse
	 */
	public function getResponse($objPage)
	{
		return new RedirectResponse($this->getForwardUrl($objPage), $this->getRedirectStatusCode($objPage));
	}

	/**
	 * Return the URL to the jumpTo or first published page
	 *
	 * @param PageModel $objPage
	 *
	 * @return string
	 *
	 * @throws ForwardPageNotFoundException
	 */
	protected function getForwardUrl($objPage)
	{
		if ($objPage->jumpTo)
		{
			$objNextPage = PageModel::findPublishedById($objPage->jumpTo);
		}
		else
		{
			$objNextPage = PageModel::findFirstPublishedRegularByPid($objPage->id);
		}

		// Forward page does not exist
		if (!$objNextPage instanceof PageModel)
		{
			$this->log('Forward page ID "' . $objPage->jumpTo . '" does not exist', __METHOD__, TL_ERROR);

			throw new ForwardPageNotFoundException('Forward page not found');
		}

		$strGet = '';
		$strQuery = Environment::get('queryString');
		$arrQuery = array();

		// Extract the query string keys (see #5867)
		if ($strQuery)
		{
			$arrChunks = explode('&', $strQuery);

			foreach ($arrChunks as $strChunk)
			{
				list($k) = explode('=', $strChunk, 2);
				$arrQuery[] = $k;
			}
		}

		// Add $_GET parameters
		if (!empty($_GET))
		{
			foreach (array_keys($_GET) as $key)
			{
				if ($key == 'language' && Config::get('addLanguageToUrl'))
				{
					continue;
				}

				// Ignore the query string parameters (see #5867)
				if (\in_array($key, $arrQuery))
				{
					continue;
				}

				// Ignore the auto_item parameter (see #5886)
				if ($key == 'auto_item')
				{
					$strGet .= '/' . Input::get($key);
				}
				else
				{
					$strGet .= '/' . $key . '/' . Input::get($key);
				}
			}
		}

		// Append the query string (see #5867)
		if ($strQuery)
		{
			$strQuery = '?' . $strQuery;
		}

		return $objNextPage->getAbsoluteUrl($strGet) . $strQuery;
	}

	/**
	 * Return the redirect status code
	 *
	 * @param PageModel $objPage
	 *
	 * @return integer
	 */
	protected function getRedirectStatusCode($objPage)
	{
		return ($objPage->redirect == 'temporary') ? 303 : 301;
	}
}

class_alias(PageForward::class, 'PageForward');
