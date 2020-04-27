<?php
namespace Stanford\ProjArrest;

use \REDCap;

require_once "emLoggerTrait.php";

class ProjArrest extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $dags;

    public $random_result_field;
    public $random_result_event;
    public $random_result;


    public function __construct() {
		parent::__construct();
	}


    /**
     * SAVE RECORD HOOK
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     */
	public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                                       $survey_hash, $response_id, $repeat_instance) {
        $this->emDebug("Save on $instrument in $event_id");

        if ($this->inRandomEvent($record,$event_id)) {

            // See if we need to update the study id
            $result = $this->checkStudyId($record, $group_id);
            $this->emDebug($result);

            // See if we need to update the pharma alias
            $result = $this->checkPharmaAlias($record, $group_id);
            $this->emDebug($result);

        }

    }


    /**
     * Assigns a study id if record is randomized and missing a study id
     * Should only be called if the current event_id is the randomiation event
     * @param $record
     * @param $event_id
     * @param $group_id
     * @return bool|string
     */
    public function checkStudyId($record, $group_id) {

        if ($this->random_result !== "") {
            // The record has been randomized
            // Check if it has a study_id - we do this as a second query as it doesn't necessarily
            // have to be in the same event as the first query
            $study_id_field = $this->getProjectSetting('study-name-field');
            $study_id_event = $this->getProjectSetting('study-name-event');
            if ($this->getValue($record, $study_id_field, $study_id_event) === "") {
                // Let's create a study_id

                // First, let's get the dagNum
                $dagNum = $this->getDagGroupIdToDagNumPrefix($group_id);
                if ($dagNum == false) {
                    $this->emLog("Unable to get a valid dagNum for $record / $group_id");
                    return false;
                }

                // Next, let's get the ID:
                $prefix        = "P" . $dagNum . "-";
                $padding       = 3;
                $next_study_id = $this->getNextStudyId($prefix, $padding, $study_id_field, $study_id_event);

                if ($next_study_id == false) {
                    $this->emLog("There was an error getting the nextStudyId for $record with $prefix");
                    return false;
                }

                // Let's write the ID to the record
                $data   = array(
                    $record => array(
                        $study_id_event => array(
                            $study_id_field => $next_study_id
                        )
                    )
                );
                $result = REDCap::saveData('array', $data);
                if(!empty($result['errors'])) {
                    $this->emError("Unable to save new study id", $result);
                    return false;
                }
                return $next_study_id;
            }
        }
    }


    /**
     * This pulls an unused alias from a separate project and assigns it to a record in this project
     * @param $record
     * @param $group_id
     * @return bool
     */
    private function checkPharmaAlias($record, $group_id) {
        // See if we need to get a pharma alias

        // If not randomized, we do not need to do anything.
        if (empty($this->random_result)) return false;

        $pharma_alias_field = $this->getProjectSetting('pharma-alias-field');
        $pharma_alias_event = $this->getProjectSetting('pharma-alias-event');
        $pharma_alias_pid   = $this->getProjectSetting('pharma-alias-pid');

        // Get the current alias
        $current_alias = $this->getValue($record, $pharma_alias_field, $pharma_alias_event);

        // If already has an alias, we don't need to do anything.
        if (!empty($current_alias)) return false;

        // Lookup the next alias
        $site = $this->getDagGroupIdToDagNumPrefix($group_id);

        $params = array(
            "project_id"    => $pharma_alias_pid,
            "return_format" => 'json',
            "filterLogic"   => "[used_by] = '' AND [group] = '$this->random_result' AND [site] = '$site'"
        );
        $q = REDCap::getData($params);
        $results = json_decode($q,true);

        $this->emDebug("Found " . count($results) . " results");
        if (count($results) == 0) {
            // No more available
            $this->emLog("Unable to find pharma alias with query", $params);
            return false;
        } else {
            $result = $results[0];

            // Reserve this alias
            $result['used_by'] = $record;
            $q = REDCap::saveData($pharma_alias_pid, 'json', json_encode(array($result)));
            $this->emDebug("save result", $q);
            if (!empty($q['errors'])) {
                REDCap::logEvent("Unable to set pharma codebook alias!  Check server logs for details.","","",$record);
                $this->emError("Unable to save pharma alias", $result, $q);
                return false;
            }

            // Save the alias to this record
            $code = $result['code'];
            $data   = array(
                $record => array(
                    $pharma_alias_event => array(
                        $pharma_alias_field => $code
                    )
                )
            );
            $result = REDCap::saveData('array', $data);
            if(!empty($result['errors'])) {
                $this->emError("Unable to save new study id", $result);
                return false;
            }

            return true;
        }
    }


    /**
     * Convert the dag name to a numerical id from 1 to 10 for this study
     * e.g. 05_duke => 5
     * @return array
     */
	public function getDagGroupIdToDagNumPrefix($group_id = null) {

        // SU, Stanford University
        // MCJ, Mayo Clinic - Jacksonville
        // MCR, Mayo Clinic - Rochester
        // MCS, Mayo Clinic - Scottsdale
        // DU, Duke University
        // JHU, Johns Hopkins University
        // NYU, New York University - Langone Health
        // TU, Temple University
        // UA, University of Arizona
        // UF, University of Florida
        //
        //
        // 01_stanford
        // 02_mayo_jacksonvil
        // 03_mayo_rochester
        // 04_mayo_scottsdale
        // 05_duke
        // 06_johnshopkins
        // 07_nyu_langone
        // 08_temple
        // 09_u_of_arizona
        // 10_u_of_florida

        $dagIdToDagNum = array();
        $groups = REDCap::getGroupNames();
        foreach ($groups as $this_group_id => $group_name) {
            list ($num, $name) = explode("_",$group_name,2);
            if (is_numeric($num)) {
                $dagIdToDagNum[ $this_group_id ] = intval($num);
            }
        }

        if ($group_id === null) {
            $result = $dagIdToDagNum;
        } elseif (isset($dagIdToDagNum[$group_id])) {
            $result = $dagIdToDagNum[$group_id];
        } else {
            $result = false;
        }

        return $result;
    }


    /**
     * See if we are in the random event (and if so, load the random data)
     * @param $record
     * @param $event_id
     * @return bool // true if we are in the random event
     */
    public function inRandomEvent($record, $event_id) {
        $this->random_result_event = $this->getProjectSetting('random-result-event');

        // If the current event_id doesn't match the event with the random result, we can skip
        if ($event_id !== $this->random_result_event) return false;

        // Load the current value of the random result to see if the record has been randomized
        $this->random_result_field = $this->getProjectSetting('random-result-field');
        $this->random_result = $this->getValue($record, $this->random_result_field, $this->random_result_event);

        return true;
    }


    /**
     * Look up a value from the database
     * @param $record
     * @param $field_name
     * @param $event_id
     * @return bool
     */
    private function getValue($record, $field_name, $event_id) {
        // Get data for randomization
        $params = array(
            "records" => array($record),
            "fields" => array($field_name),
            "events" => $event_id
        );
        $q = REDCap::getData($params);
        $result = isset($q[$record][$event_id][$field_name]) ? $q[$record][$event_id][$field_name] : false;
        return $result;
    }


    /**
     * This function will create the next record label based on the inputs from the config file
     * and the existing records.
     *
     * @param $record_prefix - user entered in config
     * @param $number_padding_size - user entered number length in config
     * @param $recordFieldName - field_name in project of record id
     * @return string - new record label
     */
    private function getNextStudyId($record_prefix, $number_padding_size, $recordFieldName, $event_id) {
        $filter = "starts_with([" . $recordFieldName . "],'" .$record_prefix . "')";
        $record_field_array = array($recordFieldName);
        $recordIDs = REDCap::getData('array', null, $record_field_array, $event_id, null, null, null, null, $filter);

        // Get the part of the record name after the prefix.  Changing to uppercase in case someone hand enters a record
        // and uses the same prefix with different case.
        $record_array_noprefix = array();
        foreach($recordIDs as $record_num => $recordInfo) {
            $record_noprefix = trim(str_replace(strtoupper($record_prefix), "", strtoupper($record_num)));
            if (is_numeric($record_noprefix)) {
                $record_array_noprefix[] = $record_noprefix;
            }
        }

        // Retrieve the max value so we can add one to create the new record label
        $highest_record_number = max($record_array_noprefix);
        if (!empty($number_padding_size)) {
            $numeric_part = str_pad(($highest_record_number + 1), $number_padding_size, '0', STR_PAD_LEFT);
        } else {
            $numeric_part = ($highest_record_number + 1);
        }
        $newRecordLabel = $record_prefix . $numeric_part;

        return $newRecordLabel;
    }

}
