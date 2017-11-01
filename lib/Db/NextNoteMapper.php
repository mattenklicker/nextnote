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
		$logger->error("NextNoteMapper::find($note_id", array('app' => 'NextNote'));
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
				throw new \Exception("checking file " . $file);
				$tmpfile = $file;
				if ($tmpfile == "." || $tmpfile == "..") continue;
				if (!$this->endsWith($tmpfile, ".htm")) continue;
				if ($info = \OC\Files\Filesystem::getFileInfo(NextNoteMapper::FOLDER."/".$tmpfile)) {
					// if count is the note_id, we have found it
					if ($count == $note_id) {
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
						$note->setNote(\OC\Files\Filesystem::file_get_contents($tmpfile));
						
						$results[] = $note;
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
		$logger->error("NextNoteMapper::findNotesFromUser($userId", array('app' => 'NextNote'));
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
	public function create($note) {
		$len = mb_strlen($note->getNote());
		$parts = false;
		if ($len > $this->maxNoteFieldLength) {
			$parts = $this->utils->splitContent($note->getNote());
			$note->setNote('');
		}

		$note->setShared(false);
		/**
		 * @var $note NextNote
		 */
		$note = parent::insert($note);

		if ($parts) {
			foreach ($parts as $part) {
				$this->createNotePart($note, $part);
			}
			$note->setNote(implode('', $parts));
		}


		return $note;
	}

	/**
	 * Update note
	 *
	 * @param NextNote $note
	 * @return NextNote|Entity
	 */
	public function updateNote($note) {
		$len = mb_strlen($note->getNote());
		$parts = false;
		$this->deleteNoteParts($note);

		if ($len > $this->maxNoteFieldLength) {
			$parts = $this->utils->splitContent($note->getNote());
			$note->setNote('');
		}
		/**
		 * @var $note NextNote
		 */
		$note = parent::update($note);
		if ($parts) {
			foreach ($parts as $part) {
				$this->createNotePart($note, $part);
			}
			$note->setNote(implode('', $parts));
		}
		return $note;
	}

	/**
	 * @param NextNote $note
	 * @param $content
	 */
	public function createNotePart(NextNote $note, $content) {
		$sql = "INSERT INTO *PREFIX*ownnote_parts VALUES (NULL, ?, ?);";
		$this->execute($sql, array($note->getId(), $content));
	}

	/**
	 * Delete the note parts
	 *
	 * @param NextNote $note
	 */
	public function deleteNoteParts(NextNote $note) {
		$sql = 'DELETE FROM *PREFIX*ownnote_parts where id = ?';
		$this->execute($sql, array($note->getId()));
	}

	/**
	 * Get the note parts
	 *
	 * @param NextNote $note
	 * @return array
	 */
	public function getNoteParts(NextNote $note) {
		$sql = 'SELECT * from *PREFIX*ownnote_parts where id = ?';
		return $this->execute($sql, array($note->getId()))->fetchAll();
	}

	/**
	 * @param NextNote $note
	 * @return bool
	 */
	public function deleteNote(NextNote $note) {
		$this->deleteNoteParts($note);
		parent::delete($note);
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
