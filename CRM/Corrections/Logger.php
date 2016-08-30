<?php

/**
 * Class for basic logging
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 6 Jul 2016
 * @license AGPL-3.0
 */
class CRM_Corrections_Logger {
  
  private $_logFile = null;

  /**
   * CRM_Corrections_Logger constructor.
   *
   * @param string $fileName
   */
  function __construct($fileName = '') {
    if (empty($fileName)) {
      $fileName = 'maf_correction';
    }
    $config = CRM_Core_Config::singleton();
    $runDate = new DateTime('now');
    $fileName = $config->configAndLogDir.$fileName."_".$runDate->format('YmdHis').'.log';
    $this->_logFile = fopen($fileName, 'w');
  }

  /**
   * Method to add message to logger
   * 
   * @param $type
   * @param $message
   */
  public function logMessage($type, $message) {
    $this->addMessage($type, $message);
  }

  /**
   * Method to log the message
   *
   * @param $type
   * @param $message
   */
  private function addMessage($type, $message) {
    fputs($this->_logFile, date('Y-m-d h:i:s'));
    fputs($this->_logFile, ' ');
    fputs($this->_logFile, $type);
    fputs($this->_logFile, ' ');
    fputs($this->_logFile, $message);
    fputs($this->_logFile, "\n");
  }
}