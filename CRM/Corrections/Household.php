<?php

/**
 * Class for Household processing in Correction extension
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 29 Aug 2016
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Corrections_Household {

  private $_kidBaseTable = NULL;
  private $_kidBaseColumn = NULL;
  private $_logger = NULL;
  private $_primaryIndividualColumn = NULL;
  private $_primaryIndividualTable = NULL;
  private $_spouseRelationshipTypeId = 0;
  private $_householdHeadRelationshipTypeId = 0;
  private $_householdMemberRelationshipTypeId = 0;
  private $_migrationTable = NULL;
  private $_migrationColumn = NULL;

  /**
   * CRM_Corrections_Household constructor.
   *
   * @throws Exception when error from API CustomGroup or CustomField getvalue
   */
  function __construct() {
    try {
      $this->_kidBaseTable = civicrm_api3('CustomGroup', 'getvalue', array(
        'name' => 'kid_base',
        'return' => 'table_name'));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a custom group with the name kid_base in ' . __METHOD__ . ', contact your system administrator. 
      Error from API CustomGroup getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_kidBaseColumn = civicrm_api3('CustomField', 'getvalue', array(
        'custom_group_id' => 'kid_base',
        'name' => 'kid_base',
        'return' => 'column_name'
      ));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a custom field with name kid_base in custom group kid_base in ' . __METHOD__
        . ', contact your system administrator. Error from API CustomField getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_migrationTable = civicrm_api3('CustomGroup', 'getvalue', array(
        'name' => 'migration_processed',
        'extends' => 'Household',
        'return' => 'table_name'));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a custom group with the name migration_processed in ' . __METHOD__ . ', contact your system administrator. 
      Error from API CustomGroup getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_migrationColumn = civicrm_api3('CustomField', 'getvalue', array(
        'custom_group_id' => 'migration_processed',
        'name' => 'processed',
        'return' => 'column_name'
      ));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a custom field with name processed in custom group migration_processed in ' . __METHOD__
        . ', contact your system administrator. Error from API CustomField getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_spouseRelationshipTypeId = civicrm_api3('RelationshipType', 'getvalue', array(
        'name_a_b' => 'Spouse of',
        'return' => 'id'));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a relationship type with name_a_b Spouse of in ' . __METHOD__
        . ', contact your system administrator. Error from API RelationshipType getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_householdHeadRelationshipTypeId = civicrm_api3('RelationshipType', 'getvalue', array(
        'name_a_b' => 'Head of Household for',
        'return' => 'id'));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a relationship type with name_a_b Head of Household for in ' . __METHOD__
        . ', contact your system administrator. Error from API RelationshipType getvalue: ' . $ex->getMessage());
    }

    try {
      $this->_householdMemberRelationshipTypeId = civicrm_api3('RelationshipType', 'getvalue', array(
        'name_a_b' => 'Household Member of',
        'return' => 'id'));
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Could not find a relationship type with name_a_b Household Member of in ' . __METHOD__
        . ', contact your system administrator. Error from API RelationshipType getvalue: ' . $ex->getMessage());
    }

    $this->getPrimaryColumn();
  }

  /**
   * Method to get the custom column for primary contact column
   *
   * @throws Exception when error in API actions
   */
  private function getPrimaryColumn() {
    //try {
      $customGroups = civicrm_api3('CustomGroup', 'get', array('extends' => 'Household'));
      foreach ($customGroups['values'] as $customGroup) {
        if ($customGroup['name'] == 'Primary_Contact') {
          $this->_primaryIndividualTable = $customGroup['table_name'];
          $this->_primaryIndividualColumn = civicrm_api3('CustomField', 'getvalue', array(
            'custom_group_id' => $customGroup['id'],
            'return' => 'column_name'
          ));
        }
      }
    //} catch (CiviCRM_API3_Exception $ex) {
      //throw new Exception('Could not find a custom group or custom field for the primary contact with household.
      //Set up this group and try again');
    //}
  }

  /**
   * Method to migrate a household into one primary individual and possibly more other individuals
   *
   * @param int $householdId
   * @return bool
   * @throws Exception when empty householdId
   */
  function migrate($householdId) {
    if (empty($householdId)) {
      throw new Exception('No household ID passed into method '.__METHOD__.', unable to process');
    }
    $this->_logger = new CRM_Corrections_Logger('maf_household_'.$householdId.'_migration');

    // STEP 1: set the processed flag for the household
    $this->setProcessedHousehold($householdId);

    // STEP 2: find primary contact of household, log and ignore further processing if nothing is found
    $primaryIndividualId = $this->findPrimaryIndividual($householdId);
    if ($primaryIndividualId == FALSE) {
      $this->_logger->logMessage('Error', 'Could not find a primary individual for household '.$householdId);
      return FALSE;
    } else {
      $this->_logger->logMessage('Success', 'Found primary individual '.$primaryIndividualId.' for household '.$householdId);
    }

    // STEP 3: change all relevant tables, set contact_id to primaryIndividualId where it was householdId
    $this->updateTables($primaryIndividualId, $householdId);
    $this->updateCustomGroups($primaryIndividualId, $householdId);

    // STEP 4: set the kid_base field for the primary individual
    $this->setKidBase($primaryIndividualId, $householdId);

    // STEP 5: retrieve all other related indivdiduals
    $otherIndividualIds = $this->findOtherIndividuals($primaryIndividualId, $householdId);

    // STEP 6: create the spouse relationship between primary individual and all others
    $this->createSpouseRelation($primaryIndividualId, $otherIndividualIds);

    // STEP 7: add the household address to the primary individual as a master and to all others as slaves
    $this->createMasterAddress($primaryIndividualId, $householdId);
    $this->createSlaveAddress($primaryIndividualId, $otherIndividualIds);
    $this->_logger->logMessage('Completion', 'Completed migration of household '.$householdId.' with primary individual '
      .$primaryIndividualId.' (and possibly other individual(s) '.implode(';', $otherIndividualIds).')');
  }

  /**
   * Method to set the processed flag for the household
   *
   * @param $householdId
   */
  private function setProcessedHousehold($householdId) {
    $query = 'SELECT * FROM civicrm_value_migration_processed WHERE entity_id = %1';
    $count = CRM_Core_DAO::singleValueQuery($query, array(1 => array($householdId, 'Integer')));
    $paramsProcessed = array(
      1 => array(1, 'Integer'),
      2 => array($householdId, 'Integer'));
    if ($count > 0) {
      $queryProcessed = 'UPDATE civicrm_value_migration_processed SET processed = %1 WHERE entity_id = %2';
    } else {
      $queryProcessed = 'INSERT INTO civicrm_value_migration_processed (entity_id, processed) VALUES(%2, %1)';
    }
    CRM_Core_DAO::executeQuery($queryProcessed, $paramsProcessed);
  }

  /**
   * Method to create slave addresses for the non-primary individuals from the primary individual
   *
   * @param int $primaryIndividualId
   * @param array $otherIndividualIds
   */
  public function createSlaveAddress($primaryIndividualId, $otherIndividualIds) {
    $query = 'SELECT * FROM civicrm_address WHERE contact_id = %1';
    $daoAddress = CRM_Core_DAO::executeQuery($query, array(1 => array($primaryIndividualId, 'Integer')));
    while ($daoAddress->fetch()) {
      $insertClauses = array();
      $insertParams = array();
      foreach ($otherIndividualIds as $otherIndividualId) {
        // delete existing address for individual just to be sure (should not bet there but you never know)
        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_address WHERE contact_id = %1',
          array(1 => array($otherIndividualId, 'Integer')));
        $insertClauses[] ='contact_id = %1';
        $insertParams[1] = array($otherIndividualId, 'Integer');
        $insertClauses[] = 'location_type_id = %2';
        $insertParams[2] = array($daoAddress->location_type_id, 'Integer');
        $insertClauses[] = 'is_primary = %3';
        $insertParams[3] = array($daoAddress->is_primary, 'Integer');
        $insertClauses[] = 'is_billing = %4';
        $insertParams[4] = array($daoAddress->is_billing, 'Integer');
        $insertClauses[] = 'manual_geo_code = %5';
        $insertParams[5] = array($daoAddress->manual_geo_code, 'Integer');
        $insertClauses[] = 'master_id = %6';
        $insertParams[6] = array($daoAddress->id, 'Integer');
        $index = 6;
        if (!empty($daoAddress->street_address)) {
          $index++;
          $insertClauses[] = 'street_address = %'.$index;
          $insertParams[$index] = array($daoAddress->street_address, 'String');
        }
        if (!empty($daoAddress->supplemental_address_1)) {
          $index++;
          $insertClauses[] = 'supplemental_address_1 = %'.$index;
          $insertParams[$index] = array($daoAddress->supplemental_address_1, 'String');
        }
        if (!empty($daoAddress->city)) {
          $index++;
          $insertClauses[] = 'city = %'.$index;
          $insertParams[$index] = array($daoAddress->city, 'String');
        }
        if (!empty($daoAddress->postal_code)) {
          $index++;
          $insertClauses[] = 'postal_code = %'.$index;
          $insertParams[$index] = array($daoAddress->postal_code, 'String');
        }
        if (!empty($daoAddress->state_province_id)) {
          $index++;
          $insertClauses[] = 'state_province_id = %'.$index;
          $insertParams[$index] = array($daoAddress->state_province_id, 'Integer');
        }
        $insert = 'INSERT INTO civicrm_address SET '.implode(', ', $insertClauses);
        CRM_Core_DAO::executeQuery($insert, $insertParams);
        $this->_logger->logMessage('Success', 'Created address with master_id '.$daoAddress->id.' for individual '.$otherIndividualId);
      }
    }
  }
  /**
   * Method to create a master address for the primary individual from the household address
   *
   * @param $primaryIndividualId
   * @param $householdId
   */
  public function createMasterAddress($primaryIndividualId, $householdId) {
    // delete existing address for primary individual just to be sure (should not be there but you never know)
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_address WHERE contact_id = %1',
      array(1 => array($primaryIndividualId, 'Integer')));
    // now update all household addresses to primary individual
    $query = 'UPDATE civicrm_address SET contact_id = %1 WHERE contact_id = %2';
    $params = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($householdId, 'Integer'));
    CRM_Core_DAO::executeQuery($query, $params);
    $this->_logger->logMessage('Success', 'Updated addresses for household '.$householdId
      .' to primary individual '.$primaryIndividualId);
  }

  /**
   * Method to set the spouse relationship between the primary individual and the others
   *
   * @param $primaryIndividualId
   * @param $otherIndividualIds
   */
  public function createSpouseRelation($primaryIndividualId, $otherIndividualIds) {
    $insert = 'INSERT INTO civicrm_relationship (contact_id_a, contact_id_b, relationship_type_id, is_active, is_permission_a_b, 
      is_permission_b_a) VALUES(%1, %2, %3, %4, %5, %5)';
    foreach ($otherIndividualIds as $otherIndividualId) {
      if ($this->spouseRelationExists($primaryIndividualId, $otherIndividualId) == FALSE) {
        $insertParams = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($otherIndividualId, 'Integer'),
          3 => array($this->_spouseRelationshipTypeId, 'Integer'),
          4 => array(1, 'Integer'),
          5 => array(0, 'Integer'));
        CRM_Core_DAO::executeQuery($insert, $insertParams);
        $this->_logger->logMessage('Success', 'Created relationship Spouse between '.$primaryIndividualId.' and '.$otherIndividualId);
      }
    }
  }

  /**
   * Method to find out if there is an existing relationship spouse between contact_id_a and contact_id_b
   *
   * @param $contactIdA
   * @param $contactIdB
   * @return bool
   */
  public function spouseRelationExists($contactIdA, $contactIdB) {
    $query = 'SELECT COUNT(*) FROM civicrm_relationship WHERE contact_id_a = %1 AND contact_id_b = %2 
      AND civicrm_relationship.relationship_type_id = %3';
    $params = array(
      1 => array($contactIdA, 'Integer'),
      2 => array($contactIdB, 'Integer'),
      3 => array($this->_spouseRelationshipTypeId, 'Integer'));
    $count = CRM_Core_DAO::singleValueQuery($query, $params);
    if ($count > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Method to find the individuals linked to the household that are NOT the primary individual
   *
   * @param $primaryIndividualId
   * @param $householdId
   * @return array $otherIndividualIds
   */
  public function findOtherIndividuals($primaryIndividualId, $householdId) {
    $otherIndividualIds = array();
    $query = 'SELECT contact_id_a FROM civicrm_relationship WHERE contact_id_b = %1 AND is_active = %2 AND 
      relationship_type_id IN(%3, %4)';
    $params = array(
      1 => array($householdId, 'Integer'),
      2 => array(1, 'Integer'),
      3 => array($this->_householdHeadRelationshipTypeId, 'Integer'),
      4 => array($this->_householdMemberRelationshipTypeId, 'Integer'));
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      if ($dao->contact_id_a != $primaryIndividualId) {
        $otherIndividualIds[] = $dao->contact_id_a;
      }
    }
    return $otherIndividualIds;
  }

  /**
   * Method to set the kid base id for the primary individual id with the value household id
   *
   * @param $primaryIndividualId
   * @param $householdId
   */
  public function setKidBase($primaryIndividualId, $householdId) {
    $paramsKidBase = array(
      1 => array($householdId, 'Integer'),
      2 => array($primaryIndividualId, 'Integer'));
    $queryCount = 'SELECT COUNT(*) FROM '.$this->_kidBaseTable.' WHERE entity_id = %1';
    $count = CRM_Core_DAO::singleValueQuery($queryCount, array(1 => array($primaryIndividualId, 'Integer')));
    if ($count > 0) {
      $queryKidBase = 'UPDATE ' . $this->_kidBaseTable . ' SET ' . $this->_kidBaseColumn . ' = %1 WHERE entity_id = %2';
    } else {
      $queryKidBase = 'INSERT INTO '.$this->_kidBaseTable.' (entity_id, '.$this->_kidBaseColumn.') VALUES(%2, %1)';
    }
    CRM_Core_DAO::executeQuery($queryKidBase, $paramsKidBase);
    $this->_logger->logMessage('Success', 'Updated or inserted '.$this->_kidBaseColumn.' in table '.$this->_kidBaseTable
      .' with value household '.$householdId .' for primary individual '.$primaryIndividualId);
  }

  /**
   * Method to find the primary individual for the household (retrieve from custom field)
   *
   * @param int $householdId
   * @return int|bool
   */
  public function findPrimaryIndividual($householdId) {
    $query = 'SELECT '.$this->_primaryIndividualColumn.' FROM '.$this->_primaryIndividualTable.' WHERE entity_id = %1';
    $primaryIndividualId = CRM_Core_DAO::singleValueQuery($query, array(1 => array($householdId, 'Integer')));
    if (!empty($primaryIndividualId)) {
      return $primaryIndividualId;
    }
    return FALSE;
  }

  /**
   * Method to update all relevant custom data (those that extend contact)
   *
   * @param $primaryIndividualId
   * @param $householdId
   */
  private function updateCustomGroups($primaryIndividualId, $householdId) {
    $queryCustomGroups = 'SELECT table_name FROM civicrm_custom_group WHERE extends = %1';
    $daoCustomGroups = CRM_Core_DAO::executeQuery($queryCustomGroups, array(1 => array('Contact', 'String')));
    while ($daoCustomGroups->fetch()) {
      if ($daoCustomGroups->table_name != $this->_kidBaseTable && $daoCustomGroups->table_name != $this->_primaryIndividualTable) {
        if ($this->customGroupPrimaryExists($daoCustomGroups, $primaryIndividualId) == TRUE) {
          $queryCustomTable = 'DELETE FROM ' . $daoCustomGroups->table_name . ' WHERE entity_id = %1';
          CRM_Core_DAO::executeQuery($queryCustomTable, array(1 => array($primaryIndividualId, 'Integer')));
        }
        $queryCustomTable = 'UPDATE ' . $daoCustomGroups->table_name . ' SET entity_id = %1 WHERE entity_id = %2';
        $paramsCustomTable = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($householdId, 'Integer'));
        CRM_Core_DAO::executeQuery($queryCustomTable, $paramsCustomTable);
        $this->_logger->logMessage('Success', 'Updated records in custom table ' . $daoCustomGroups->table_name
          . ' from household ' . $householdId . ' to primary individual ' . $primaryIndividualId);
      }
    }
  }

  /**
   * Method to update all relevant civicrm tables
   *
   * @param $primaryIndividualId
   * @param $householdId
   */
  private function updateTables($primaryIndividualId, $householdId) {

    // first process all tables that use the contact_id field
    $massTables = array('civicrm_contribution_recur', 'civicrm_contribution_soft', 'civicrm_contribution',
      'civicrm_financial_item', 'civicrm_kid_number', 'civicrm_mailing_event_queue', 'civicrm_mailing_recipients',
      'civicrm_membership', 'civicrm_participant');
    $params = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($householdId, 'Integer'));

    foreach ($massTables as $tableName) {
      $query = "UPDATE ".$tableName." SET contact_id = %1 WHERE contact_id = %2";
      CRM_Core_DAO::executeQuery($query, $params);
      $this->_logger->logMessage('Success', 'Updated contact_id in table '.$tableName.' from '.$householdId.' to '.$primaryIndividualId);
    }

    // civicrm_activity_contact needs a check if there is already a record for the record_type and primary individual id
    $queryActHousehold = 'SELECT * FROM civicrm_activity_contact where contact_id = %1';
    $daoActHousehold = CRM_Core_DAO::executeQuery($queryActHousehold, array(1 => array($householdId, 'Integer')));
    while ($daoActHousehold->fetch()) {
      if ($this->activityContactPrimaryExists($daoActHousehold, $primaryIndividualId) == TRUE) {
        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_activity_contact WHERE id = %1', array(
          1 => array($daoActHousehold->id, 'Integer')));
      } else {
        $updateActHousehold = 'UPDATE civicrm_activity_contact SET contact_id = %1 WHERE id = %2';
        $paramsActHousehold = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($daoActHousehold->id, 'Integer'));
        CRM_Core_DAO::executeQuery($updateActHousehold, $paramsActHousehold);
      }
      $this->_logger->logMessage('Success', 'Updated contact_id in table civicrm_activity_contact from '.$householdId.' to '.$primaryIndividualId);
    }

    // civicrm_group_contact needs a check if there is already a record for the group and primary individual id
    $queryGroupContact = 'SELECT * FROM civicrm_group_contact where contact_id = %1';
    $daoGroupContact = CRM_Core_DAO::executeQuery($queryGroupContact, array(1 => array($householdId, 'Integer')));

    while ($daoGroupContact->fetch()) {
      if ($this->groupContactPrimaryExists($daoGroupContact, $primaryIndividualId) == TRUE) {

        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_subscription_history WHERE contact_id = %1 AND group_id = %2',
          array(1 => array($daoGroupContact->contact_id, 'Integer'), 2 => array($daoGroupContact->group_id, 'Integer')));

        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_group_contact_cache WHERE contact_id = %1 AND group_id = %2',
          array(1 => array($daoGroupContact->contact_id, 'Integer'), 2 => array($daoGroupContact->group_id, 'Integer')));

        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_group_contact WHERE id = %1', array(
          1 => array($daoGroupContact->id, 'Integer')));

      } else {

        $updateSubscriptionHistory = 'UPDATE civicrm_subscription_history SET contact_id = %1 
          WHERE contact_id = %2 AND group_id = %3';
        $updateGroupContactCache = 'UPDATE civicrm_group_contact_cache SET contact_id = %1 
          WHERE contact_id = %2 AND group_id = %3';

        $paramsSubscriptionHistory = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($daoGroupContact->contact_id, 'Integer'),
          3 => array($daoGroupContact->group_id, 'Integer'));
        CRM_Core_DAO::executeQuery($updateSubscriptionHistory, $paramsSubscriptionHistory);
        CRM_Core_DAO::executeQuery($updateGroupContactCache, $paramsSubscriptionHistory);

        $updateGroupContact = 'UPDATE civicrm_group_contact SET contact_id = %1 WHERE id = %2';
        $paramsGroupContact = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($daoGroupContact->id, 'Integer'));
        CRM_Core_DAO::executeQuery($updateGroupContact, $paramsGroupContact);
      }
      $this->_logger->logMessage('Success', 'Updated records in tables civicrm_group_contact, civicrm_group_contact_cache 
          and civicrm_subscription_history from household '.$householdId.' to primary individual '.$primaryIndividualId
        . 'and group '.$daoGroupContact->group_id);
    }

    // civicrm_log
    $queryLog = 'UPDATE civicrm_log SET entity_id = %1 WHERE entity_id = %2 AND entity_table = %3';
    $paramsLog = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($householdId, 'Integer'),
      3 => array('civicrm_contact', 'String'));
    CRM_Core_DAO::executeQuery($queryLog, $paramsLog);
    $this->_logger->logMessage('Success', 'Updated records in table civicrm_log from household '.$householdId
      .' to primary individual '.$primaryIndividualId);

    // civicrm_entity_tag needs a check if the primary individual already has the tag
    $queryEntityTag = 'SELECT * FROM civicrm_entity_tag where entity_id = %1 AND entity_table = %2';
    $daoEntityTag = CRM_Core_DAO::executeQuery($queryEntityTag, array(
      1 => array($householdId, 'Integer'),
      2 => array('civicrm_contact', 'String')));
    while ($daoEntityTag->fetch()) {
      if ($this->entityTagPrimaryExists($daoEntityTag, $primaryIndividualId) == TRUE) {
        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_entity_tag WHERE id = %1', array(
          1 => array($daoEntityTag->id, 'Integer')));
        $this->_logger->logMessage('Success', 'Deleted record in table civicrm_entity_tag for household ' . $householdId
          . ' and tag ' . $daoEntityTag->tag_id . ' because there already is a record for primary individual '
          . $primaryIndividualId);
      } else {
        $updateEntityTag = 'UPDATE civicrm_entity_tag SET entity_id = %1 WHERE id = %2';
        $paramsEntityTag = array(
          1 => array($primaryIndividualId, 'Integer'),
          2 => array($daoEntityTag->id, 'Integer'));
        CRM_Core_DAO::executeQuery($updateEntityTag, $paramsEntityTag);
        $this->_logger->logMessage('Success', 'Updated entity_id in table civicrm_entity_tag from ' . $householdId . ' to '
          . $primaryIndividualId . ' and tag ' . $daoEntityTag->tag_id);
      }
    }

    // civicrm_maf_invoice_entity
    $queryInvoiceEntity = 'UPDATE civicrm_maf_invoice_entity SET entity_id = %1 WHERE entity_id = %2 AND entity = %3';
    $paramsInvoiceEntity = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($householdId, 'Integer'),
      3 => array('Contact', 'String'));
    CRM_Core_DAO::executeQuery($queryInvoiceEntity, $paramsInvoiceEntity);
    $this->_logger->logMessage('Success', 'Updated records in table civicrm_maf_invoice_entity from household '.$householdId
      .' to primary individual '.$primaryIndividualId);

    // civicrm_note
    $queryNote = 'UPDATE civicrm_note SET entity_id = %1 WHERE entity_id = %2 AND entity_table = %3';
    $paramsNote = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($householdId, 'Integer'),
      3 => array('civicrm_contact', 'String'));
    CRM_Core_DAO::executeQuery($queryNote, $paramsNote);
    $this->_logger->logMessage('Success', 'Updated records in table civicrm_note from household '.$householdId
      .' to primary individual '.$primaryIndividualId);
  }

  /**
   * Method to find out if there is already a group contact for the primary individual and group id
   *
   * @param object $groupContact
   * @param int $primaryIndividualId
   * @return bool
   */
  private function groupContactPrimaryExists($groupContact, $primaryIndividualId) {
    $query = 'SELECT COUNT(*) FROM civicrm_group_contact WHERE contact_id = %1 AND group_id = %2';
    $params = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($groupContact->group_id, 'Integer'));
    $count = CRM_Core_DAO::singleValueQuery($query, $params);
    if ($count > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Method to find out if there is already an activity contact for the primary individual id with the record type
   *
   * @param object $activityContact
   * @param int $primaryIndividualId
   * @return bool
   */
  private function activityContactPrimaryExists($activityContact, $primaryIndividualId) {
    $query = 'SELECT COUNT(*) FROM civicrm_activity_contact 
      WHERE contact_id = %1 AND activity_id = %2 AND record_type_id = %3';
    $params = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($activityContact->activity_id, 'Integer'),
      3 => array($activityContact->record_type_id, 'Integer'));
    $count = CRM_Core_DAO::singleValueQuery($query, $params);
    if ($count > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Method to find out if there is already an tag for the primary individual
   *
   * @param object $entityTag
   * @param int $primaryIndividualId
   * @return bool
   */
  private function entityTagPrimaryExists($entityTag, $primaryIndividualId) {
    $query = 'SELECT COUNT(*) FROM civicrm_entity_tag 
      WHERE entity_id = %1 AND tag_id = %2 AND entity_table = %3';
    $params = array(
      1 => array($primaryIndividualId, 'Integer'),
      2 => array($entityTag->tag_id, 'Integer'),
      3 => array($entityTag->entity_table, 'String'));
    $count = CRM_Core_DAO::singleValueQuery($query, $params);
    if ($count > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Method to find out if there is already an custom group entry for the primary individual
   *
   * @param object $customGroup
   * @param int $primaryIndividualId
   * @return bool
   */
  private function customGroupPrimaryExists($customGroup, $primaryIndividualId) {
    $query = 'SELECT COUNT(*) FROM '.$customGroup->table_name.' WHERE entity_id = %1';
    $count = CRM_Core_DAO::singleValueQuery($query, array(1 => array($primaryIndividualId, 'Integer')));
    if ($count > 0) {
      return TRUE;
    } else {
      return FALSE;
    }
  }
}