<?php

App::uses("CoJobBackend", "Model");

class ResendNotificationJob extends CoJobBackend {
  // Required by COmanage Plugins
  public $cmPluginType = "job";

  // Document foreign keys
  public $cmPluginHasMany = array();

  // Validation rules for table elements
  public $validate = array();

  // Current CO Job Object
  private $CoJob;

  // Current CO ID
  private $coId;

  /**
   * Expose menu items.
   * 
   * @return Array with menu location type as key and array of labels, controllers, actions as values.
   */
  public function cmPluginMenus() {
    return array();
  }

  /**
   * Execute the requested Job.
   *
   * @param  int   $coId    CO ID
   * @param  CoJob $CoJob   CO Job Object, id available at $CoJob->id
   * @param  array $params  Array of parameters, as requested via parameterFormat()
   * @throws InvalidArgumentException
   * @throws RuntimeException
   * @return void
   */
  public function execute($coId, $CoJob, $params) {
    $CoJob->update($CoJob->id, null, "full", null);

    $this->CoJob = $CoJob;
    $this->coId = $coId;

    $hours = $params['hours'];

    $resendCount = 0;

    // Find existing notifications that are pending resolution and action
    // is CPUP (CoPetitionUpdated).

    $args = array();
    $args['conditions']['CoNotificationRecipient.status'] = NotificationStatusEnum::PendingResolution;
    $args['conditions']['CoNotificationRecipient.action'] = ActionEnum::CoPetitionUpdated;
    $args['contain'][] = 'RecipientCoPerson';
    $args['contain'][] = 'RecipientCoGroup';

    $notifications = $this->CoJob->Co->CoPerson->CoNotificationRecipient->find('all', $args);

    foreach($notifications as $notification) {
      $notificationCoId = $notification['RecipientCoPerson']['co_id'] ?? $notification['RecipientCoGroup']['co_id'] ?? null;

      // Skip notifications not for this CO.
      if(empty($notificationCoId) || ($notificationCoId != $coId)) {
        continue;
      }

      $notificationTime = strtotime($notification['CoNotificationRecipient']['notification_time']);
      $now = time();
      $delta = $hours * 3600;

      // Skip notifications not far enough in the past.
      if(($notificationTime + $delta) > $now) {
        continue;
      }

      // Compute the recipients for the notification.
      $recipients = array();

      if(!empty($notification['RecipientCoPerson']['id'])) {
        // Recipient is a single CO Person.
        // TODO
      } else if (!empty($notification['RecipientCoGroup']['id'])) {
        // Recipients computed from a CO Group.
        $groupId = $notification['RecipientCoGroup']['id'];
        $recipients = $this->recipientsByGroupId($groupId);
      }

      // Email each recipient.
      foreach($recipients as $recipient) {
        $subject = $notification['CoNotificationRecipient']['email_subject'];
        $body = $notification['CoNotificationRecipient']['email_body'];
        $notificationId = $notification['CoNotificationRecipient']['id'];

        list($email, $success) = $this->mailRecipient($recipient, $subject, $body);

        if($success) {
          $resendCount += 1;
          if(!empty($email)) {
            $comment = "Resent email to $email";
            $this->CoJob->CoJobHistoryRecord->record($this->CoJob->id, $email, $comment, null, null, JobStatusEnum::Complete);
          }

          // Update the timestamp on the existing notification.
          $this->CoJob->Co->CoPerson->CoNotificationRecipient->clear();
          $this->CoJob->Co->CoPerson->CoNotificationRecipient->id = $notificationId;
          $this->CoJob->Co->CoPerson->CoNotificationRecipient->saveField('notification_time', date('Y-m-d H:i:s', $now));
        } else {
          $comment = "Failed to resend notification with ID $notificationId";
          $key = $email ?? "unknown";
          $this->CoJob->CoJobHistoryRecord->record($this->CoJob->id, $key, $comment, null, null, JobStatusEnum::Failed);
        }
      }
    }

    $summary = "$resendCount email notifications resent";
    $status = JobStatusEnum::Complete;

    $CoJob->finish($CoJob->id, $summary, $status);
  }

  /**
   * @since  COmanage Registry v4.0.0
   * @return Array Array of supported parameters.
   */

  public function getAvailableJobs() {
    $availableJobs = array();

    $availableJobs['ResendNotification'] = "Process stale notifications";

    return $availableJobs;
  }

  /**
   * @since COmanage Registry 4.1.2
   * @return Array Array of email address and boolean success
   *
   */
  protected function mailRecipient($recipient, $subject, $body) {
    $toaddr = null;
    $success = false;

    if(!empty($recipient['EmailAddress'][0]['mail'])) {
      // Send email, if we have an email address
      // Which email address do we use? for now, the first one (same as in processResolution())
      // (ultimately we probably want the first address of type delivery)
      $toaddr = $recipient['EmailAddress'][0]['mail'];
    }

    if($toaddr) {
      try {
        $email = new CakeEmail('default');

        $email->template('custom', 'basic')
          ->emailFormat(MessageFormatEnum::Plaintext)
          ->to($toaddr)
          ->viewVars(array(MessageFormatEnum::Plaintext => $body))
          ->subject($subject);

        $email->send();
        $success = true;
      } catch(Exception $e) {
        $this->log("Caught exception sending email: " . print_r($e, true));
      }
    }

    return(array($toaddr, $success));
  }

  /**
   * Obtain the list of parameters supported by this Job.
   *
   * @since  COmanage Registry v3.3.0
   * @return Array Array of supported parameters.
   */
  public function parameterFormat() {
    $params = array(
      'hours' => array(
        'help' => _txt('pl.resendnotificationjob.job.resend.hours'),
        'type' => 'int',
        'required' => true
      )
    );

    return $params;
  }

  /**
   * @since COmanage Registry v4.1.2
   * @return Array Array of CO Person recipients with email addresses
   */
  protected function recipientsByGroupId($id) {
    $recipients = array();

    // This block is taken from the CoNotification model in the register function
    // from when recipientType == 'cogroup'.
    $args = array();
    $args['conditions']['RecipientCoGroup.id'] = $id;
    $args['conditions']['RecipientCoGroup.status'] = SuspendableStatusEnum::Active;
    $args['contain'] = array(
      'CoGroupMember' => array(
        // We only want group members, not owners
        'conditions' => array(
          'CoGroupMember.member' => true,
          'AND' => array(
            array('OR' => array(
              'CoGroupMember.valid_from IS NULL',
              'CoGroupMember.valid_from < ' => date('Y-m-d H:i:s', time())
            )),
            array('OR' => array(
              'CoGroupMember.valid_through IS NULL',
              'CoGroupMember.valid_through > ' => date('Y-m-d H:i:s', time())
            ))
          )
        ),
        'CoPerson' => array(
          // We only want active people BUT this condition seems to get overwritten
          // in ChangelogBehavior (beforeFind - modifyContain) and is therefore not applied
          // 'conditions' => array('CoPerson.status' => StatusEnum::Active),
          'EmailAddress'
        )
      )
    );
    
    $gr = $this->CoJob->Co->CoPerson->CoNotificationRecipient->RecipientCoGroup->find('first', $args);
    
    if(!empty($gr['CoGroupMember'])) {
      foreach($gr['CoGroupMember'] as $gm) {
        if(!empty($gm['CoPerson']) 
          && $gm['CoPerson']['status'] == StatusEnum::Active) {
          // Move EmailAddress up a level, as for 'coperson'
          $recipients[] = array(
            'RecipientCoPerson' => $gm['CoPerson'],
            'EmailAddress'      => (!empty($gm['CoPerson']['EmailAddress'])
                                    ? $gm['CoPerson']['EmailAddress']
                                    : array())
          );
        }
      }
    }

    return($recipients);
  }
}
