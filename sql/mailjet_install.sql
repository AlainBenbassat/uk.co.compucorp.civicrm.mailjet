
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `civicrm_mailing_mailjet_event`;

SET FOREIGN_KEY_CHECKS=1;
-- /*******************************************************
-- *
-- * Create new tables
-- *
-- *******************************************************/

-- /*******************************************************
-- *
-- * civicrm_mailing_mailjet_event
-- *
-- *******************************************************/
CREATE TABLE `civicrm_mailing_mailjet_event` (


     `id` int unsigned NOT NULL AUTO_INCREMENT  ,
     `mailing_id` int unsigned    COMMENT 'FK to mailing ID and customcampiang on Mailjet',
     `email` varchar(255) NOT NULL   COMMENT 'Email address of recipient triggering the event',
     `event` varchar(255) NOT NULL   ,
     `mj_campaign_id` bigint unsigned    COMMENT 'The mailjet campaing _id',
     `mj_contact_id` int unsigned    COMMENT 'The mailjet campaing _id',
     `time` datetime NOT NULL   COMMENT 'Unix timestamp of event (free of timezone concerns)',
     `data` text    COMMENT 'Mailjet row data',
     `created_date` datetime NOT NULL
,
    PRIMARY KEY ( `id` )



)  ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci  ;



-- alter civicrm_mailing_bounce_type to add Mailjet's bounce types to enum
ALTER TABLE `civicrm_mailing_bounce_type`
  CHANGE `name` `name` ENUM( 'AOL', 'Away', 'DNS', 'Host', 'Inactive', 'Invalid', 'Loop', 'Quota', 'Relay', 'Spam', 'Syntax', 'Unknown',
    'Mailjet Soft Bounces', 'Mailjet Hard Bounces', 'Mailjet Blocked', 'Mailjet Spam' )
    CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL COMMENT 'Type of bounce';

-- This column will receive the civi timestamp, when we receive the mailjet one
ALTER TABLE civicrm_mailing_event_delivered ADD COLUMN (original_time_stamp DATETIME);

# change logic of mailjet timestamp:
ALTER TABLE civicrm_mailing_event_delivered ADD COLUMN mailjet_time_stamp DATETIME DEFAULT '1970-01-01' COMMENT 'Datetime of sent event which mailjet endpoint received from mailjet service';
ALTER TABLE civicrm_mailing_event_delivered ADD INDEX civicrm_mailing_event_delivered_mailjet_time_stamp_inx (mailjet_time_stamp);

-- move mailjet event time to new column mailjet_time_stamp
# UPDATE civicrm_mailing_event_delivered
# SET mailjet_time_stamp = time_stamp
# WHERE original_time_stamp > '1970-01-01';

-- restore original (civicrm) delivered time to column time_stamp
# UPDATE civicrm_mailing_event_delivered
# SET time_stamp = original_time_stamp
# WHERE original_time_stamp > '1970-01-01';

-- remove unnecessary column
# ALTER TABLE civicrm_mailing_event_delivered DROP COLUMN original_time_stamp;
