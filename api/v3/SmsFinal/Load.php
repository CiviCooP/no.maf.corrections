<?php

/**
 * SmsFinal.Cancel API
 * Find contacts for pswincom file
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_final_load($params) {
  set_time_limit(0);
  $returnValues = array();
  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM sms_donations_30_nov");
  while ($dao->fetch()) {
    $phoneCount = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_phone WHERE phone = %1", array(
      1 => array($dao->phone, 'String')));
    switch ($phoneCount) {
      case 0:
        $sql = 'UPDATE sms_donations_30_nov SET more_phones = %1 WHERE message_id = %2';
        $sqlParams = array(
          1 => array(0, 'Integer'),
          2 => array($dao->message_id, 'String'));
        break;
      case 1:
        $contact = CRM_Core_DAO::executeQuery("SELECT p.contact_id, c.is_deleted 
          FROM civicrm_phone p JOIN civicrm_contact c ON p.contact_id = c.id 
          WHERE p.phone = %1", array(
          1 => array($dao->phone, 'String')));
        $contact->fetch();
        $sql = 'UPDATE sms_donations_30_nov SET more_phones = %1, contact_id = %2, soft_deleted = %3 WHERE message_id = %4';
        $sqlParams = array(
          1 => array(0, 'Integer'),
          2 => array($contact->contact_id, 'Integer'),
          3 => array($contact->is_deleted, 'Integer'),
          4 => array($dao->message_id, 'String'));
        break;
      default:
        $sql = 'UPDATE sms_donations_30_nov SET more_phones = %1 WHERE message_id = %2';
        $sqlParams = array(
          1 => array(1, 'Integer'),
          2 => array($dao->message_id, 'String'));
        break;
    }
    CRM_Core_DAO::executeQuery($sql, $sqlParams);
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsFinal', 'Load');
}

