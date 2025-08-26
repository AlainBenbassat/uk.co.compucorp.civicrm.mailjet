<?php

class CRM_Mailjet_Logic_Message {
  public $event = '';
  public $email = '';

  /** @var int|mixed CiviCRM mailing id - is not exactly ID, this is CustomValue */
  public $mailingId = '';
  public $job_id = 0;
  public $activityId = 0;
  public $campaignId = 0;
  public $time = '';
  public $date_ts = '';
  public $mailjetCampaignId = '';
  public $mailjetContactId = '';
  public $hard_bounce = 0;
  public $blocked = 0;
  public $source = '';
  public $error_related_to = '';
  public $error = '';
  public $payload;
  public $message;
  public $trigger;

  function __construct($message) {
    $this->message = $message;
    $trigger = json_decode($message, true);
    $this->trigger = $trigger;
    $this->event = trim(CRM_Utils_Array::value('event', $trigger));
    $this->email = str_replace('"', '', trim(CRM_Utils_Array::value('email', $trigger)));
    $this->mailingId = CRM_Utils_Array::value('customcampaign', $trigger);
    $this->activityId = $this->getActivityId($trigger);
    $this->campaignId = $this->getCampaignId($trigger);
    $this->time = date('YmdHis', CRM_Utils_Array::value('time', $trigger));
    $this->date_ts = CRM_Utils_Array::value('time', $trigger);
    $this->hard_bounce = (int)CRM_Utils_Array::value('hard_bounce', $trigger);
    $this->blocked = (int)CRM_Utils_Array::value('blocked', $trigger);
    $this->source = CRM_Utils_Array::value('source', $trigger);
    $this->error_related_to = CRM_Utils_Array::value('error_related_to', $trigger);
    $this->error = CRM_Utils_Array::value('error', $trigger);
    $this->mailjetCampaignId = CRM_Utils_Array::value('mj_campaign_id', $trigger);
    $this->mailjetContactId = CRM_Utils_Array::value('mj_contact_id' , $trigger);
    $this->payload = CRM_Utils_Array::value('Payload', $trigger);
    if ($this->payload) {
      $this->payload = json_decode($this->payload);
      $this->job_id = (int) $this->payload->jobId;
    }
    else {
      $this->job_id = (int) explode('MJ', $this->mailingId)[0];
    }
  }

  public function isValid() {
    return !!$this->event;
  }

  /**
   * Check if message derived from transaction email.
   *
   * @return bool
   */
  public function isTransactional() {
    return (substr($this->mailingId, 0, 5) === "TRANS" || substr($this->mailingId, 0, 15) === "=?utf-8?Q?TRANS");
  }

  /**
   * Check if message derived from mass mailing.
   *
   * @return bool
   */
  public function isMailing() {
    return ($this->mailingId && $this->mailingId[0] != '0');
  }

  /**
   * Get activity id if it's possible
   * @param array $trigger
   *
   * @return int
   */
  private function getActivityId($trigger) {
    $customCampaign = CRM_Utils_Array::value('customcampaign', $trigger);
    $re = '/TRANS-ACTIVITY-([0-9]*)/';
    if (preg_match($re, $customCampaign, $matches)) {
      return $matches[1];
    }
    return 0;
  }

  /**
   * Get campaign id if it's possible
   * @param array $trigger
   *
   * @return int
   */
  private function getCampaignId($trigger) {
    $customCampaign = CRM_Utils_Array::value('customcampaign', $trigger);
    $re = '/TRANS-.*-CAMPAIGN-([0-9]*)$/';
    if (preg_match($re, $customCampaign, $matches)) {
      return $matches[1];
    }
    return 0;
  }
}
