<?php

/**
 * SmsFinal.FixDate API
 * sms repair action 30 nov update forgotten contribution date
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_final_fixdate($params) {
  set_time_limit(0);
  $returnValues = array();
  $dao = CRM_Core_DAO::executeQuery('SELECT * from sms_date_30_nov');
  while ($dao->fetch()) {
    $sql = 'UPDATE sms_donations_30_nov SET donation_date = %1 WHERE message_id = %2';
    CRM_Core_DAO::executeQuery($sql, array(
      1 => array($dao->donation_date, 'String'),
      2 => array($dao->message_id, 'String')
    ));
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsFinal', 'FixDate');
}

