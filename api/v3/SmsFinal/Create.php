<?php

/**
 * SmsFinal.Cancel API
 * Correction scheduled job for SMS Fix on 30 Nov 2016:
 * - second step is to create contributions for all incoming sms from pswincom file
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_final_create($params) {
  set_time_limit(0);
  // add processed column if not exists yet
  if (!CRM_Core_DAO::checkFieldExists('sms_donations_30_nov', 'processed')) {
    CRM_Core_DAO::executeQuery("ALTER TABLE sms_donations_30_nov ADD COLUMN processed TINYINT DEFAULT 0");
  }
  $returnValues = array();
  $netsTableName = civicrm_api3('CustomGroup', 'getvalue', array('name' => 'nets_transactions', 'return' => 'table_name'));
  $thankYouTableName = civicrm_api3('CustomGroup', 'getvalue', array('name' => 'maf_norge_contribution_thank_you', 'return' => 'table_name'));
  $earmarkingColumn = civicrm_api3('CustomField', 'getvalue', array('name' => 'earmarking', 'custom_group_id' => 'nets_transactions', 'return' => 'column_name'));
  $aksjonColumn = civicrm_api3('CustomField', 'getvalue', array('name' => 'Aksjon_ID', 'custom_group_id' => 'nets_transactions', 'return' => 'column_name'));
  $balanseColumn = civicrm_api3('CustomField', 'getvalue', array('name' => 'balansekonto', 'custom_group_id' => 'nets_transactions', 'return' => 'column_name'));
  $thankYouColumn = civicrm_api3('CustomField', 'getvalue', array('name' => 'contribution_thank_you', 'custom_group_id' => 'maf_norge_contribution_thank_you', 'return' => 'column_name'));
  $completedActivityStatusId = civicrm_api3('OptionValue', 'getvalue', array('option_group_id' => 'activity_status', 'name' => 'Completed', 'return' => 'value'));
  $inboundSMSActivityTypeId = CRM_Core_OptionGroup::getValue('activity_type', 'Inbound SMS', 'name');


  $completedStatusId = civicrm_api3('OptionValue', 'getvalue', array(
    'option_group_id' => 'contribution_status',
    'name' => 'Completed',
    'return' => 'value'));
  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM sms_donations_30_nov WHERE contact_id IS NOT NULL AND processed = 0 LIMIT 50");
  while ($dao->fetch()) {

    $toContactID = 9; // That is Erik Hommel
    // create inbound SMS activity
    $activityDate = new DateTime($dao->donation_date);
    $activityParams = array(
      'source_contact_id' => $toContactID,
      'target_contact_id' => $dao->contact_id,
      'activity_type_id' => $inboundSMSActivityTypeId,
      'activity_date_time' => $activityDate->format('YmdHis'),
      'status_id' => $completedActivityStatusId,
      'details' => 'SMS Message ID is '.$dao->message_id.", status ".$dao->donation_status,
      'phone_number' => $dao->phone,
      'subject' => 'Refund list from Link-mobility'
    );
    CRM_Activity_BAO_Activity::create($activityParams);

    // create contribution
    $contributionParams = array(
      'contact_id' => $dao->contact_id,
      'financial_type_id' => $dao->financial_type_id,
      'payment_instrument_id' => $dao->payment_instrument_id,
      'total_amount' => $dao->total_amount,
      'contribution_status_id' => $completedStatusId,
      'source' => $dao->source
    );
    if (!empty($dao->donation_date)) {
      $contributionParams['receive_date'] = $dao->donation_date;
    }
    $contribution = civicrm_api3('Contribution', 'create', $contributionParams);
    // now create or update custom data
    if ($contribution) {
      $netsCount = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM ".$netsTableName." WHERE entity_id = %1",
        array(1 => array($contribution['id'], 'Integer')));
      if ($netsCount == 0) {
        $netsSql = "INSERT INTO ".$netsTableName." (entity_id, ".$earmarkingColumn.", ".$aksjonColumn.", ".$balanseColumn
          .") VALUES(%1, %2, %3, %4)";
      } else {
        $netsSql = "UPDATE ".$netsTableName." SET ".$earmarkingColumn." = %2, ".$aksjonColumn." = %3, ".$balanseColumn.
          " = %4 WHERE entity_id = %1";
      }
      $netsParams = array(
        1 => array($contribution['id'], 'Integer'),
        2 => array($dao->earmarking_id, 'String'),
        3 => array($dao->aksjon, 'String'),
        4 => array($dao->balansekonto_id, 'String')
      );
      CRM_Core_DAO::executeQuery($netsSql, $netsParams);
      $thankYouCount = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM ".$thankYouTableName." WHERE entity_id = %1",
        array(1 => array($contribution['id'], 'Integer')));
      if ($thankYouCount == 0) {
        $thankYouSql = "INSERT INTO ".$thankYouTableName." (entity_id, ".$thankYouColumn.") VALUES(%1, %2)";
      } else {
        $thankYouSql = "UPDATE ".$thankYouTableName." SET ".$thankYouColumn." = %2 WHERE entity_id = %1";
      }
      $thankYouParams = array(
        1 => array($contribution['id'], 'Integer'),
        2 => array(0, 'Integer'),
      );
      CRM_Core_DAO::executeQuery($thankYouSql, $thankYouParams);
      // finally update to processed
      $processedSql = "UPDATE sms_donations_30_nov SET processed = %1 WHERE message_id = %2";
      $processedParams = array(
        1 => array(1, 'Integer'),
        2 => array($dao->message_id, 'String')
      );
      CRM_Core_DAO::executeQuery($processedSql, $processedParams);
    }
  }
  return civicrm_api3_create_success($returnValues, $params, 'SmsFinal', 'Create');
}

