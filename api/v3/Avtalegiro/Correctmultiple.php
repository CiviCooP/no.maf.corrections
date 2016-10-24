<?php

/**
 * Correct multiple avtale giro contributions.
 *
 * Delete each contribution which is linked to a recurring contribution and exists
 * more than once in the system.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_avtalegiro_correctmultiple($params) {
  $returnValues = array();
  $count = 0;
  $dao = CRM_Core_DAO::executeQuery("
    SELECT contact_id, contribution_recur_id, count(contact_id) as total
    FROM `civicrm_contribution`
    WHERE (`receive_date` BETWEEN '2016-11-15 00:00:00' AND '2016-12-14 23:59:59')
      AND `contribution_recur_id` IS NOT NULL
      AND `contribution_status_id` =2
    GROUP BY contribution_recur_id
    HAVING COUNT( `contact_id` ) >1
    ORDER BY COUNT( `contact_id` ) DESC"
  );
  while ($dao->fetch()) {
    $count = 0;
    $sqlParams[1] = array($dao->contribution_recur_id, 'Integer');
    $contributions = CRM_Core_DAO::executeQuery("
      SELECT id FROM civicrm_contribution
      WHERE (`receive_date` BETWEEN '2016-11-15 00:00:00' AND '2016-12-14 23:59:59')
        AND `contribution_recur_id` = %1
        AND `contribution_status_id` =2
    ", $sqlParams);
    $i=0;
    while ($contributions->fetch()) {
      $i++;
      if ($i > 1) {
        civicrm_api3('Contribution', 'delete', array('id' => $contributions->id));
      }
    }
  }

  return civicrm_api3_create_success($returnValues, $params, 'Avtale', 'Correctmultiple');
}


 ?>
