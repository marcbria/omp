<?php

/**
 * @file pages/catalog/CatalogBookHandler.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class CatalogBookHandler
 * @ingroup pages_catalog
 *
 * @brief Handle requests for the book-specific part of the public-facing
 *   catalog.
 */

import('classes.handler.Handler');

// import UI base classes
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.core.JSONMessage');

class CatalogBookHandler extends Handler {
	/**
	 * Constructor
	 */
	function CatalogBookHandler() {
		parent::Handler();
	}


	//
	// Overridden functions from PKPHandler
	//
	/**
	 * @see PKPHandler::authorize()
	 * @param $request PKPRequest
	 * @param $args array
	 * @param $roleAssignments array
	 */
	function authorize(&$request, &$args, $roleAssignments) {
		import('classes.security.authorization.OmpPublishedMonographAccessPolicy');
		$this->addPolicy(new OmpPublishedMonographAccessPolicy($request, $args, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}


	//
	// Public handler methods
	//
	/**
	 * Display a published monograph in the public catalog.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function book($args, &$request) {
		$templateMgr =& TemplateManager::getManager($request);
		$press =& $request->getPress();
		$this->setupTemplate($request);
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_SUBMISSION); // submission.synopsis

		$publishedMonograph =& $this->getAuthorizedContextObject(ASSOC_TYPE_PUBLISHED_MONOGRAPH);
		$templateMgr->assign('publishedMonograph', $publishedMonograph);

		// Get Social media blocks enabled for the catalog
		$socialMediaDao =& DAORegistry::getDAO('SocialMediaDAO');
		$socialMedia =& $socialMediaDao->getEnabledForContextByContextId($press->getId());
		$blocks = array();
		while ($media =& $socialMedia->next()) {
			$media->replaceCodeVars($publishedMonograph);
			$blocks[] = $media->getCode();
		}

		$templateMgr->assign_by_ref('blocks', $blocks);

		// add Chapters, if they exist.
		if ($publishedMonograph->getWorkType() == WORK_TYPE_EDITED_VOLUME) {
			$chapterDao =& DAORegistry::getDAO('ChapterDAO');
			$chapters =& $chapterDao->getChapters($publishedMonograph->getId());
			$templateMgr->assign_by_ref('chapters', $chapters->toAssociativeArray());
		}
		// determine which pubId plugins are enabled.
		$pubIdPlugins =& PluginRegistry::loadCategory('pubIds', true);
		$enabledPubIdTypes = array();
		foreach ($pubIdPlugins as $plugin) {
			if ($plugin->getEnabled()) {
				$enabledPubIdTypes[] = $plugin->getPubIdType();
				// check to see if the format has a pubId set.  If not, generate one.
				$publicationFormats =& $publishedMonograph->getPublicationFormats(true);
				foreach ($publicationFormats as $publicationFormat) {
					if ($publicationFormat->getStoredPubId($plugin->getPubIdType()) == '') {
						$plugin->getPubId($publicationFormat);
					}
				}
			}
		}
		$templateMgr->assign('enabledPubIdTypes', $enabledPubIdTypes);

		// e-Commerce
		import('classes.payment.omp.OMPPaymentManager');
		$ompPaymentManager = new OMPPaymentManager($request);
		$monographFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
		if ($ompPaymentManager->isConfigured()) {
			$availableFiles = array_filter(
				$monographFileDao->getLatestRevisions($publishedMonograph->getId()),
				create_function('$a', 'return $a->getViewable() && $a->getDirectSalesPrice() !== null && $a->getAssocType() == ASSOC_TYPE_PUBLICATION_FORMAT;')
			);
			$availableFilesByPublicationFormat = array();
			foreach ($availableFiles as $availableFile) {
				$availableFilesByPublicationFormat[$availableFile->getAssocId()][] = $availableFile;
			}

			// Determine whether or not to use the collapsed view.
			$useCollapsedView = true;
			foreach ($availableFilesByPublicationFormat as $publicationFormatId => $availableFiles) {
				if (count($availableFiles)>1) {
					$useCollapsedView = false;
					break;
				}
			}

			// Expose variables to template
			$templateMgr->assign('availableFiles', $availableFilesByPublicationFormat);
			$templateMgr->assign('useCollapsedView', $useCollapsedView);
		}

		// Display
		$templateMgr->display('catalog/book/book.tpl');
	}

	/**
	 * Download a published monograph publication format.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function download($args, &$request) {
		$press =& $request->getPress();
		$this->setupTemplate($request);

		$monographId = (int) array_shift($args); // Validated thru auth
		$publicationFormatId = (int) array_shift($args);
		$fileIdAndRevision = array_shift($args);

		$publishedMonograph =& $this->getAuthorizedContextObject(ASSOC_TYPE_PUBLISHED_MONOGRAPH);
		$publicationFormatDao =& DAORegistry::getDAO('PublicationFormatDAO');
		$publicationFormat =& $publicationFormatDao->getById($publicationFormatId, $publishedMonograph->getId());
		if (!$publicationFormat || !$publicationFormat->getIsApproved() || !$publicationFormat->getIsAvailable()) fatalError('Invalid publication format specified.');

		$submissionFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
		list($fileId, $revision) = array_map(create_function('$a', 'return (int) $a;'), split('-', $fileIdAndRevision));
		import('classes.monograph.MonographFile'); // File constants
		$submissionFile =& $submissionFileDao->getRevision($fileId, $revision, SUBMISSION_FILE_PROOF, $monographId);
		if (!$submissionFile || $submissionFile->getAssocType() != ASSOC_TYPE_PUBLICATION_FORMAT || $submissionFile->getAssocId() != $publicationFormatId || $submissionFile->getDirectSalesPrice() === null) {
			fatalError('Invalid monograph file specified!');
		}

		$ompCompletedPaymentDao =& DAORegistry::getDAO('OMPCompletedPaymentDAO');
		$user =& $request->getUser();
		if ($submissionFile->getDirectSalesPrice() === '0' || ($user && $ompCompletedPaymentDao->hasPaidPurchaseFile($user->getId(), $fileIdAndRevision))) {
			// Paid purchase or open access. Allow download.
			import('classes.file.MonographFileManager');
			$monographFileManager = new MonographFileManager($press->getId(), $monographId);
			return $monographFileManager->downloadFile($fileId, $revision);
		}

		// Fall-through: user needs to pay for purchase.

		// Check that they are registered and logged in.
		if (!$user) return $request->redirect(null, 'login', null, null, array('source' => $request->url(null, null, null, array($monographId, $publicationFormatId, $fileIdAndRevision))));

		import('classes.payment.omp.OMPPaymentManager');
		$ompPaymentManager = new OMPPaymentManager($request);
		if (!$ompPaymentManager->isConfigured()) {
			$request->redirect(null, 'catalog');
		}

		$queuedPayment = $ompPaymentManager->createQueuedPayment(
			$press->getId(),
			PAYMENT_TYPE_PURCHASE_FILE,
			$user->getId(),
			$fileIdAndRevision,
			$submissionFile->getDirectSalesPrice(),
			$press->getSetting('currency')
		);

		$queuedPaymentId = $ompPaymentManager->queuePayment($queuedPayment);
		$ompPaymentManager->displayPaymentForm($queuedPaymentId, $queuedPayment);
	}
}

?>
