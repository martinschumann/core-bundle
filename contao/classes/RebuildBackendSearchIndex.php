<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Search\Backend\ReindexConfig;

/**
 * Maintenance module "rebuild backend search index".
 */
class RebuildBackendSearchIndex extends Backend implements MaintenanceModuleInterface
{
	public function isActive()
	{
		return false;
	}

	/**
	 * Generate the module
	 *
	 * @return string
	 */
	public function run()
	{
		// Not even configured, hide the section entirely
		if (!System::getContainer()->has('contao.search.backend'))
		{
			return '';
		}

		$backendSearch = System::getContainer()->get('contao.search.backend');

		$objTemplate = new BackendTemplate('be_rebuild_backend_search');
		$objTemplate->disabled = !$backendSearch->isAvailable();

		if (Input::post('FORM_SUBMIT') == 'tl_rebuild_backend_search' && $backendSearch->isAvailable())
		{
			$backendSearch->reindex(new ReindexConfig());
			Message::addConfirmation($GLOBALS['TL_LANG']['tl_maintenance']['backend_search']['confirmation'], self::class);
			$this->reload();
		}

		return $objTemplate->parse();
	}
}
