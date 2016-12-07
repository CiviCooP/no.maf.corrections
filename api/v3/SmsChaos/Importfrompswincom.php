<?php
/**
 * @author Jaap Jansma (CiviCooP) <jaap.jansma@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */

function civicrm_api3_sms_chaos_importfrompswincom($params) {
  set_time_limit(0);
  $returnValues = array();

  // Import CSV File:
  // For each row find the contact based on the phone number. When the contact is
  // not found create a new contact
  // Per row create an activity (Incoming SMS)
  // And for each sms create a completed contribution record
  // For 200KR.

  /*
   * CSV File looks like:
   * From	    To	  Text	Time	              Message Type
   * 93643490	2377	MAF	  23.10.2016 17:41:56	SMS
   *
   */

  $row = 0;
  $path = realpath(__DIR__ .'/../../../');
  $provider = CRM_SMS_Provider::singleton(array(
    'provider_id' => 2,
  ));
  $actStatusIDs = array_flip(CRM_Core_OptionGroup::values('activity_status'));
  $activityTypeID = CRM_Core_OptionGroup::getValue('activity_type', 'Inbound SMS', 'name');

  $nets_transaction = civicrm_api3('CustomGroup', 'getsingle', array('name' => 'nets_transactions'));
  $nets_transaction_gid = $nets_transaction['id'];
  if (is_array($nets_transaction) && isset($nets_transaction['id']) && isset($nets_transaction['table_name'])) {
    $balans_konto_field_name = civicrm_api3('CustomField', 'getvalue', array('return'=>'column_name', 'name' => 'balansekonto', 'custom_group_id' => $nets_transaction_gid));
    $balans_konto_table_name = $nets_transaction['table_name'];
  }

  CRM_Smsautoreply_Reply::disable();

  if (($handle = fopen($path."/files/import_sms.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
      $row ++;

      if ($row <= 1) {
        continue;
      }

      $from = $data[0];
      $to = $data[1];
      $body = $data[2];
      $date = new DateTime($data[3]);

      $kind = null;
      $formatFrom   = $provider->formatPhone($provider->stripPhone($from), $kind, "like");
      $escapedFrom  = CRM_Utils_Type::escape($formatFrom, 'String');
      $fromContactID = CRM_Core_DAO::singleValueQuery('SELECT contact_id FROM civicrm_phone JOIN civicrm_contact ON civicrm_contact.id = civicrm_phone.contact_id WHERE !civicrm_contact.is_deleted AND phone LIKE "%' . $escapedFrom . '"');

      if (! $fromContactID) {
        // unknown mobile sender -- create new contact
        // use fake @mobile.sms email address for new contact since civi
        // requires email or name for all contacts
        $locationTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id');
        $phoneTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Phone', 'phone_type_id');
        $phoneloc = array_search('Home', $locationTypes);
        $phonetype = array_search('Mobile', $phoneTypes);
        $stripFrom = $provider->stripPhone($from);
        $contactparams = array(
          'contact_type' => 'Individual',
          'email' => array(1 => array(
            'location_type_id' => $phoneloc,
            'email' => $stripFrom . '@mobile.sms'
          )),
          'phone' => array(1 => array(
            'phone_type_id' => $phonetype,
            'location_type_id' => $phoneloc,
            'phone' => $stripFrom
          )),
        );
        $fromContact = CRM_Contact_BAO_Contact::create($contactparams, FALSE, TRUE, FALSE);
        $fromContactID = $fromContact->id;
      }

      $toContactID = 10; // That is Jaap Jansma

      if ($fromContactID) {
        // note: lets not pass status here, assuming status will be updated by callback
        $activityParams = array(
          'source_contact_id' => $toContactID,
          'target_contact_id' => $fromContactID,
          'activity_type_id' => $activityTypeID,
          'activity_date_time' => $date->format('YmdHis'),
          'status_id' => $actStatusIDs['Completed'],
          'details' => $body,
          'phone_number' => $from,
          'subject' => 'Import SMS after chaos Oct. 2016'
        );

        CRM_Activity_BAO_Activity::create($activityParams);

        //create pending contribution
        $contributionParams['contact_id'] = $fromContactID;
        $contributionParams['total_amount'] = 200;
        $contributionParams['financial_type_id'] = 1;
        $contributionParams['receive_date'] = $date->format('YmdHis');
        $contributionParams['thankyou_date'] = $date->format('YmdHis');
        $contributionParams['contribution_status_id'] = 1; //pending
        $contributionParams['source' ] = 'Import SMS after chaos Nov. 2016';
        $contributionParams['custom_145'] = '0'; // Set Thank you MAF Norge: Skal dett takkes for gaven? to Nein

        $paymentInstrument = CRM_Core_OptionGroup::getValue('payment_instrument', 'SMS');
        if ($paymentInstrument) {
          $contributionParams['contribution_payment_instrument_id'] = $paymentInstrument;
        }

        $contribution = civicrm_api3('Contribution', 'Create', $contributionParams);

        if (!empty($body)) {
          //process note (sms message)
          $noteParams = array(
            'entity_table' => 'civicrm_contribution',
            'note' => $body,
            'entity_id' => $contribution['id'],
            'contact_id' => $fromContactID,
          );
          civicrm_api3('Note', 'create', $noteParams);
        }

        CRM_Core_DAO::executeQuery("INSERT INTO `".$balans_konto_table_name."` (`entity_id`, `".$balans_konto_field_name."`) VALUES (%1, %2) ON DUPLICATE KEY UPDATE `".$balans_konto_field_name."` = %2;", array(
          1 => array($contribution['id'], 'Positive'),
          2 => array('1571', 'String') //1571 is sms payment
        ));
      }
    }
    fclose($handle);
  }

  CRM_Smsautoreply_Reply::enable();

  return civicrm_api3_create_success($returnValues, $params, 'SmsChaos', 'Importfrompswincom');
}