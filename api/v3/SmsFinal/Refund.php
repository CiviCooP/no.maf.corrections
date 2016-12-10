<?php

/**
 * SmsFinal.Refund API
 * Correction scheduled job for SMS Fix on 30 Nov 2016:
 * - third step is to refund contributions for all incoming sms from pswincom with refund status file
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_final_refund($params) {
  set_time_limit(0);
  // add processed column if not exists yet
  if (!CRM_Core_DAO::checkFieldExists('sms_donations_30_nov', 'processed')) {
    CRM_Core_DAO::executeQuery("ALTER TABLE sms_donations_30_nov ADD COLUMN processed TINYINT DEFAULT 0");
  }
  $returnValues = array();
  $completedStatusId = civicrm_api3('OptionValue', 'getvalue', array('option_group_id' => 'contribution_status', 'name' => 'Completed', 'return' => 'value'));
  $refundedStatusId = civicrm_api3('OptionValue', 'getvalue', array('option_group_id' => 'contribution_status', 'name' => 'Refunded', 'return' => 'value'));

  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM sms_donations_30_nov 
    WHERE contact_id IS NOT NULL AND processed = %1 AND refunded_id != %2 LIMIT 100", array(
    1 => array(1, 'Integer'),
    2 => array('#N/B', 'String')));
  while ($dao->fetch()) {

    // find contribution to refund
    $getParams = array(
      'contact_id' => $dao->contact_id,
      'financial_type_id' => $dao->financial_type_id,
      'payment_instrument_id' => $dao->payment_instrument_id,
      'total_amount' => $dao->total_amount,
      'contribution_status_id' => $completedStatusId,
      'source' => $dao->source,
      'trxn_id' => $dao->message_id,
      'return' => 'id'
    );
    $foundContributions = civicrm_api3('Contribution', 'get', $getParams);
    foreach ($foundContributions['values'] as $contribution) {
      $refundParams = array(
        'id' => $contribution['contribution_id'],
        'contribution_status_id' => $refundedStatusId,
      );
      civicrm_api3('Contribution', 'create', $refundParams);
    }
    // finally update to processed
    $processedSql = "UPDATE sms_donations_30_nov SET processed = %1 WHERE message_id = %2";
    $processedParams = array(
      1 => array(2, 'Integer'),
      2 => array($dao->message_id, 'String')
    );
    CRM_Core_DAO::executeQuery($processedSql, $processedParams);
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsFinal', 'Create');
}

