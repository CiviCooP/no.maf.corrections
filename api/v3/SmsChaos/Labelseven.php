<?php
/**
 * SmsChaos.Labelseven API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_chaos_labelseven($params) {
  try {
    $tag = civicrm_api3('Tag', 'create', array('name' => 'SMS Chaos 7'));
  } catch (CiviCRM_API3_Exception $ex) {
    $tag = civicrm_api3('Tag', 'getsingle', array('name' => 'SMS Chaos 7'));
  }
  $returnValues = array();
  $sql = 'SELECT DISTINCT(cc.id) AS contact_id
    FROM civicrm_contribution sms LEFT JOIN civicrm_contact cc ON sms.contact_id = cc.id
    WHERE (sms.receive_date BETWEEN %1 AND %2) AND sms.payment_instrument_id = %3
    GROUP BY cc.id HAVING count(sms.id) > 1';
  $dao = CRM_Core_DAO::executeQuery($sql, array(
    1 => array('2016-10-11 00:00:00', 'String'),
    2 => array('2016-10-11 23:59:59', 'String'),
    3 => array(6, 'Integer')));
  while ($dao->fetch()) {
    civicrm_api3('EntityTag', 'create', array(
      'tag_id' => $tag['id'],
      'contact_id' => $dao->contact_id
    ));
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsChaos', 'Labelseven');
}

