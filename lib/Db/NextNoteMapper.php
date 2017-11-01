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

namespace OCA\NextNote\Db;

use \OCA\NextNote\Utility\Utils;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use OCP\AppFramework\Db\Mapper;
use OCP\ILogger;

class NextNoteMapper extends Mapper {
	private $utils;
	private $maxNoteFieldLength = 2621440;

	public function __construct(IDBConnection $db, Utils $utils) {
		parent::__construct($db, 'ownnote');
		$this->utils = $utils;
	}

	/* TODO: change to config parameter */
	const FOLDER = 'Notes';
	
	private function startsWith($haystack, $needle) {
		return $needle === "" || strripos($haystack, $needle, -strlen($haystack)) !== FALSE;
	}

	private function endsWith($string, $test) {
		$strlen = strlen($string);
		$testlen = strlen($test);
		if ($testlen > $strlen) return false;
		return substr_compare($string, $test, $strlen - $testlen, $testlen, true) === 0;
	}

	private function getGroupAndNameForFile($filename) {
		$name = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename);
		$group = "";
		if (substr($name,0,1) == "[") {
			$end = strpos($name, ']');
			$group = substr($name, 1, $end-1);	
			$name = substr($name, $end+1, strlen($name)-$end+1);
			$name = trim($name);
		}
		return array ($group, $name);
	}

    /**
     * @param $note_id
     * @param null $user_id
     * @param int|bool $deleted
     * @return NextNote if not found
     */
	public function find($note_id, $user_id = null, $deleted = false, ILogger $logger) {
		//$logger->error("NextNoteMapper::find($note_id", array('app' => 'NextNote'));
		/* TODO: better way to find note in file system based on note_id? */
		$results = [];
		
		// build array from file list
		$count = 0;
		if ($listing = \OC\Files\Filesystem::opendir(NextNoteMapper::FOLDER)) {
			if (!$listing) {
				throw new \Exception( "ERROR: Error listing directory." );
				exit;
			}
			while (($file = readdir($listing)) !== false) {
				//$logger->error("checking file " . $file, array('app' => 'NextNote'));
				$tmpfile = $file;
				if ($tmpfile == "." || $tmpfile == "..") continue;
				if (!$this->endsWith($tmpfile, ".htm")) continue;
				if ($info = \OC\Files\Filesystem::getFileInfo(NextNoteMapper::FOLDER."/".$tmpfile)) {
					// if count is the note_id, we have found it
					//$logger->error("checking count " . $count, array('app' => 'NextNote'));
					if ($count == $note_id) {
						//$logger->error("found file " . $note_id, array('app' => 'NextNote'));
						// Separate the name and group name
						list ($fgroup, $fname) = $this->getGroupAndNameForFile($tmpfile);

						// populate result array as for database
						$item = [];
						$item['id'] = $count;
						$item['name'] = $fname;
						$item['grouping'] = $fgroup;
						$item['mtime'] = $info['mtime'];
						$item['deleted'] = 0;
						$item['uid'] = $user_id;
						$note = $this->makeEntityFromFile($item);
						$note->setNote(\OC\Files\Filesystem::file_get_contents(NextNoteMapper::FOLDER."/".$tmpfile));
						
						$results[] = $note;
						
						// done - lets get out of here
						break;
					}

					$count++;
				}
			}
		}

		return array_shift($results);
	}

	/**
	 * @param $userId
	 * @param int|bool $deleted
	 * @param string|bool $group
	 * @return NextNote[] if not found
	 */
	public function findNotesFromUser($userId, $deleted = 0, $group = false, ILogger $logger) {
		//$logger->error("NextNoteMapper::findNotesFromUser($userId", array('app' => 'NextNote'));
		/* TODO: what would the params mean for file system?
			group -> Filter notes by group name
			deleted -> 1 to get deleted, 0 or omit to show normal notes.
		*/
		$results = [];
		
		// build array from file list
		$count = 0;
		if ($listing = \OC\Files\Filesystem::opendir(NextNoteMapper::FOLDER)) {
			if (!$listing) {
				throw new \Exception( "ERROR: Error listing directory." );
				exit;
			}
			while (($file = readdir($listing)) !== false) {
				$tmpfile = $file;
				if ($tmpfile == "." || $tmpfile == "..") continue;
				if (!$this->endsWith($tmpfile, ".htm")) continue;
				if ($info = \OC\Files\Filesystem::getFileInfo(NextNoteMapper::FOLDER."/".$tmpfile)) {
					// Separate the name and group name
					list ($fgroup, $fname) = $this->getGroupAndNameForFile($tmpfile);

					// populate result array as for database
					$item = [];
					$item['id'] = $count;
					$item['name'] = $fname;
					$item['grouping'] = $fgroup;
					$item['mtime'] = $info['mtime'];
					$item['deleted'] = 0;
					$item['uid'] = $userId;
					$note = $this->makeEntityFromFile($item);
					
					$results[] = $note;
					$count++;
				}
			}
		}

		return $results;
	}

	

	/**
	 * Creates a note
	 *
	 * @param NextNote $note
	 * @return NextNote|Entity
	 * @internal param $userId
	 */
	public function create($note, ILogger $logger) {
		$name = $note->getName();
		$group = $note->getGrouping();
		//$logger->error("NextNoteMapper::create for " . $name . " - " . $group . " - " . $note->getNote(), array('app' => 'NextNote'));
		
		$tmpfile = NextNoteMapper::FOLDER."/".$name.".htm";
		if ($group != '')
			$tmpfile = NextNoteMapper::FOLDER."/[".$group."] ".$name.".htm";
		
		// create file if it doesn't already exist
		if (!\OC\Files\Filesystem::file_exists($tmpfile)) {
			\OC\Files\Filesystem::touch($tmpfile);
		}

		// save note content to file
		\OC\Files\Filesystem::file_put_contents($tmpfile, $note->getNote());
		if ($info = \OC\Files\Filesystem::getFileInfo($tmpfile)) {
			$note->setMtime($info['mtime']);
		}

		return $note;
	}

	/**
	 * Update note
	 *
	 * @param NextNote $note
	 * @return NextNote|Entity
	 */
	public function updateNote($note, ILogger $logger) {
		$name = $note->getName();
		$group = $note->getGrouping();
		//$logger->error("NextNoteMapper::updateNote for " . $name . " - " . $group . " - " . $note->getNote(), array('app' => 'NextNote'));

		$tmpfile = NextNoteMapper::FOLDER."/".$name.".htm";
		if ($group != '')
			$tmpfile = NextNoteMapper::FOLDER."/[".$group."] ".$name.".htm";

		// save note content to file
		\OC\Files\Filesystem::file_put_contents($tmpfile, $note->getNote());
		if ($info = \OC\Files\Filesystem::getFileInfo($tmpfile)) {
			$note->setMtime($info['mtime']);
		}

		return $note;
	}

	/**
	 * @param NextNote $note
	 * @return bool
	 */
	public function deleteNote(NextNote $note, ILogger $logger) {
		$name = $note->getName();
		$group = $note->getGrouping();
		//$logger->error("NextNoteMapper::deleteNote for " . $name . " - " . $group, array('app' => 'NextNote'));

		$tmpfile = NextNoteMapper::FOLDER."/".$name.".htm";
		if ($group != '')
			$tmpfile = NextNoteMapper::FOLDER."/[".$group."] ".$name.".htm";

		if (\OC\Files\Filesystem::file_exists($tmpfile))
			\OC\Files\Filesystem::unlink($tmpfile);

		return true;
	}

	/**
	 * @param $arr
	 * @return NextNote
	 */
	public function makeEntityFromFile($arr) {
		$note = new NextNote();
		$note->setId($arr['id']);
		$note->setName($arr['name']);
		$note->setGrouping($arr['grouping']);
		$note->setMtime($arr['mtime']);
		$note->setDeleted($arr['deleted']);
		$note->setUid($arr['uid']);
		return $note;
	}
}
