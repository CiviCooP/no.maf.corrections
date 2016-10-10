<?php
/**
 * SmsChaos.Label API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_chaos_label($params) {
  $returnValues = array();
  $smsChaos = new CRM_Corrections_SmsChaos();
  $sql = 'SELECT sms_date, sender COLLATE utf8_unicode_ci AS smsSender FROM psiwincom_log WHERE direction = %1';
  $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array('In', 'String')));
  while ($dao->fetch()) {
    $smsChaos->labelFromPsiWinCom($dao->smsSender);
    $returnValues[] = 'Processed Incoming SMS date '.$dao->sms_date.' FROM sender '.$dao->smsSender;
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsChaos', 'Label');
}

