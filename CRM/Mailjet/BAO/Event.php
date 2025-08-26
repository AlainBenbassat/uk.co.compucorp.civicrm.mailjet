<?php

class CRM_Mailjet_BAO_Event extends CRM_Mailjet_DAO_Event {

  /**
   * @param $jobId
   *
   * @return string
   * @throws \CiviCRM_API3_Exception
   */
  public static function getMailjetCustomCampaignId($jobId) {
    if ($jobId !== NULL) {
      $mailingJob = civicrm_api3('MailingJob', 'get', $params = array('id' => $jobId));
      if ($mailingJob['values'][$jobId]['job_type'] == 'child') {
        $timestamp = strtotime($mailingJob['values'][$jobId]['scheduled_date']);
        return $jobId . 'MJ' . $timestamp;
      }
    }
    return 0 . 'MJ' . strtotime("now");
  }

  /**
   * @param integer $jobId
   *
   * @return string
   */
  public static function getMailjetCampaign($jobId) {
    if ($jobId) {
      $query = "SELECT CONCAT('ID', mj.mailing_id, 'NM', m.name) mailjet_campaign
                FROM civicrm_mailing_job mj
                  JOIN civicrm_mailing m ON mj.mailing_id = m.id
                WHERE mj.id = %1";
      $params = [
        1 => [$jobId, 'Integer'],
      ];
      $mailjetCampaign = CRM_Core_DAO::singleValueQuery($query, $params);
      if ($mailjetCampaign) {
        return $mailjetCampaign;
      }
      return $jobId . 'MJ' . strtotime("now");
    }

    return 0 . 'MJ' . strtotime("now");
  }

  /**
   * Store a raw event in the mailjet table
   *
   * @param \CRM_Mailjet_Logic_Message $message
   */
  public static function createFromPostData(CRM_Mailjet_Logic_Message $message) {
    $mailjetEvent = new CRM_Mailjet_DAO_Event();
    $mailjetEvent->mailing_id = $message->mailingId;
    $mailjetEvent->email = $message->email;
    $mailjetEvent->event = $message->event;
    $mailjetEvent->mj_campaign_id = $message->mailjetCampaignId;
    $mailjetEvent->mj_contact_id = $message->mailjetContactId;
    $mailjetEvent->time = $message->time;
    $mailjetEvent->data = serialize($message->trigger);
    $mailjetEvent->created_date = date('YmdHis');
    $mailjetEvent->save();
  }

  /**
   * @param $params
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public static function recordBounce($params) {
    $isSpam = CRM_Utils_Array::value('is_spam', $params);
    $contactId = CRM_Utils_Array::value('contact_id', $params);
    $emailId = CRM_Utils_Array::value('email_id', $params);
    $jobId = CRM_Utils_Array::value('job_id', $params);
    $eqParams = array(
      'job_id' => $jobId,
      'contact_id' => $contactId,
      'email_id' => $emailId,
    );
    $eventQueue = CRM_Mailing_Event_BAO_Queue::create($eqParams);
    $time = date('YmdHis', CRM_Utils_Array::value('date_ts', $params));
    $bounceType = array();
    // fixme deprecated function
    CRM_Core_PseudoConstant::populate($bounceType, 'CRM_Mailing_DAO_BounceType', TRUE, 'id', NULL, NULL, NULL, 'name');
    if ($isSpam) {
      $bounceTypeId = $bounceType[CRM_Mailjet_Upgrader::SPAM];
      $bounceReason = CRM_Utils_Array::value('source', $params); //bounce reason when spam occured
    }
    else {
      $hardBounce = CRM_Utils_Array::value('hard_bounce', $params);
      $blocked = CRM_Utils_Array::value('blocked', $params); //  blocked : true if this bounce leads to recipient being blocked
      if ($hardBounce && $blocked) {
        $bounceTypeId = $bounceType[CRM_Mailjet_Upgrader::BLOCKED];
      }
      elseif ($hardBounce && !$blocked) {
        $bounceTypeId = $bounceType[CRM_Mailjet_Upgrader::HARD_BOUNCE];
      }
      else {
        if (self::isHardError($params)) {
          $bounceTypeId = $bounceType[CRM_Mailjet_Upgrader::HARD_BOUNCE];
        }
        else {
          $bounceTypeId = $bounceType[CRM_Mailjet_Upgrader::SOFT_BOUNCE];
        }
      }
      $bounceReason = $params['error_related_to'] . " - " . $params['error'];
    }
    $bounceParams = [
      'job_id' => $jobId,
      'event_queue_id' => $eventQueue->id,
      'hash' => $eventQueue->hash,
      'time_stamp' => $time,
      'bounce_type_id' => $bounceTypeId,
      'bounce_reason' => $bounceReason,
    ];
    CRM_Mailing_Event_BAO_Bounce::create($bounceParams);
    if ($bounceTypeId != $bounceType[CRM_Mailjet_Upgrader::SOFT_BOUNCE]) {
      $params = array(
        'id' => $contactId,
        'do_not_email' => 1,
      );
      civicrm_api3('Contact', 'create', $params);
    }
    return TRUE;
  }

  /**
   * Check if error should be hard bounce.
   *
   * @param $params
   *
   * @return bool
   */
  private static function isHardError($params) {
    $error = CRM_Utils_Array::value('error', $params);
    $hardErrors = [
      'invalid domain',
      'relay/access denied',
      'typofix',
    ];
    return in_array($error, $hardErrors);
  }

}
