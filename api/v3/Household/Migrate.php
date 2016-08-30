<?php

/**
 * Household.Migrate API
 * Migration of Household contacts into primary (and possibly other) individuals
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_household_migrate($params) {
  set_time_limit(0);
  $returnValues = array();
  $returnValues[0] = "Migrated households with contact_id ";
  $household = new CRM_Corrections_Household();

  // if there is a param contact_id, only process this single contact else read all households
  if (!isset($params['contact_id']) || empty($params['contact_id'])) {
    $queryHousehold = 'SELECT cc.id FROM civicrm_contact cc LEFT JOIN civicrm_value_migration_processed mi 
      ON cc.id = mi.entity_id WHERE cc.contact_type = %1 AND cc.is_deleted = %2 
      AND (mi.processed <> %3 OR mi.processed IS NULL) LIMIT 250';
    $paramsHousehold = array(
      1 => array("Household", "String"),
      2 => array(0, "Integer"),
      3 => array(1, "Integer"));
    $daoHousehold = CRM_Core_DAO::executeQuery($queryHousehold, $paramsHousehold);
    while ($daoHousehold->fetch()) {
      $household->migrate($daoHousehold->id);
      $returnValues[] = "contact_id ".$daoHousehold->id;
    }
  } else {
    $household->migrate($params['contact_id']);
    $returnValues[] = "contact_id ".$params['contact_id'];
  }

  return civicrm_api3_create_success($returnValues, $params, 'Household', 'Migrate');
}

