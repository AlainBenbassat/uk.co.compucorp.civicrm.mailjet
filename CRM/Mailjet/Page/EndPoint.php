<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.4                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2013                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

require_once 'CRM/Core/Page.php';

class CRM_Mailjet_Page_EndPoint extends CRM_Core_Page {

  /**
   * @return null|void
   * @throws \CiviCRM_API3_Exception
   */
  public function run() {
    $post = trim(file_get_contents('php://input'));
    if (empty($post)) {
      header('HTTP/1.1 421 No Event');
      CRM_Core_Error::debug_var("ENDPOINT EVENT", "Needs to be called by mailjet", TRUE, TRUE);
      return;
    }

    $httpHeader = CRM_Mailjet_Page_EndPoint::processMessage($post);
    header($httpHeader);
    CRM_Utils_System::civiExit();
  }

  /**
   * @param $msg
   *
   * @return string HTTP STATUS code
   * @throws \CiviCRM_API3_Exception
   */
  public function processMessage($msg) {

    $message = new CRM_Mailjet_Logic_Message($msg);
    if (!$message->isValid()) {
      CRM_Core_Error::debug_var("ENDPOINT EVENT", "Invalid JSON or no event", TRUE, TRUE);
      return 'HTTP/1.1 422 Not ok';
    }

    if ($message->isTransactional()) {

      // run hook for transactional mailings
      CRM_Mailjet_Hooks::handle_transactional_event($msg);

      $allowedEvents = array('bounce', 'blocked', 'spam', 'unsub');
      if (!in_array($message->event, $allowedEvents)) {
        return 'HTTP/1.1 200 Ok';
      }

      $emailValues = $this->findEmail($message->email);
      if ($emailValues) {
        foreach ($emailValues as $email) {
          $emailId = $email['id'];
          $contactId = $email['contact_id'];
          switch ($message->event) {
            case 'bounce':
              $this->createBounceActivity($message, $contactId);
              if ($message->hard_bounce) {
                $this->setOnHoldHard($emailId, $message->email);
                // shouldn't also set as is_opt_out? the same as on unsub event type
                // $this->setOptOut($contactId);
              }
              // fixme what with else?
              break;

            case 'spam':
              $this->createBounceActivity($message, $contactId);
              $this->setOnHoldHard($emailId, $message->email);
              // shouldn't also set as is_opt_out? the same as on unsub event type
              // $this->setOptOut($contactId);
              break;

            case 'unsub':
              $this->setOnHoldHard($emailId, $message->email);
              $this->setOptOut($contactId);
              break;
          }
        }
      }

      if ($message->event == 'bounce' || $message->event == 'blocked') {
        $this->setUnreachableActivity($message);
      }

      return 'HTTP/1.1 200 Ok';
    }

    if ($message->isMailing()) {

      // run hook for mass-mailings
      CRM_Mailjet_Hooks::handle_mailing_event($msg);

      /* https://www.mailjet.com/docs/event_tracking for more informations. */
      switch ($message->event) {
        //For unsupported events, we just store them raw
        case 'open':
        case 'click':
        case 'typofix':
          CRM_Mailjet_BAO_Event::createFromPostData($message);
          return 'HTTP/1.1 200 Ok';

        //We replace the civi delivery time with the mailjet one
        //but keep the civi one for comparison
        case 'sent':
          $emailValues = $this->findEmail($message->email, FALSE);
          if ($emailValues) {
            foreach ($emailValues as $email) {
              CRM_Mailjet_Page_EndPoint::updateDelivery($message, $email['id']);
            }
          }
          else {
            $this->logUnkownAddress($message);
          }
          return 'HTTP/1.1 200 Ok';

        //we treat bounce, span and blocked as bounce mailing in CiviCRM
        case 'bounce':
        case 'spam':
        case 'blocked':
          $emailValues = $this->findEmail($message->email);
          if ($emailValues) {
            foreach ($emailValues as $email) {
              $params = CRM_Mailjet_Page_EndPoint::prepareBounceParams($message, $email['id'], $email['contact_id']);
              CRM_Mailjet_BAO_Event::recordBounce($params);
            }
          }
          else {
            $this->logUnkownAddress($message);
          }
          return 'HTTP/1.1 200 Ok';

        case 'unsub':
          CRM_Mailjet_BAO_Event::createFromPostData($message);
          $emailValues = $this->findEmail($message->email);
          if ($emailValues) {
            foreach ($emailValues as $email) {
              $emailId = $email['id'];
              $contactId = $email['contact_id'];
              $this->setOnHoldHard($emailId, $message->email);
              $this->setOptOut($contactId);
            }
          }
          else {
            $this->logUnkownAddress($message);
          }
          return 'HTTP/1.1 200 Ok';
          break;

        default:
          CRM_Core_Error::debug_var("MAILJET TRIGGER", "No handler for $message->event", TRUE, TRUE);
          return 'HTTP/1.1 422 unknown event';
      }
    }

    return 'HTTP/1.1 200 Ok';
  }

  private function logUnkownAddress($message) {
    //This shouldn't happen, let's log the event
    CRM_Mailjet_BAO_Event::createFromPostData($message);
    CRM_Core_Error::debug_var("MAILJET TRIGGER", "Unknown address $message->email event " . $message->event, TRUE, TRUE);
  }

  /**
   * @param \CRM_Mailjet_Logic_Message $message
   * @param $emailId
   */
  private function updateDelivery(CRM_Mailjet_Logic_Message $message, $emailId) {
    $query = "UPDATE civicrm_mailing_event_delivered d
              JOIN civicrm_mailing_event_queue q ON d.event_queue_id = q.id
              SET d.mailjet_time_stamp = %3
              WHERE q.job_id = %1 AND q.email_id = %2 AND d.mailjet_time_stamp = '1970-01-01'";
    $params = array(
      1 => array($message->job_id, 'Integer'),
      2 => array($emailId, 'Integer'),
      3 => array($message->time, 'String'),
    );
    CRM_Core_DAO::executeQuery($query, $params);
  }

  /**
   * @param \CRM_Mailjet_Logic_Message $message
   * @param $emailId
   * @param $contactId
   *
   * @return array
   */
  private function prepareBounceParams(CRM_Mailjet_Logic_Message $message, $emailId, $contactId) {
    $params = array(
      'mailing_id' => $message->mailingId,
      'contact_id' => $contactId,
      'email_id' => $emailId,
      'date_ts' => $message->date_ts,
    );
    $params['hard_bounce'] = $message->hard_bounce;
    $params['blocked'] = $message->blocked;
    $params['source'] = $message->source;
    $params['error_related_to'] = $message->error_related_to;
    $params['error'] = $message->error;
    $params['job_id'] = $message->job_id;
    $params['email'] = $message->email;
    $params['is_spam'] = ($message->event == 'spam');

    return $params;
  }

  /**
   * @param string $email
   * @param bool $contactIdIsNotNull
   *
   * @return mixed
   * @throws \CiviCRM_API3_Exception
   */
  private function findEmail($email, $contactIdIsNotNull = TRUE) {
    $emailParams = [
      'sequential' => 1,
      'email' => $email,
      'is_billing' => 0,
    ];
    if ($contactIdIsNotNull) {
      $emailParams['contact_id'] = ['IS NOT NULL' => 1];
    }
    $emailResult = civicrm_api3('Email', 'get', $emailParams);

    return $emailResult['values'];
  }

  /**
   * @param $emailId
   * @param $email
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function setOnHoldHard($emailId, $email) {
    $params = array(
      'sequential' => 1,
      'id' => $emailId,
      'email' => $email,
      'on_hold' => 2,
      'hold_date' => date('YmdHis'),
    );
    civicrm_api3('Email', 'create', $params);
  }

  /**
   * @param $contactId
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function setOptOut($contactId) {
    $params = array(
      'sequential' => 1,
      'id' => $contactId,
      'is_opt_out' => 1,
    );
    civicrm_api3('Contact', 'create', $params);
  }

  /**
   * @param \CRM_Mailjet_Logic_Message $message
   * @param $contactId
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function createBounceActivity(CRM_Mailjet_Logic_Message $message, $contactId) {
    $params = array(
      'sequential' => 1,
      'activity_type_id' => MAILJET_CIVICRM_BOUNCE_ACTIVITY, // Bounce
      'activity_date_time' => $message->time,
      'status_id' => 'Completed',
      'subject' => $message->event,
      'details' => $this->prepareDetails($message),
      'source_contact_id' => $contactId,
    );
    if ($message->campaignId) {
      $params['campaign_id'] = $message->campaignId;
    }
    civicrm_api3('Activity', 'create', $params);
  }

  /**
   * @param \CRM_Mailjet_Logic_Message $message
   *
   * @return string
   */
  private function prepareDetails(CRM_Mailjet_Logic_Message $message) {
    return 'Added by mailjet extension, error: '
      . $message->error_related_to
      . ', '
      . $message->error
      . '. blocked='
      . $message->blocked
      . '. hard_bounce='
      . $message->hard_bounce
      . ', json=' . $message->message;
  }

  /**
   * @param \CRM_Mailjet_Logic_Message $message
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function setUnreachableActivity(CRM_Mailjet_Logic_Message $message) {
    if ($message->activityId) {
      $params = array(
        'sequential' => 1,
        'id' => $message->activityId,
        'status_id' => 'Unreachable',
      );
      civicrm_api3('Activity', 'create', $params);
    }
  }

}
