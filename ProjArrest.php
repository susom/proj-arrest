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
     * @throws \Exception
     */
	public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                                       $survey_hash, $response_id, $repeat_instance) {

	    // Try and run after randomisation
	    $this->delayModuleExecution();

        try {

            if ($this->inRandomEvent($record, $event_id)) {
                $this->emDebug("Save on $instrument in $event_id");

                // See if we need to update the study id
                $result = $this->checkStudyId($record, $group_id);
                // $this->emDebug($result);

                // See if we need to update the pharma alias
                $result = $this->checkPharmaAlias($record, $group_id);
                // $this->emDebug($result);

            }

        } catch (\Exception $e) {
            $this->emDebug("Exception: " . $e->getMessage(), $e->getTraceAsString());
        }
    }


    /**
     * Assigns a study id if record is randomized and missing a study id
     * Should only be called if the current event_id is the randomiation event
     * @param $record
     * @param $event_id
     * @param $group_id
     * @return bool|string
     * @throws \Exception
     */
    public function checkStudyId($record, $group_id) {

        if ($this->random_result !== "") {
            // The record has been randomized
            // Check if it has a study_id - we do this as a second query as it doesn't necessarily
            // have to be in the same event as the first query
            $study_id_field = $this->getProjectSetting('study-name-field');
            $study_id_event = $this->getProjectSetting('study-name-event');
            if ($this->getValue($record, $study_id_field, $study_id_event) === "") {

                // First, let's get the dagNum
                $dagNum = $this->getDagGroupIdToDagNumPrefix($group_id);
                if ($dagNum == false) {
                    $this->emLog("Unable to get a valid dagNum for $record / $group_id");
                    REDCap::logEvent("Unable to get a valid dagNum for $record / $group_id");
                    return false;
                }

                // Next, let's get the ID:
                $prefix        = "R" . $dagNum . "-";
                $padding       = 3;
                $next_study_id = $this->getNextFormattedFieldId($prefix, $padding, $study_id_field, $study_id_event);

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

        if (empty($site)) {
            $this->emDebug("Unable to do pharma alias with no site");
            return false;
        }
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
            // If we were unable to obtain a dag number, return false
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
     * This function will create the next incremented field based on the inputs.
     * It works both on record_ids AND on another field in the project
     * It does not work on repeating forms/events
     *
     * @param $prefix - user entered in config
     * @param $padding_length - user entered number length in config
     * @param $field_name - field_name in project of record id
     * @return string - new record label
     * @throws \Exception
     */
    private function getNextFormattedFieldId($prefix, $padding_length, $field_name, $event_id = null, $project_id = null) {
        global $Proj;

        // Set the $proj variable
        if (empty($project_id)) {
            $proj = $Proj;
        } else {
            $proj = isset($Proj) && $Proj->project_id == $project_id ? $Proj : new \Project($project_id);
        }
        if (empty($proj)) return false;  // MISSING REQUIRE PROJ

        // Set the event_id
        if (empty($event_id)) $event_id = $proj->firstEventId;

        // Make a filter
        $filter = "starts_with([" . $field_name . "],'" .$prefix . "')";
        $fields_array = array($field_name);
        $records = REDCap::getData('array', null, $fields_array, $event_id, null, null, null, null, $filter);

        // Get the part of the record name after the prefix.  Changing to uppercase in case someone hand enters a record
        // and uses the same prefix with different case.
        $numeric_values_prefix_removed = array();
        foreach($records as $id => $events) {
            // Skip if empty/missing
            if (empty($events[$event_id][$field_name])) continue;
            $current_value = $events[$event_id][$field_name];
            $value_prefix_removed = empty($prefix) ? trim($current_value) : trim(str_replace($prefix, "", $current_value));
            if (is_numeric($value_prefix_removed)) {
                $numeric_values_prefix_removed[] = intval($value_prefix_removed);
            }
        }

        // Retrieve the max value so we can add one to create the new record label
        $highest_id = count($numeric_values_prefix_removed) > 0 ? max($numeric_values_prefix_removed) : 0;
        $next_id = empty($padding_length) ? $highest_id + 1 : str_pad(($highest_id + 1), $padding_length, '0', STR_PAD_LEFT);
        return $prefix . $next_id;
    }

}
