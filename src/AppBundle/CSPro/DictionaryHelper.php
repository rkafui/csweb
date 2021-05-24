<?php

namespace AppBundle\CSPro;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\VectorClock;
use AppBundle\CSPro\SyncHistoryEntry;
use AppBundle\CSPro\Dictionary;
use AppBundle\CSPro\Data;

class DictionaryHelper {

    private $logger;
    private $pdo;
    private $serverDeviceId;

    public function __construct(PdoHelper $pdo, LoggerInterface $logger, $serverDeviceId) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->serverDeviceId = $serverDeviceId;
    }

    public function tableExists($table) {
        try {
            $result = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception $e) {
            return false;
        }
        // ALW - By default PDO will not throw exceptions, so check result also.
        return $result !== false;
    }

    function dictionaryExists($dictName) {
        $stm = 'SELECT id  FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = array(
            'dictName' => array(
                'dictName' => $dictName
            )
        );
        return $this->pdo->fetchValue($stm, $bind);
    }

    function checkDictionaryExists($dictName) {
        if ($this->dictionaryExists($dictName) == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }
    }

    function loadDictionary($dictName) {
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $bFound = false;
            $dict = apcu_fetch($dictName, $bFound);
            if ($bFound == true)
                return $dict;
        }
        $stm = 'SELECT dictionary_full_content FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = array(
            'dictName' => array(
                'dictName' => $dictName
            )
        );
        $dictText = $this->pdo->fetchValue($stm, $bind);
        if ($dictText == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }

        $parser = new \AppBundle\CSPro\Dictionary\Parser ();
        try {
            $dict = $parser->parseDictionary($dictText);
            if (extension_loaded('apcu') && ini_get('apc.enabled')) {
                apcu_store($dictName, $dict);
            }
            return $dict;
        } catch (\Exception $e) {
            $this->logger->error('Failed loading dictionary: ' . $dictName, array("context" => (string) $e));
            throw new HttpException(400, 'dictionary_invalid: ' . $e->getMessage());
        }
    }

    function createDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $dictLabel = $dict->getLabel();

        if ($this->dictionaryExists($dictName)) {
            $csproResponse->setError(405, 'dictionary_exists', "Dictionary {$dictName} already exists.");
            $csproResponse->setStatusCode(405);
            return;
        }
        // Make sure dict name contains only valid chars (letters, numbers and _)
        // This matches CSPro valid names and protects against SQL injection.
        // Note that PDO does not support using a prepared statement with table name as
        // parameter.
        if (!preg_match('/\A[A-Z0-9_]*\z/', $dictName)) {
            $csproResponse->setError(400, 'dictionary_name_invalid', "{$dictName} is not a valid dictionary name.");
            $csproResponse->setStatusCode(400);
            return;
        }

        $sql = <<<EOT
	CREATE TABLE IF NOT EXISTS `$dictName` (
        `id` int(11) unsigned NOT NULL AUTO_INCREMENT UNIQUE,
	`guid` binary(16) NOT NULL,
	`caseids` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
	`label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`questionnaire` BLOB NOT NULL,
	`revision` int(11) unsigned NOT NULL,
	`deleted` tinyint(1) unsigned NOT NULL DEFAULT '0',
	`verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `clock` text COLLATE utf8mb4_unicode_ci NOT NULL,
	`modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	`created_time` timestamp DEFAULT '1971-01-01 00:00:00',
	partial_save_mode varchar(6) NULL,
	partial_save_field_name varchar(255) COLLATE utf8mb4_unicode_ci NULL,
	partial_save_level_key varchar(255) COLLATE utf8mb4_unicode_ci NULL,
	partial_save_record_occurrence SMALLINT NULL,
	partial_save_item_occurrence SMALLINT NULL,
	partial_save_subitem_occurrence SMALLINT NULL,
EOT;


        $trigName = 'tr_' . $dictName;
        $sql .= <<<EOT
	PRIMARY KEY (`guid`),
  	KEY `revision` (`revision`),
  	KEY `caseids` (`caseids`),
  	KEY `deleted` (`deleted`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
	CREATE TRIGGER  $trigName BEFORE INSERT ON  $dictName FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
        $this->pdo->exec($sql);

        $stmt = $this->pdo->prepare("INSERT INTO cspro_dictionaries (`dictionary_name`,
								`dictionary_label`, `dictionary_full_content`) VALUES (:name,:label,:content)");
        $stmt->bindParam(':name', $dictName);
        $stmt->bindParam(':label', $dictLabel);
        $stmt->bindParam(':content', $dictContent);
        $stmt->execute();

        $this->createDictionaryNotes($dictName, $csproResponse);
        if ($csproResponse->getStatusCode() != 200) {
            $this->logger->debug('createDictionaryNotes: getStatusCode.' . $csproResponse->getStatusCode());
            return $csproResponse; // failed to create notes.
        }

        $csproResponse = $csproResponse->setContent(json_encode(array(
            "code" => 200,
            "description" => 'Success'
        )));
        $csproResponse->setStatusCode(200);
    }

    function createDictionaryNotes($dictName, &$csproResponse) {
        $notesTableName = $dictName . '_notes';
        // check if the notes table if it does not exist
        $sql = <<<EOT
	CREATE TABLE IF NOT EXISTS `$notesTableName` (
	`id` SERIAL PRIMARY KEY ,
	`case_guid` binary(16)  NOT NULL,
	`operator_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`field_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`level_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`record_occurrence` SMALLINT NOT NULL,
	`item_occurrence`  SMALLINT NOT NULL,
    `subitem_occurrence` SMALLINT NOT NULL,
	`content` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
	`modified_time` datetime NOT NULL,
	`created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (`case_guid`)
        REFERENCES `$dictName`(`guid`)
		ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
        try {
            $this->pdo->exec($sql);
        } catch (\Exception $e) {
            $this->logger->error('Failed creating dictionary notes: ' . $notesTableName, array("context" => (string) $e));
            $csproResponse->setError(405, 'notes_table_createfail', $e->getMessage());
        }
    }

    function updateExistingDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $dictLabel = $dict->getLabel();
        try {
            // Update dictionaries table with new label and content
            $stmt = $this->pdo->prepare("UPDATE cspro_dictionaries SET `dictionary_label`=:label, `dictionary_full_content`=:content WHERE `dictionary_name`=:name");
            $stmt->bindParam(':name', $dictName);
            $stmt->bindParam(':label', $dictLabel);
            $stmt->bindParam(':content', $dictContent);
            $stmt->execute();

            $csproResponse = $csproResponse->setContent(json_encode(array(
                "code" => 200,
                "description" => 'Success'
            )));
            $csproResponse->setStatusCode(200);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update dictionary: ' . $dictName, array("context" => (string) $e));
            $csproResponse->setError(500, 'dictionary_update_fail', $e->getMessage());
        }
    }

    function getLastSyncForDevice($dictName, $device) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, dictionary_name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE device=:device AND dictionary_name = :dictName ORDER BY revision DESC LIMIT 1';
            $bind = array(
                'device' => array(
                    'device' => $device
                ),
                'dictName' => array(
                    'dictName' => $dictName
                )
            );
            return $this->pdo->fetchObject($stm, $bind, 'AppBundle\CSPro\SyncHistoryEntry');
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLastSyncForDevice ' . $dictName, 0, $e);
        }
    }

    function getSyncHistoryByRevisionNumber($dictName, $revisionNumber) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, dictionary_name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE revision = :revisionNumber AND dictionary_name = :dictName';
            $bind = array(
                'revisionNumber' => array(
                    'revisionNumber' => $revisionNumber
                ),
                'dictName' => array(
                    'dictName' => $dictName
                )
            );
            return $this->pdo->fetchObject($stm, $bind, 'AppBundle\CSPro\SyncHistoryEntry');
        } catch (\Exception $e) {
            throw new \Exception('Failed in getSyncHistoryByRevisionNumber ' . $dictName, 0, $e);
        }
    }

    // is universe more restrictive Or same as the previous revision
    function isUniverseMoreRestrictiveOrSame($currentUniverse, $lastRevisionUniverse) {
        // if the current universe is a sub string of last revision universe, they are not the same
        if ($currentUniverse === $lastRevisionUniverse) {
            return true;
        } else {
            return (strlen($currentUniverse) >= strlen($lastRevisionUniverse)) && substr($currentUniverse, 0, strlen($lastRevisionUniverse)) === $lastRevisionUniverse;
        }
    }

    // Add a new sync history entry to database and return the revision number
    function addSyncHistoryEntry($deviceId, $userName, $dictName, $direction, $universe = "") {
        //SELECT dictionary ID 
        $dictId = $this->dictionaryExists($dictName);
        if ($dictId == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }
        // insert a row into the sync history with the new version
        $stm = 'INSERT INTO cspro_sync_history (`device` , `username`, `dictionary_id`, `direction`, `universe`)
			 VALUES (:deviceId, :userName, :dictionary_id, :direction, :universe)';
        $bind = array(
            'deviceId' => array(
                'deviceId' => $deviceId
            ),
            'userName' => array(
                'userName' => $userName
            ),
            'dictName' => array(
                'dictName' => $dictName
            ),
            'universe' => array(
                'universe' => $universe
            ),
            'direction' => array(
                'direction' => $direction
            ),
            'dictionary_id' => array(
                'dictionary_id' => $dictId
            )
        );
        try {
            $this->pdo->perform($stm, $bind);
            $lastRevisionId = $this->pdo->lastInsertId();

            return $lastRevisionId;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw new \Exception('Failed to addSyncHistoryEntry ' . $dictName, 0, $e);
        }
    }

    //Delete Sync history entry
    function deleteSyncHistoryEntry($revision) {
        // delete entry in sync history for the revision
        $stm = $stm = 'DELETE FROM `cspro_sync_history` WHERE revision=:revision';
        $bind = array(
            'revision' => array(
                'revision' => $revision
            )
        );
        $deletedSyncHistoryCount = $this->pdo->fetchAffected($stm, $bind);
        $this->logger->debug('Deleted # ' . $deletedSyncHistoryCount . ' Sync History Entry revision: ' . $revision);
        return $deletedSyncHistoryCount;
    }

    // Select all the cases sent by the client that exist on the server
    function getLocalServerCaseList($dictName, $caseList) {
        if (count($caseList) == 0)
            return null;

        try {
            $this->checkDictionaryExists($dictName);
            // Select all the cases sent by the client that exist on the server
            $stm = 'SELECT  LCASE(CONCAT_WS("-", LEFT(HEX(guid), 8), MID(HEX(guid), 9,4), MID(HEX(guid), 13,4), MID(HEX(guid), 17,4), RIGHT(HEX(guid), 12))) as id,
			clock
			FROM ' . $dictName;
            $insertData = array();
            $ids = array();
            $strWhere = '';
            $n = 1;
            foreach ($caseList as $row) {
                $insertData [] = 'UNHEX(REPLACE(:guid' . $n . ',"-",""))';
                $ids ['guid' . $n] = $row ['id'];
                $n++;
            }
            // do bind values for the where condition
            if (count($insertData) > 0) {
                $inQuery = implode(',', $insertData);
                $stm .= ' WHERE `guid` IN (' . $inQuery . ');';
            }

            $stmt = $this->pdo->prepare($stm);

            $stmt->execute($ids);
            $result = $stmt->fetchAll();

            $localServerCases = array();
            foreach ($result as $row) {
                $localServerCases [$row ['id']] = $row;
            }
            return $localServerCases;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLocalServerCaseList ' . $dictName, 0, $e);
        }
    }

    function reconcileCases(&$caseList, $localServerCases) {
        // fix the caseList.
        $defaultServerClock = new VectorClock(null);
        $defaultServerClock->setVersion($this->serverDeviceId, 1);
        $defaultJSONArrayServerClock = json_decode($defaultServerClock->getJSONClockString(), true);

        foreach ($caseList as $i => &$row) {
            $serverCase = isset($localServerCases, $localServerCases [$row ['id']]) ? $localServerCases [$row ['id']] : null;
            if (isset($serverCase)) {
                $strJSONServerClock = $serverCase ['clock'];
                $serverClock = new VectorClock(json_decode($strJSONServerClock, true));
                $clientClock = new VectorClock($row ['clock']); // the caselist row has decoded json array for the clock
                // compare clocks
                if ($clientClock->IsLessThan($serverClock)) {
                    // Local server case is more recent, do not update
                    // Remove this case from the $caseList.
                    // echo 'client clock less than server clock';
                    unset($caseList [$i]);
                    continue;
                } else if ($serverClock->IsLessThan($clientClock)) {
                    // Update is newer, replace the local server case
                    // do nothing. $row in the caseList will update the server case. client clock will be updated on the server.
                    // echo 'server clock less than client clock';
                } else if (!$serverClock->IsEqual($clientClock)) {
                    // Conflict - neither clock is greater - always use the client case and merge the clocks
                    // merge the clocks
                    // echo 'conflict! ';
                    $serverClock->merge($clientClock);
                    // update the case using the merged clock
                    $row ['clock'] = json_decode($serverClock->getJSONClockString(), true);
                }
            }
            if (count($row ['clock']) == 0) { // set the server default clock for updates or inserts if the clock sent is empty
                $row ['clock'] = $defaultJSONArrayServerClock;
            }
        }
        unset($row);
        // remove cases that have been discarded.
        $caseList = array_filter($caseList);
    }

    function prepareJSONForInsertOrUpdate($dictName, &$caseList) {
        // for each row get the record list array to multi-line string for the questionnaire data
        // Get the clocks for the cases on the server.
        // get local server cases
        $localServerCases = $this->getLocalServerCaseList($dictName, $caseList);
        // reconcile server cases with the client
        $this->reconcileCases($caseList, $localServerCases);
        foreach ($caseList as &$row) {
            if (isset($row['data'])) {
                $row ['data'] = implode("\n", $row ['data']); // for pre 7.5 blob data
            } else {
                //https://stackoverflow.com/questions/24607493/mysql-compress-vs-php-gzcompress
                //php gzcompress and MySQL uncompress differ in the header the static header below works fine 
                //with 4 bytes  zlib.org/rfc-gzip.html, with header 1F 8B 08 00 = ID1|ID2|CM |FLG
                //if this has issues in future use the commented line below which adds the leading 4 bytes with original size of the string
//                  $insertData ['questionnaire' . $n] = pack('V', mb_strlen($row ['level-1'])) . gzcompress($row ['level-1']); // CSPro 7.5+
                $row ['level-1'] = "\x1f\x8b\x08\x00" . gzcompress($row ['level-1']);
            }
            $row ['deleted'] = ( isset($row ['deleted']) && (1 == $row ['deleted'])) ? true : false;
            $row ['verified'] = (isset($row ['verified']) && (1 == $row ['verified'])) ? true : false;
            $row ['clock'] = json_encode($row ['clock']); // convert the json array clock to json string
            if (!isset($row['label'])) // allow null labels
                $row['label'] = '';
        }
        unset($row);
    }

    function isJsonQuestionnaire($case) {
        $len = strlen($case);
        return $len >= 2 && $case[0] == '{' && $case[$len - 1] == '}';
    }

    function prepareResultSetForJSON(&$caseList) {
        // for each row get the record list array to multi-line string for the questionnaire data
        foreach ($caseList as &$row) {
            unset($row['revision']);
            // Json formatted needs to be under 'level-1' key
            $row['level-1'] = gzuncompress(substr($row['data'], 4));
            unset($row['data']);

            $row ['deleted'] = (1 == $row ['deleted']) ? true : false;
            $row ['verified'] = (1 == $row ['verified']) ? true : false;
            if (isset($row ['partial_save_mode'])) {
                $row ['partialSave'] = array(
                    "mode" => $row ['partial_save_mode'],
                    "field" => array(
                        "name" => $row ['partial_save_field_name'],
                        "levelKey" => $row ['partial_save_level_key'],
                        "recordOccurrence" => intval($row ['partial_save_record_occurrence']),
                        "itemOccurrence" => intval($row ['partial_save_item_occurrence']),
                        "subitemOccurrence" => intval($row ['partial_save_subitem_occurrence'])
                    )
                );
            } else {
                unset($row ['partialSave']);
            }
            // unset partial_save_ ... columns
            unset($row ['partial_save_mode']);
            unset($row ['partial_save_field_name']);
            unset($row ['partial_save_level_key']);
            unset($row ['partial_save_record_occurrence']);
            unset($row ['partial_save_item_occurrence']);
            unset($row ['partial_save_subitem_occurrence']);

            if (empty($row ['clock']))
                $row ['clock'] = array();
            else
                $row ['clock'] = json_decode($row ['clock']);

            if (isset($row ['lastModified'])) {
                $lastModifiedUTC = DateTime::createFromFormat('Y-m-d H:i:s', $row ['lastModified'], new \DateTimeZone("UTC"));
                $row ['lastModified'] = $lastModifiedUTC->format(DateTime::RFC3339);
            }
        }
        unset($row);
    }

}
