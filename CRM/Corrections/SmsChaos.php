<?php

/**
 * Class for fixing SMS Chaos on 8-10 Oct 2016
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 10 Oct 2016
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Corrections_SmsChaos {

  private $_labelTags = array();

  /**
   * CRM_Corrections_SmsChaos constructor.
   * @throws Exception
   */
  function __construct() {
    $tagCounter = 1;
    while ($tagCounter <= 6) {
      $this->getOrCreateTag($tagCounter);
      $tagCounter++;
    }
  }

  /**
   * Method to get or create required tags
   * @param $tagCounter
   */
  private function getOrCreateTag($tagCounter) {
    $tagName = 'SMS Chaos '.$tagCounter;
    try {
      $this->_labelTags[$tagCounter] = civicrm_api3('Tag', 'getvalue', array('name' => $tagName, 'return' => 'id'));
    } catch (CiviCRM_API3_Exception $ex) {
      $created = civicrm_api3('Tag', 'create', array('name' => $tagName));
      $this->_labelTags[$tagCounter] = $created['id'];
    }
  }

  /**
   * Method to label contact based on incoming psiwincom log data
   * For each incoming SMS:
   * - if there is only 1 incoming for the phone number:
   *   - if there is no outgoing for this number: tag contact as SMS Chaos 4
   *   - if there is only 1 outgoing for this number: tag contact as SMS Chaos 2
   *   - if there are more than 1 outgoing: tag contact as SMS Chaos 1
   * - if there are more than 1 incoming for the number:
   *   - if there are no outgoing for the number: tag contact as SMS Chaos 5
   *   - if there are outgoing: tag contact as SMS Chaos 3
   *
   * @param $sender
   */
  public function labelFromPsiWinCom($sender) {
    $countOutgoing = $this->countTotalOutForPhone($sender);
    $countIncoming = $this->countTotalInForPhone($sender);
    switch ($countIncoming) {
      case 1:
        switch ($countOutgoing) {
          case 0:
            $this->labelContact('4', $sender);
            break;
          case 1:
            $this->labelContact('2', $sender);
            break;
          default:
            $this->labelContact('1', $sender);
            break;
        }
        break;
      default:
        switch ($countOutgoing) {
          case 0:
            $this->labelContact('5', $sender);
            break;
          default:
            $this->labelContact('3', $sender);
            break;
        }
        break;
    }
  }

  /**
   * Method to count all incoming SMS for phone in log
   * @param $phone
   * @return string
   */
  private function countTotalInForPhone($phone) {
    $sql = 'SELECT COUNT(*) FROM psiwincom_log WHERE sender = %1';
    return CRM_Core_DAO::singleValueQuery($sql, array(1 => array($phone, 'String')));
  }

  /**
   * Method to count all outgoing SMS for phone in log
   * @param $phone
   * @return string
   */
  private function countTotalOutForPhone($phone) {
    $sql = 'SELECT COUNT(*) FROM psiwincom_log WHERE receiver = %1';
    return CRM_Core_DAO::singleValueQuery($sql, array(1 => array($phone, 'String')));
  }

  /**
   * Method to find the contact with the phone number
   *
   * @param $phone
   * @return int|bool
   */
  private function findContactWithPhone($phone) {
    $sql = 'SELECT contact_id FROM civicrm_phone WHERE phone = %1 LIMIT 1';
    $contactId = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($phone, 'String')));
    if ($contactId) {
      return (int)$contactId;
    } else {
      return FALSE;
    }
  }

  /**
   * Method to label the contact
   *
   * @param $labelId
   * @param $sender
   */
  private function labelContact($labelId, $sender) {
    $contactId = $this->findContactWithPhone($sender);
    if ($contactId) {
      civicrm_api3('EntityTag', 'create', array(
        'tag_id' => $this->_labelTags[$labelId],
        'contact_id' => $contactId
      ));
    }
  }
}