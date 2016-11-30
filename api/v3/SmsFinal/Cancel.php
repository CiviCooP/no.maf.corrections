<?php

/**
 * SmsFinal.Cancel API
 * Correction scheduled job for SMS Fix on 30 Nov 2016:
 * - first step is to cancel all existing sms contributions in october
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_final_cancel($params) {
  $returnValues = array();
  $smsPaymentInstrumentId = civicrm_api3('OptionValue', 'getvalue', array(
    'option_group_id' => 'payment_instrument',
    'name' => 'SMS',
    'return' => 'value'
  ));
  $cancelContributionStatusId = civicrm_api3('OptionValue', 'getvalue', array(
    'option_group_id' => 'contribution_status',
    'name' => 'Cancelled',
    'return' => 'value'
  ));
  $cancelReason = "SMS originally recorded in Civi, but cancelled by fixing all SMS in Oct 2016";
  $cancelDate = "2016-11-30";
  $financialTypeId = 1;

  $sql = "SELECT id FROM civicrm_contribution 
    WHERE payment_instrument_id = %1 AND financial_type_id = %2 AND (receive_date BETWEEN %3 AND %4)";
  $dao = CRM_Core_DAO::executeQuery($sql, array(
    1 => array($smsPaymentInstrumentId, 'Integer'),
    2 => array($financialTypeId, 'Integer'),
    3 => array('2016-10-01', 'String'),
    4 => array('2016-10-31', 'String')
  ));
  while ($dao->fetch()) {
    $contributionParams = array(
      'contribution_status_id' => $cancelContributionStatusId,
      'cancel_date' => $cancelDate,
      'cancel_reason' => $cancelReason,
      'id' => $dao->id
    );
    civicrm_api3('Contribution', 'create', $contributionParams);
    $returnValues[] = 'Contribution '.$dao->id.' cancelled';
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsFinal', 'Cancel');
}

