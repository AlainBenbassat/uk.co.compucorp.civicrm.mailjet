<?php

require_once 'mailjet.civix.php';

/**
 * Implementation of hook_civicrm_alterMailParams( )
 * To add Mailjet headers in mail
 */
function mailjet_civicrm_alterMailParams(&$params, $context) {
  $jobId = CRM_Utils_Array::value('job_id', $params); //CiviCRM job ID
  if (isset($jobId)) {
    $key = 'mailjet-campaign-'. $jobId;
    $mailjetCampaign = Civi::cache()->get($key);
    if (!isset($mailjetCampaign)) {
      $mailjetCampaign = CRM_Mailjet_BAO_Event::getMailjetCampaign($jobId);
      Civi::cache()->set($key, $mailjetCampaign);
    }
    $params['headers']['X-Mailjet-Campaign'] = $mailjetCampaign;
    $params['headers']['X-Mailjet-CustomValue'] = $mailjetCampaign;
    $params['headers']['X-Mailjet-Prio'] = 1; // this has to go batch
  }
  else {
    $params['headers']['X-Mailjet-Campaign'] = prepareTransactionalCampaign($params);
    $params['headers']['X-Mailjet-CustomValue'] = prepareTransactionalCampaign($params);
    $params['headers']['X-Mailjet-Prio'] = 2; // High priority queue
  }
  $params['headers']['X-MJ-EventPayload'] = prepareEventPayload($params);
  if (array_key_exists('Subject',$params) && substr($params['Subject'], 0, 16) === "[CiviMail Draft]") {
    $params['headers']['X-Mailjet-Prio'] = 3; // this has to go as fast as possible
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 *
 * Handler for pageRun hook.
 */
/* function mailjet_civicrm_pageRun(&$page) {
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
} */



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
 * Implementation of hook_civicrm_enable
 */
function mailjet_civicrm_enable() {
  return _mailjet_civix_civicrm_enable();
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
  $current_payload = [];
  if (isset($params['headers']['X-MJ-EventPayload'])) {
    // decode current payload
    $current_payload = json_decode($params['headers']['X-MJ-EventPayload'], TRUE);
  }
  // add Event Payload
  $current_payload['jobId'] = (int) CRM_Utils_Array::value('job_id', $params);
  $current_payload['activityId'] = (int) CRM_Utils_Array::value('custom-activity-id', $params);
  $current_payload['campaignId'] = (int) CRM_Utils_Array::value('custom-campaign-id', $params);
  // return merged payload
  return json_encode($current_payload);
}
