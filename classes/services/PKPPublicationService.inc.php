<?php
/**
 * @file classes/services/PKPPublicationService.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2000-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PKPPublicationService
 * @ingroup services
 *
 * @brief Helper class that encapsulates business logic for publications
 */

namespace PKP\Services;

use Application;
use \Core;
use \DAOResultFactory;
use \DAORegistry;
use \DBResultRange;
use HookRegistry;
use \Services;
use SubmissionLog;
use \PKP\Services\interfaces\EntityPropertyInterface;
use \PKP\Services\interfaces\EntityReadInterface;
use \PKP\Services\interfaces\EntityWriteInterface;
use \PKP\Services\QueryBuilders\PKPPublicationQueryBuilder;

import('lib.pkp.classes.db.DBResultRange');

class PKPPublicationService implements EntityPropertyInterface, EntityReadInterface, EntityWriteInterface {

	/**
	 * @copydoc \PKP\Services\interfaces\EntityReadInterface::get()
	 */
	public function get($publicationId) {
		return DAORegistry::getDAO('PublicationDAO')->getById($publicationId);
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityReadInterface::getCount()
	 */
	public function getCount($args = []) {
		return $this->getQueryBuilder($args)->getCount();
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityReadInterface::getIds()
	 */
	public function getIds($args = []) {
		return $this->getQueryBuilder($args)->getIds();
	}

	/**
	 * Get publications
	 *
	 * @param array $args {
	 *		@option int|array submissionIds
	 *		@option string publisherIds
	 * 		@option int count
	 * 		@option int offset
	 * }
	 * @return Iterator
	 */
	public function getMany($args = []) {
		$range = null;
		if (isset($args['count'])) {
			import('lib.pkp.classes.db.DBResultRange');
			$range = new \DBResultRange($args['count'], null, isset($args['offset']) ? $args['offset'] : 0);
		}
		// Pagination is handled by the DAO, so don't pass count and offset
		// arguments to the QueryBuilder.
		if (isset($args['count'])) unset($args['count']);
		if (isset($args['offset'])) unset($args['offset']);
		$publicationQO = $this->getQueryBuilder($args)->getQuery();
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$result = $publicationDao->retrieveRange($publicationQO->toSql(), $publicationQO->getBindings(), $range);
		$queryResults = new DAOResultFactory($result, $publicationDao, '_fromRow');

		return $queryResults->toIterator();
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityReadInterface::getMax()
	 */
	public function getMax($args = []) {
		// Don't accept args to limit the results
		if (isset($args['count'])) unset($args['count']);
		if (isset($args['offset'])) unset($args['offset']);
		return $this->getQueryBuilder($args)->getCount();
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityReadInterface::getQueryBuilder()
	 * @return PKPPublicationQueryBuilder
	 */
	public function getQueryBuilder($args = []) {

		$defaultArgs = [
			'contextIds' => [],
			'publisherIds' => [],
			'submissionIds' => [],
		];

		$args = array_merge($defaultArgs, $args);

		$publicationQB = new PKPPublicationQueryBuilder();
		$publicationQB
			->filterByContextIds($args['contextIds'])
			->filterByPublisherIds($args['publisherIds'])
			->filterBySubmissionIds($args['submissionIds']);

		if (isset($args['count'])) {
			$publicationQB->limitTo($args['count']);
		}

		if (isset($args['offset'])) {
			$publicationQB->offsetBy($args['count']);
		}

		HookRegistry::call('Publication::getMany::queryBuilder', [$publicationQB, $args]);

		return $publicationQB;
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityPropertyInterface::getProperties()
	 */
	public function getProperties($publication, $props, $args = null) {
		$request = $args['request'];
		$dispatcher = $request->getDispatcher();

		// Get required submission and context
		$submission = !empty($args['submission'])
			? $args['submission']
			: $args['submission'] = Services::get('submission')->get($publication->getData('submissionId'));

		$submissionContext = !empty($args['context'])
			? $args['context']
			: $args['context'] = Services::get('context')->get($submission->getData('contextId'));

		// Users assigned as reviewers should not receive author details
		if (array_intersect(['authors', 'authorsString', 'authorsStringShort', 'galleys'], $props)) {
			$currentUserReviewAssignment = isset($args['currentUserReviewAssignment'])
				? $args['currentUserReviewAssignment']
				: DAORegistry::getDAO('ReviewAssignmentDAO')
					->getLastReviewRoundReviewAssignmentByReviewer(
						$submission->getId(),
						$request->getUser()->getId()
					);
		}

		$values = [];

		foreach ($props as $prop) {
			switch ($prop) {
				case '_href':
					$values[$prop] = $dispatcher->url(
						$request,
						ROUTE_API,
						$submissionContext->getData('urlPath'),
						'submissions/' . $publication->getData('submissionId') . '/publications/' . $publication->getId()
					);
					break;
				case 'authors':
					if ($currentUserReviewAssignment && $currentUserReviewAssignment->getReviewMethod() === SUBMISSION_REVIEW_METHOD_DOUBLEBLIND) {
						$values[$prop] = [];
					} else {
						$values[$prop] = array_map(
							function($author) use ($request) {
								return Services::get('author')->getSummaryProperties($author, ['request' => $request]);
							},
							$publication->getData('authors')
						);
					}
					break;
				case 'authorsString':
					$values[$prop] = '';
					if ((!$currentUserReviewAssignment || $currentUserReviewAssignment->getReviewMethod() !== SUBMISSION_REVIEW_METHOD_DOUBLEBLIND)
						&& isset($args['userGroups'])) {
						$values[$prop] = $publication->getAuthorString($args['userGroups']);
					}
					break;
				case 'authorsStringShort':
					if ($currentUserReviewAssignment && $currentUserReviewAssignment->getReviewMethod() === SUBMISSION_REVIEW_METHOD_DOUBLEBLIND) {
						$values[$prop] = '';
					} else {
						$values[$prop] = $publication->getShortAuthorString();
					}
					break;
				case 'citations':
					$values[$prop] = array_map(
						function($citation) {
							return $citation->getCitationWithLinks();
						},
						DAORegistry::getDAO('CitationDAO')->getByPublicationId($publication->getId())->toArray()
					);
					break;
				case 'fullTitle':
					$values[$prop] = $publication->getFullTitles();
					break;
				case 'galleys':
					if ($currentUserReviewAssignment && $currentUserReviewAssignment->getReviewMethod() === SUBMISSION_REVIEW_METHOD_DOUBLEBLIND) {
						$values[$prop] = [];
					} else {
						$values[$prop] = array_map(
							function($galley) use ($request, $args) {
								return Services::get('galley')->getSummaryProperties($galley, $args);
							},
							$publication->getData('galleys')
						);
					}
					break;
				case 'urlPublished':
					$values[$prop] = $dispatcher->url(
						$request,
						ROUTE_PAGE,
						$submissionContext->getData('urlPath'),
						'article',
						'view',
						[$submission->getBestId(), 'version', $publication->getId()]
					);
					break;
				default:
					$values[$prop] = $publication->getData($prop);
					break;
			}
		}

		$values = Services::get('schema')->addMissingMultilingualValues(SCHEMA_PUBLICATION, $values, $submissionContext->getSupportedLocales());

		HookRegistry::call('Publication::getProperties', [&$values, $publication, $props, $args]);

		ksort($values);

		return $values;
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityPropertyInterface::getSummaryProperties()
	 */
	public function getSummaryProperties($publication, $args = null) {
		$props = Services::get('schema')->getSummaryProps(SCHEMA_PUBLICATION);

		return $this->getProperties($publication, $props, $args);
	}

	/**
	 * @copydoc \PKP\Services\interfaces\EntityPropertyInterface::getFullProperties()
	 */
	public function getFullProperties($publication, $args = null) {
		$props = Services::get('schema')->getFullProps(SCHEMA_PUBLICATION);

		return $this->getProperties($publication, $props, $args);
	}

	/**
	 * Get the oldest and most recent published dates of matching publications
	 *
	 * @param array $args Supports all args of self::getMany()
	 * @return array [oldest, newest]
	 */
	public function getDateBoundaries($args) {
		$publicationQO = $this->getQueryBuilder($args)->getDateBoundaries();
		$result = DAORegistry::getDAO('PublicationDAO')->retrieve($publicationQO->toSql(), $publicationQO->getBindings());
		return [$result->fields[0], $result->fields[1]];
	}

	/**
	 * @copydoc \PKP\Services\EntityProperties\EntityWriteInterface::validate()
	 */
	public function validate($action, $props, $allowedLocales, $primaryLocale) {
		\AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_APP_SUBMISSION);
		$schemaService = Services::get('schema');

		import('lib.pkp.classes.validation.ValidatorFactory');
		$validator = \ValidatorFactory::make(
			$props,
			$schemaService->getValidationRules(SCHEMA_PUBLICATION, $allowedLocales),
			[
				'locale.regex' => __('validator.localeKey'),
				'datePublished.date_format' => __('publication.datePublished.errorFormat'),
			]
		);

		// Check required fields if we're adding the object
		if ($action === VALIDATE_ACTION_ADD) {
			\ValidatorFactory::required(
				$validator,
				$schemaService->getRequiredProps(SCHEMA_PUBLICATION),
				$schemaService->getMultilingualProps(SCHEMA_PUBLICATION),
				$primaryLocale
			);
		}

		// Check for input from disallowed locales
		\ValidatorFactory::allowedLocales($validator, $schemaService->getMultilingualProps(SCHEMA_PUBLICATION), $allowedLocales);

		// The submissionId must match an existing submission
		$validator->after(function($validator) use ($props) {
			if (isset($props['submissionId']) && !$validator->errors()->get('submissionId')) {
				$submission = Services::get('submission')->get($props['submissionId']);
				if (!$submission) {
					$validator->errors()->add('submissionId', __('publication.invalidSubmission'));
				}
			}
		});

		// Don't allow an empty value for the primary locale of the title field
		\ValidatorFactory::requirePrimaryLocale(
			$validator,
			['title'],
			$props,
			$allowedLocales,
			$primaryLocale
		);

		// If a new file has been uploaded, check that the temporary file exists and
		// the current user owns it
		$user = Application::get()->getRequest()->getUser();
		\ValidatorFactory::temporaryFilesExist(
			$validator,
			['coverImage'],
			['coverImage'],
			$props,
			$allowedLocales,
			$user ? $user->getId() : null
		);

		if ($validator->fails()) {
			$errors = $schemaService->formatValidationErrors($validator->errors(), $schemaService->get(SCHEMA_PUBLICATION), $allowedLocales);
		}

		HookRegistry::call('Publication::validate', [&$errors, $action, $props, $allowedLocales, $primaryLocale]);

		return $errors;
	}

	/**
	 * Validate a publication against publishing requirements
	 *
	 * This validation check should return zero errors before
	 * calling self::publish().
	 *
	 * It should not be necessary to repeat validation rules from
	 * self::validate(). These rules should be applied during all add
	 * or edit actions.
	 *
	 * This additional check should be used when a journal or press
	 * wants to enforce particular publishing requirements, such as
	 * requiring certain metadata or other information.
	 *
	 * @param Publication $publication
	 * @param Submission $submission
	 * @param array $allowedLocales array Which locales are allowed
	 * @param string $primaryLocale string
	 * @return array List of error messages. The array keys are property names
	 */
	public function validatePublish($publication, $submission, $allowedLocales, $primaryLocale) {

		$errors = [];

		// Don't allow declined submissions to be published
		if ($submission->getData('status') === STATUS_DECLINED) {
			$errors['declined'] = __('publication.required.declined');
		}

		// Don't allow a publication to be published before passing the review stage
		if ($submission->getData('stageId') < WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
			$errors['reviewStage'] = __('publication.required.reviewStage');
		}

		HookRegistry::call('Publication::validatePublish', [&$errors, $publication, $submission, $allowedLocales, $primaryLocale]);

		return $errors;
	}

	/**
	 * @copydoc \PKP\Services\EntityProperties\EntityWriteInterface::add()
	 */
	public function add($publication, $request) {
		$publication->stampModified();
		$publicationId = DAORegistry::getDAO('PublicationDAO')->insertObject($publication);
		$publication = $this->get($publicationId);
		$submission = Services::get('submission')->get($publication->getData('submissionId'));

		// Parse the citations
		if ($publication->getData('citationsRaw')) {
			DAORegistry::getDAO('CitationDAO')->importCitations($publication->getId(), $publication->getData('citationsRaw'));
		}

		// Move uploaded files into place and update the settings
		if ($publication->getData('coverImage')) {
			$userId = $request->getUser() ? $request->getUser()->getId() : null;

			$submissionContext = Application::get()->getRequest()->getContext();
			if ($submissionContext->getId() !== $submission->getData('contextId')) {
				$submissionContext = Services::get('context')->get($submission->getData('contextId'));
			}

			$supportedLocales = $submissionContext->getSupportedLocales();
			foreach ($supportedLocales as $localeKey) {
				if (!array_key_exists($localeKey, $publication->getData('coverImage'))) {
					continue;
				}
				$value[$localeKey] = $this->_saveFileParam($publication, $submission, $publication->getData('coverImage', $localeKey), 'coverImage', $userId, $localeKey, true);
			}

			$publication = $this->edit($publication, ['coverImage' => $value], $request);
		}

		HookRegistry::call('Publication::add', [$publication, $request]);

		// Update a submission's status based on the status of its publications
		$submission = Services::get('submission')->updateStatus($submission);

		return $publication;
	}

	/**
	 * Create a new version of a publication
	 *
	 * Make a copy of an existing publication, without the datePublished,
	 * and make copies of all associated objects.
	 *
	 * @param Publication $publication The publication to copy
	 * @param Request
	 * @return Publication The new publication
	 */
	public function version($publication, $request) {
		$newPublication = clone $publication;
		$newPublication->setData('id', null);
		$newPublication->setData('datePublished', '');
		$newPublication->setData('status', STATUS_QUEUED);
		$newPublication->setData('version', $publication->getData('version') + 1);
		$newPublication->stampModified();
		$newPublication = $this->add($newPublication, $request);

		$authors = $publication->getData('authors');
		if (!empty($authors)) {
			foreach ($authors as $author) {
				$newAuthor = clone $author;
				$newAuthor->setData('id', null);
				$newAuthor->setData('publicationId', $newPublication->getId());
				$newAuthor = Services::get('author')->add($newAuthor, $request);

				if ($author->getId() === $publication->getData('primaryContactId')) {
					$newPublication = $this->edit($newPublication, ['primaryContactId' => $newAuthor->getId()], $request);
				}
			}
		}

		if (!empty($newPublication->getData('citationsRaw'))) {
			DAORegistry::getDAO('CitationDAO')->importCitations($newPublication->getId(), $newPublication->getData('citationsRaw'));
		}

		$newPublication = $this->get($newPublication->getId());

		HookRegistry::call('Publication::version', [&$newPublication, $publication, $request]);

		$submission = Services::get('submission')->get($newPublication->getData('submissionId'));
		import('lib.pkp.classes.log.SubmissionLog');
		import('classes.log.SubmissionEventLogEntry');
		SubmissionLog::logEvent(Application::get()->getRequest(), $submission, SUBMISSION_LOG_CREATE_VERSION, 'publication.event.versionCreated');

		return $newPublication;
	}

	/**
	 * @copydoc \PKP\Services\EntityProperties\EntityWriteInterface::edit()
	 */
	public function edit($publication, $params, $request) {
		$submission = Services::get('submission')->get($publication->getData('submissionId'));

		// Move uploaded files into place and update the params
		if (array_key_exists('coverImage', $params)) {
			$userId = $request->getUser() ? $request->getUser()->getId() : null;

			$submissionContext = Application::get()->getRequest()->getContext();
			if ($submissionContext->getId() !== $submission->getData('contextId')) {
				$submissionContext = Services::get('context')->get($submission->getData('contextId'));
			}

			$supportedLocales = $submissionContext->getSupportedLocales();
			foreach ($supportedLocales as $localeKey) {
				if (!array_key_exists($localeKey, $params['coverImage'])) {
					continue;
				}
				$params['coverImage'][$localeKey] = $this->_saveFileParam($publication, $submission, $params['coverImage'][$localeKey], 'coverImage', $userId, $localeKey, true);
			}
		}

		$newPublication = DAORegistry::getDAO('PublicationDAO')->newDataObject();
		$newPublication->_data = array_merge($publication->_data, $params);
		$newPublication->stampModified();

		HookRegistry::call('Publication::edit', [$newPublication, $publication, $params, $request]);

		DAORegistry::getDAO('PublicationDAO')->updateObject($newPublication);
		$newPublication = $this->get($newPublication->getId());

		// Parse the citations
		if (array_key_exists('citationsRaw', $params)) {
			DAORegistry::getDAO('CitationDAO')->importCitations($newPublication->getId(), $newPublication->getData('citationsRaw'));
		}

		// Log an event when publication data is updated
		import('lib.pkp.classes.log.SubmissionLog');
		import('classes.log.SubmissionEventLogEntry');
		SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_METADATA_UPDATE, 'submission.event.general.metadataUpdated');

		return $newPublication;
	}

	/**
	 * Publish a publication or schedule it for publication at a
	 * future date
	 *
	 * @param Publication $publication
	 * @return Publication
	 */
	public function publish($publication) {
		$newPublication = clone $publication;

		if (!$newPublication->getData('datePublished')) {
			$newPublication->setData('datePublished', Core::getCurrentDate());
		}

		if (strtotime($newPublication->getData('datePublished')) <= strtotime(Core::getCurrentDate())) {
			$newPublication->setData('status', STATUS_PUBLISHED);
		} else {
			$newPublication->setData('status', STATUS_SCHEDULED);
		}

		$newPublication->stampModified();

		HookRegistry::call('Publication::publish::before', [$newPublication, $publication]);

		DAORegistry::getDAO('PublicationDAO')->updateObject($newPublication);

		$newPublication = $this->get($newPublication->getId());
		$submission = Services::get('submission')->get($newPublication->getData('submissionId'));

		// Update a submission's status based on the status of its publications
		if ($newPublication->getData('status') !== $publication->getData('status')) {
			$submission = Services::get('submission')->updateStatus($submission);
		}

		// Log an event when publication is published. Adjust the message depending
		// on whether this is the first publication or a subsequent version
		if (count($submission->getData('publications')) > 1) {
			$msg = $newPublication->getData('status') === STATUS_SCHEDULED ? 'publication.event.versionScheduled' : 'publication.event.versionPublished';
		} else {
			$msg = $newPublication->getData('status') === STATUS_SCHEDULED ? 'publication.event.scheduled' : 'publication.event.published';
		}
		import('lib.pkp.classes.log.SubmissionLog');
		import('classes.log.SubmissionEventLogEntry');
		SubmissionLog::logEvent(Application::get()->getRequest(), $submission, SUBMISSION_LOG_METADATA_PUBLISH, $msg);

		HookRegistry::call('Publication::publish', [$newPublication, $publication, $submission]);

		// Update the search index.
		if ($newPublication->getData('status') === STATUS_PUBLISHED) {
			Application::getSubmissionSearchIndex()->submissionMetadataChanged($submission);
			Application::getSubmissionSearchIndex()->submissionFilesChanged($submission);
			Application::getSubmissionSearchDAO()->flushCache();
			Application::getSubmissionSearchIndex()->submissionChangesFinished();
		}

		return $newPublication;
	}

	/**
	 * Unpublish a publication that has already been published
	 *
	 * @param Publication $publication
	 * @return Publication
	 */
	public function unpublish($publication) {
		$newPublication = clone $publication;
		$newPublication->setData('status', STATUS_QUEUED);
		$newPublication->stampModified();

		HookRegistry::call('Publication::unpublish::before', [$newPublication, $publication]);

		DAORegistry::getDAO('PublicationDAO')->updateObject($newPublication);
		$newPublication = $this->get($newPublication->getId());
		$submission = Services::get('submission')->get($newPublication->getData('submissionId'));

		// Update a submission's status based on the status of its publications
		if ($newPublication->getData('status') !== $publication->getData('status')) {
			$submission = Services::get('submission')->updateStatus($submission);
		}

		// Log an event when publication is unpublished. Adjust the message depending
		// on whether this is the first publication or a subsequent version
		$msg = count($submission->getData('publications')) > 1 ? 'publication.event.versionUnpublished' : 'publication.event.unpublished';
		import('lib.pkp.classes.log.SubmissionLog');
		import('classes.log.SubmissionEventLogEntry');
		SubmissionLog::logEvent(Application::get()->getRequest(), $submission, SUBMISSION_LOG_METADATA_UNPUBLISH, $msg);

		HookRegistry::call('Publication::unpublish', [$newPublication, $publication, $submission]);

		// Update the metadata in the search index.
		if ($submission->getData('status') !== STATUS_PUBLISHED) {
			Application::getSubmissionSearchIndex()->deleteTextIndex($submission->getId());
			Application::getSubmissionSearchIndex()->clearSubmissionFiles($submission);
		} else {
			Application::getSubmissionSearchIndex()->submissionMetadataChanged($submission);
			Application::getSubmissionSearchIndex()->submissionFilesChanged($submission);
		}
		Application::getSubmissionSearchDAO()->flushCache();
		Application::getSubmissionSearchIndex()->submissionChangesFinished();

		return $newPublication;
	}

	/**
	 * @copydoc \PKP\Services\EntityProperties\EntityWriteInterface::delete()
	 */
	public function delete($publication) {
		HookRegistry::call('Publication::delete::before', [$publication]);

		DAORegistry::getDAO('PublicationDAO')->deleteObject($publication);

		// Update a submission's status based on the status of its remaining publications
		$submission = Services::get('submission')->get($publication->getData('submissionId'));
		$submission = $submission = Services::get('submission')->updateStatus($submission);

		HookRegistry::call('Publication::delete', [$publication]);
	}

	/**
	 * Handle a publication setting for an uploaded file
	 *
	 * - Moves the temporary file to the public directory
	 * - Resets the param value to what is expected to be stored in the db
	 * - If a null value is passed, deletes any existing file
	 *
	 * This method is protected because all operations which edit publications should
	 * go through the add and edit methods in order to ensure that
	 * the appropriate hooks are fired.
	 *
	 * @param Publication $publication The publication being edited
	 * @param Submission $submission The submission this publication is part of
	 * @param mixed $value The param value to be saved. Contains the temporary
	 *  file ID if a new file has been uploaded.
	 * @param string $settingName The name of the setting to save, typically used
	 *  in the filename.
	 * @param integer $userId ID of the user who owns the temporary file
	 * @param string $localeKey Optional. Pass if the setting is multilingual
	 * @param boolean $isImage Optional. For image files which include alt text in value
	 * @return string|array|null New param value or null on failure
	 */
	protected function _saveFileParam($publication, $submission, $value, $settingName, $userId, $localeKey = '', $isImage = false) {

		// If the value is null, delete any existing unused file in the system
		if (is_null($value)) {
			$oldPublication = Services::get('publication')->get($publication->getId());
			$oldValue = $oldPublication->getData($settingName, $localeKey);
			$fileName = $oldValue['uploadName'];
			if ($fileName) {
				// File may be in use by other publications
				$fileInUse = false;
				foreach ((array) $submission->getData('publications') as $iPublication) {
					if ($publication->getId() === $iPublication->getId()) {
						continue;
					}
					$iValue = $iPublication->getData($settingName, $localeKey);
					if (!empty($iValue['uploadName']) && $iValue['uploadName'] === $fileName) {
						$fileInUse = true;
						continue;
					}
				}
				if (!$fileInUse) {
					import('classes.file.PublicFileManager');
					$publicFileManager = new \PublicFileManager();
					$publicFileManager->removeContextFile($submission->getData('contextId'), $fileName);
				}
			}
			return null;
		}

		// Get uploaded file to move
		if ($isImage) {
			if (empty($value['temporaryFileId'])) {
				return $value; // nothing to upload
			}
			$temporaryFileId = (int) $value['temporaryFileId'];
		} else {
			if (!ctype_digit($value)) {
				return $value; // nothing to upload
			}
			$temporaryFileId = (int) $value;
		}

		// Get the submission context
		$submissionContext = Application::get()->getRequest()->getContext();
		if ($submissionContext->getId() !== $submission->getData('contextId')) {
			$submissionContext = Services::get('context')->get($submission->getData('contextId'));
		}

		import('lib.pkp.classes.file.TemporaryFileManager');
		$temporaryFileManager = new \TemporaryFileManager();
		$temporaryFile = $temporaryFileManager->getFile($temporaryFileId, $userId);
		$fileNameBase = join('_', ['submission', $submission->getId(), $publication->getId(), $settingName]); // eg - submission_1_1_coverImage
		$fileName = Services::get('context')->moveTemporaryFile($submissionContext, $temporaryFile, $fileNameBase, $userId, $localeKey);

		if ($fileName) {
			if ($isImage) {
				return [
					'altText' => !empty($value['altText']) ? $value['altText'] : '',
					'dateUploaded' => \Core::getCurrentDate(),
					'uploadName' => $fileName,
				];
			} else {
				return $fileName;
			}
		}

		return false;
	}
}
