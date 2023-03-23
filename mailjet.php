<?php

require_once 'mailjet.civix.php';

/**
 * Implementation of hook_civicrm_alterMailParams( )
 * To add Mailjet headers in mail
 */
function mailjet_civicrm_alterMailParams(&$params, $context) {
  $jobId = CRM_Utils_Array::value('job_id', $params); //CiviCRM job ID
  if (isset($jobId)) {
    $customID = Civi::cache()->get('mailjet-custom-'. $jobId);
    if (!isset($customID)) {
      $customID = CRM_Mailjet_BAO_Event::getMailjetCustomCampaignId($jobId);
      Civi::cache()->set('mailjet-custom-'. $jobId, $customID);
    }
    $params['headers']['X-Mailjet-Campaign'] = $customID;
    $params['headers']['X-Mailjet-CustomValue'] = $customID;
    $params['headers']['X-Mailjet-Prio'] = 1; // this has to go batch
  } else {
    $params['headers']['X-Mailjet-Campaign'] = prepareTransactionalCampaign($params);
    $params['headers']['X-Mailjet-CustomValue'] = prepareTransactionalCampaign($params);
    $params['headers']['X-MJ-EventPayload'] = prepareEventPayload($params);
    $params['headers']['X-Mailjet-Prio'] = 2; // High priority queue
  }
  if (array_key_exists('Subject',$params) && substr($params['Subject'], 0, 16) === "[CiviMail Draft]") {
    $params['headers']['X-Mailjet-Prio'] = 3; // this has to go as fast as possible
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 *
 * Handler for pageRun hook.
 */
function mailjet_civicrm_pageRun(&$page) {
  if (get_class($page) == 'CRM_Mailing_Page_Report') {
    $mailingId = $page->_mailing_id;
    $mailingJobs = civicrm_api3('MailingJob', 'get', $params = array('mailing_id' => $mailingId));

    $stats = array(
      'BlockedCount' => 0,
      'BouncedCount' => 0,
      'ClickedCount' => 0,
      'DeliveredCount' => 0,
      'OpenedCount' => 0,
      'ProcessedCount' => 0,
      'QueuedCount' => 0,
      'SpamComplaintCount' => 0,
      'UnsubscribedCount' => 0,
    );
    foreach ($mailingJobs['values'] as $key => $job) {
      if ($job['job_type'] == 'child') {
        $jobId = $key;
        require_once('packages/mailjet-0.3/php-mailjet-v3-simple.class.php');
        // Create a new Mailjet Object
        $mj = new Mailjet(MAILJET_API_KEY, MAILJET_SECRET_KEY);
        $mj->debug = 0;
        $mailJetParams = array(
         'method' => 'VIEW',
         'unique' => CRM_Mailjet_BAO_Event::getMailjetCustomCampaignId($jobId),
        );
        $response = $mj->campaign($mailJetParams);
        $page->assign('mailjet_params', $mailJetParams);

        if (!empty($response)) {
          if ($response->Count == 1) {
            $campaign = $response->Data[0];
            $mailJetParams = array(
              'method' => 'VIEW',
              'unique' => $campaign->ID,
            );
            $response = $mj->campaignstatistics($mailJetParams);
            if ($response->Count == 1) {
              $stats = sumUpStats($stats, get_object_vars($response->Data[0]));
            }
          }
        }
      }
    }
    $page->assign('mailjet_stats', $stats);
    CRM_Core_Region::instance('page-header')->add(array(
      'template' => 'CRM/Mailjet/Page/Report.tpl',
    ));
  }
}

/**
 * Implementation of hook_civicrm_config
 */
function mailjet_civicrm_config(&$config) {
  _mailjet_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 */
function mailjet_civicrm_install() {
  return _mailjet_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function mailjet_civicrm_uninstall() {

  return _mailjet_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function mailjet_civicrm_enable() {
  return _mailjet_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function mailjet_civicrm_disable() {
  return _mailjet_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function mailjet_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _mailjet_civix_civicrm_upgrade($op, $queue);
}

function sumUpStats($base, $newStats) {
  $keys = array(
    'BlockedCount',
    'BouncedCount',
    'ClickedCount',
    'DeliveredCount',
    'OpenedCount',
    'ProcessedCount',
    'QueuedCount',
    'SpamComplaintCount',
    'UnsubscribedCount',
  );
  foreach ($keys as $key) {
    if (array_key_exists($key, $base) && array_key_exists($key, $newStats)) {
      $base[$key] += $newStats[$key];
    }
  }
  return $base;
}

function prepareTransactionalCampaign($params) {
  $activityId = (int) CRM_Utils_Array::value('custom-activity-id', $params);
  $campaignId = (int) CRM_Utils_Array::value('custom-campaign-id', $params);
  $from = CRM_Utils_Array::value('from', $params);
  if ($activityId || $campaignId) {
    return 'TRANS-ACTIVITY-' . $activityId . '-CAMPAIGN-' . $campaignId;
  }
  return 'TRANS-FROM-' . $from;
}

function prepareEventPayload($params) {
  return json_encode([
    'jobId' => (int) CRM_Utils_Array::value('job_id', $params),
    'activityId' => (int) CRM_Utils_Array::value('custom-activity-id', $params),
    'campaignId' => (int) CRM_Utils_Array::value('custom-campaign-id', $params),
  ]);
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function mailjet_civicrm_postInstall() {
  _mailjet_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function mailjet_civicrm_entityTypes(&$entityTypes) {
  _mailjet_civix_civicrm_entityTypes($entityTypes);
}
