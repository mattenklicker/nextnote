<?php
/**
 * Nextcloud - NextNote
 *
 * @copyright Copyright (c) 2015, Ben Curtis <ownclouddev@nosolutions.com>
 * @copyright Copyright (c) 2017, Sander Brand (brantje@gmail.com)
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\NextNote\Controller;

use OCA\NextNote\Service\NextNoteService;
use OCA\NextNote\Utility\NotFoundJSONResponse;
use \OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\ILogger;
use \OCP\IRequest;
use \OCA\NextNote\Lib\Backend;


class NextNoteApiController extends ApiController {

	private $logger;
	private $config;
	private $noteService;

	public function __construct($appName, IRequest $request, ILogger $logger, IConfig $config, NextNoteService $noteService) {
		parent::__construct($appName, $request);
		$this->logger = $logger;
		$this->config = $config;
		$this->noteService = $noteService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @TODO Add etag / lastmodified
	 * @param int|bool $deleted
	 * @param string|bool $group
	 * @return JSONResponse
	 */
	public function index($deleted = false, $group = false) {
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		//$this->logger->error("NextNoteApiController::index for " . $uid, array('app' => 'NextNote'));
		
		$results = $this->noteService->findNotesFromUser($uid, $deleted, $group, $this->logger);
		return new JSONResponse($results);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @TODO Add etag / lastmodified
	 */
	public function get($id) {
		//$this->logger->error("NextNoteApiController::get($id", array('app' => 'NextNote'));
		
		$results = $this->noteService->find($id, null, false, $this->logger);
		//@TODO for sharing add access check
		if (!$results) {
			return new NotFoundJSONResponse();
		}
		return new JSONResponse($results);
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function create($title, $grouping, $note) {
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		//$this->logger->error("NextNoteApiController::create($title, $grouping for " . $uid, array('app' => 'NextNote'));

		if($title == "" || !$title){
			return new JSONResponse(['error' => 'title is missing']);
		}
		$note = [
			'title' => $title,
			'name' => $title,
			'grouping' => $grouping,
			'note' => $note
		];
		$result = $this->noteService->create($note, $uid, $this->logger);
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update($id, $title, $grouping, $content, $deleted) {
		//$this->logger->error("NextNoteApiController::update($id, $title, $grouping", array('app' => 'NextNote'));

		if($title == "" || !$title){
			return new JSONResponse(['error' => 'title is missing']);
		}

		$note = [
			'id' => $id,
			'title' => $title,
			'name' => $title,
			'grouping' => $grouping,
			'note' => $content,
            'deleted' => $deleted
		];
        //@TODO for sharing add access check
		$entity = $this->noteService->find($id, null, false, $this->logger);
		if (!$entity) {
			return new NotFoundJSONResponse();
		}

		$results = $this->noteService->update($note, $this->logger);
		return new JSONResponse($results);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function delete($id) {
		//$this->logger->error("NextNoteApiController::delete($id", array('app' => 'NextNote'));

		$entity = $this->noteService->find($id, null, false, $this->logger);
		if (!$entity) {
			return new NotFoundJSONResponse();
		}
        //@TODO for sharing add access check
		$this->noteService->delete($id, null, $this->logger);
		$result = (object) ['success' => true];
		return new JSONResponse($result);
	}

}
