<?php

/**
 * SmsChaos.Contributions API to fix contributions for tags SMS Chaos 1 and 3
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_sms_chaos_contributions($params) {
  set_time_limit(0);
  $returnValues = array();
  $smsTagId1 = civicrm_api3('Tag', 'getvalue', array('name' => 'SMS Chaos 1', 'return' => 'id'));
  $smsTagId3 = civicrm_api3('Tag', 'getvalue', array('name' => 'SMS Chaos 3', 'return' => 'id'));
  $customTable = civicrm_api3('CustomGroup', 'getvalue', array(
    'name' => 'nets_transactions',
    'return' => 'table_name'));
  $customAksjon = civicrm_api3('CustomField', 'getvalue', array(
    'custom_group_id' => 'nets_transactions',
    'name' => 'Aksjon_ID',
    'return' => 'column_name'));
  $customBalans = civicrm_api3('CustomField', 'getvalue', array(
    'custom_group_id' => 'nets_transactions',
    'name' => 'balansekonto',
    'return' => 'column_name'));
  $customEar = civicrm_api3('CustomField', 'getvalue', array(
    'custom_group_id' => 'nets_transactions',
    'name' => 'earmarking',
    'return' => 'column_name'));

  $query = 'SELECT DISTINCT(entity_id) FROM civicrm_entity_tag WHERE entity_table = %1 AND tag_id in (%2, %3)';
  $queryParams = array(
    1 => array('civicrm_contact', 'String'),
    2 => array($smsTagId1, 'Integer'),
    3 => array($smsTagId3, 'Integer')
  );
  $dao = CRM_Core_DAO::executeQuery($query, $queryParams);

  while ($dao->fetch()) {
    $contributionParams = array(
      'contact_id' => $dao->entity_id,
      'currency' => 'NOK',
      'financial_type_id' => 1,
      'payment_instrument_id' => 6,
      'receive_date' => '08-10-2016',
      'total_amount' => 200,
      'contribution_status_id' => 1,
      'source' => 'SMS Fix'
    );
    $contributionCount = civicrm_api3('Contribution', 'getcount', array(
      'contact_id' => $dao->entity_id,
      'source' => 'SMS Fix'));
    if ($contributionCount <= 0) {
      try {
        $created = civicrm_api3('Contribution', 'create', $contributionParams);
        $returnValues[] = 'Created contribution for contact ' . $dao->entity_id;
        // update or create custom data
        $countSql = 'SELECT COUNT(*) FROM '.$customTable.' WHERE entity_id = %1';
        $customCount = CRM_Core_DAO::singleValueQuery($countSql, array(1 => array($created['id'], 'Integer')));
        if ($customCount > 0) {
          $customSql = 'UPDATE '.$customTable.' SET '.$customAksjon.' = %1, ' .$customBalans. ' = %2, ' .$customEar
            . ' = %3 WHERE entity_id = %4';
        } else {
          $customSql = 'INSERT INTO  '.$customTable.' ('.$customAksjon.',  '.$customBalans.', '.$customEar
            .', entity_id) VALUES(%1, %2, %3, %4)';
        }
        $customParams = array(
          1 => array('MAF Haiti', 'String'),
          2 => array('1571', 'String'),
          3 => array('332', 'String',),
          4 => array($created['id'], 'Integer'));
        CRM_Core_DAO::executeQuery($customSql, $customParams);
      } catch (CiviCRM_API3_Exception $ex) {
        $returnValues[] = 'Could NOT create contribution for contact ' . $dao->entity_id;
      }
    }
  }
  return civicrm_api3_create_success($returnValues, $params, 'SMSChaos', 'Contributions');
}

