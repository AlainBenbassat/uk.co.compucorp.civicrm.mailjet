CiviMailJet - MailJet integration for CiviCRM 
===============================

## Features
This extension allows to automatically update the number of bounces for civimail mailings. As with the standard civiway of handing bounces, it automatically flag invalid emails "on hold". You DO NOT have to configure or run the cronjob that fetch the bounces from a mailbox, mailjet directly sends these events to civicrm using the endpoint provided by this extension.

Thanks to [WeMove.EU](https://www.wemove.eu) contribution, this extension also flags transactional emails (eg. automatic emails sent to confirm a donation or event registration, request to confirm their subscriptions...). In order to do that, it automatically create a fake campaign on mailjet with the sender email as its name.

Another major change is that the extension stores received event in its own table only for event types currently not supported, or when something wrong happens. Otherwise, only Civicrm standard tables are used. This applies for `sent ` events, where the extension stores the mailjet send time in place of the civiCRM one, and keeps the CiviCRM one in a new column `original_time_stamp' (created by the install script).

##setup for mailjet
you do not need that extension to send emails, simply use mailjet smtp interface (using the api key as login and secret as password, as explained on their site)

You should be able to send emails and see on mailjet site how many were sent.

##Setup instructions for CiviMailjet extensions

1. Download and install the extension into the site extension directory.
2. Set up the CiviCRM Outbond email using SMTP  with  Mailjet's SMTP Credentials.
3. Config Event Tracking Endpoint Url in your Mailjet account using http://<yoursite>/civicrm/mailjet/event/endpoint
Do not trigger events for open and click if you expect to handle any big mailinngs
4. Add add the code below into the site civicrm settings file and put your mailjet api and secret key

```
define( 'MAILJET_API_KEY', 'YOUR MAILJET API KEY');<br/>
define( 'MAILJET_SECRET_KEY', 'YOUR MAILJET SECRET KEY');
```

## Read events from an AMQP broker
To increase the response time to mailjet events, you can send them to an AMQP broker and dispatch them to this extension.

### Set up

 - Make sure you have [composer](https://getcomposer.org/) installed, or download it to the `amqp` directory of this extension.
 - Create in the `amqp` directory a file named `sitepath.inc` that contains the path to your drupal site (without trailing slash)
 - Update you CiviCRM settings with the following constants, depending on your AMQP server:
   + MAILJET_AMQP_HOST
   + MAILJET_AMQP_PORT
   + MAILJET_AMQP_USER
   + MAILJET_AMQP_PASSWORD
   + MAILJET_AMQP_VHOST
 - From the amqp directory, run `php composer.phar install` (adapt if you have a global composer)

adapt and copy doc/mailjet_amqp.conf into /etc/init/mailjet_amqp.conf

### Run
On ubuntu/production:
service mailjet_amqp start

Manually:
From the `amqp` directory: `php consumer.php -q name_of_queue`
The script does not ensure that the queue exists before reading from it.
The script consumes messages only when the load on the server is lower than MAILJET_MAX_LOAD.

#TODO (PR welcome)
the latest version of mailjet API allows to group events, instead of calling the endpoint for every event. This shouldn't be too hard to implement and would make me very happy if you contribute that part.

Verifying who is calling the endpoint could be better done...

X+
