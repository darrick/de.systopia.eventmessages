<?php
/*-------------------------------------------------------+
| SYSTOPIA Event Messages                                |
| Copyright (C) 2020 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Eventmessages_ExtensionUtil as E;
use \Civi\EventMessages\MessageTokens as MessageTokens;

/**
 * Basic Logic for sending the actual email
 */
class CRM_Eventmessages_SendMail
{

    /**
     * Triggers the actual sending of a message (or at least it's scheduling)
     *
     * @param array $context
     *      some context information, see processStatusChange
     */
    public static function sendMessageTo($context)
    {
        try {
            // load some stuff via SQL
            $event = self::getEventData($context['event_id']);
            $data_query = self::buildDataQuery($context);
            $data  = CRM_Core_DAO::executeQuery($data_query);
            $template_id = empty($context['template_id']) ? $context['rule']['template'] : $context['template_id'];
            if ($data->fetch()) {
                // load participant
                $message_tokens = CRM_Eventmessages_Logic::generateTokenEvent($data->participant_id, $data->contact_id, $event);
                $message_tokens->setTemplateId($template_id);
                Civi::dispatcher()->dispatch('civi.eventmessages.tokens', $message_tokens);

                // and send the template via email
                $email_data = [
                    'id'        => $template_id,
                    'toName'    => $data->contact_name,
                    'toEmail'   => $data->contact_email,
                    'from'      => CRM_Utils_Array::value('event_messages_settings.event_messages_sender', $event, ''),
                    'replyTo'   => CRM_Utils_Array::value('event_messages_settings.event_messages_reply_to', $event, ''),
                    'cc'        => CRM_Utils_Array::value('event_messages_settings.event_messages_cc', $event, ''),
                    'bcc'       => CRM_Utils_Array::value('event_messages_settings.event_messages_bcc', $event, ''),
                    'contactId' => $data->contact_id,
                    'tplParams' => $message_tokens->getTokens(),
                ];

                // resolve/beautify sender (use name instead of value of the option_value)
                $from_addresses = CRM_Core_OptionGroup::values('from_email_address');
                if (isset($from_addresses[$email_data['from']])) {
                    $email_data['from'] = $from_addresses[$email_data['from']];
                } else {
                    $email_data['from'] = reset($from_addresses);
                }

                // send the mail
                Civi::log()->debug("EventMessages: Sending eventmessages mail to '{$data->contact_email}' from '{$email_data['from']}'");
                civicrm_api3('MessageTemplate', 'send', $email_data);

            } else {
                Civi::log()->warning("Couldn't send message to participant [{$context['participant_id']}], something is wrong with the data set.");
            }
        } catch (Exception $ex) {
            Civi::log()->warning("Couldn't send email to participant [{$context['participant_id']}], error was: " . $ex->getMessage());
        }
    }

    /**
     * Get all the necessary event data from the event.
     *  Will be cached
     *
     * @param integer $event_id
     *   Event ID
     *
     * @return array
     *   Event data
     */
    protected static function getEventData($event_id)
    {
        static $event_cache = [];
        if (!isset($event_cache[$event_id])) {
            $event = civicrm_api3('Event', 'getsingle', ['id' => $event_id]);
            CRM_Eventmessages_CustomData::labelCustomFields($event, 1, '__');
            $event_cache[$event_id] = $event;
        }
        return $event_cache[$event_id];
    }

    /**
     * Make sure that CiviCRM event mails will be suppressed if the event is configured is this way
     * This is achieved by wrapping the mailer object in a filter class
     *
     * @param object $mailer
     *      the currently used mailer, to be manipulated
     */
    public static function suppressSystemMails(&$mailer)
    {
        $mailer = new class($mailer) {
            public function __construct($mailer)
            {
                $this->mailer = $mailer;
            }

            /**
             * Implement the mailer's send function, so that
             *   system mails from events with active suppression will be dropped
             */
            function send($recipients, $headers, $body)
            {
                // go through the call stack, and see where this is coming from
                $callstack = debug_backtrace();
                foreach ($callstack as $call) {
                    if (isset($call['class']) && isset($call['function'])) {
                        // 1. check for emails coming through CRM_Event_BAO_Event::sendMessageTo
                        if ($call['class'] == 'CRM_Eventmessages_SendMail' && $call['function'] == 'sendMessageTo') {
                            break; // these are ours, continue to send
                        }

                        // 2. check for emails coming through CRM_Event_BAO_Event::sendMail
                        if ($call['class'] == 'CRM_Event_BAO_Event' && $call['function'] == 'sendMail') {
                            $participant_id = $call['args'][2];
                            if (CRM_Eventmessages_SendMail::suppressSystemEventMailsForParticipant($participant_id)) {
                                #Civi::log()->debug("EventMessages: CRM_Event_BAO_Event::sendMail detected!");
                                $this->logDroppedMail($recipients, $headers, $body);
                                return; // don't send
                            }
                            break; // no suppression, continue to send
                        }

                        // 3. check for mails coming from from the CRM_Event_Form_Participant form
                        //  note that this also triggers our own messages, but that was already dealt with in 1.
                        if ($call['class'] == 'CRM_Event_Form_Participant' && $call['function'] == 'submit') {
                            $participant_id = $call['object']->_id;
                            if (CRM_Eventmessages_SendMail::suppressSystemEventMailsForParticipant($participant_id)) {
                                #Civi::log()->debug("EventMessages: CRM_Event_Form_Participant::submit detected!");
                                $this->logDroppedMail($recipients, $headers, $body);
                                return; // don't send
                            }
                            break; // no suppression, continue to send
                        }
                    }
                }

                // we're done filtering -> send it already
                $this->mailer->send($recipients, $headers, $body);
            }

            /**
             * If we really drop/suppress a system mail, let's at least
             *   log something...
             */
            function logDroppedMail($recipients, $headers, $body)
            {
                $recipient_list = is_array($recipients) ? implode(';', $recipients) : $recipients;
                Civi::log()->debug("EventMessages: Suppressed CiviCRM event mail for recipients '{$recipient_list}'");
            }
        };
    }

    /**
     * Check whether CiviCRM's native event notifications should be suppressed
     *  for this participant/event
     *
     * @param integer $participant_id
     *   the participant ID
     *
     * @param integer $event_id
     *   the event ID
     *
     * @return boolean
     *   should the email be suppressed?
     */
    public static function suppressSystemEventMailsForParticipant($participant_id, $event_id = null)
    {
        static $cached_event_results = [];
        static $cached_participant_results = [];

        // if an event ID is given, use that
        $event_id = (int) $event_id;
        if ($event_id) {
            if (!isset($cached_event_results[$event_id])) {
                $cached_event_results[$event_id] = (boolean) CRM_Core_DAO::singleValueQuery("
                    SELECT settings.disable_default
                    FROM civicrm_value_event_messages_settings settings
                    WHERE settings.entity_id = {$event_id}");

//                Civi::log()->debug("EventMessages: suppress system messages for event [{$event_id}]: " .
//                                   ($cached_event_results[$event_id] ? 'yes' : 'no'));
            }
            return $cached_event_results[$event_id];
        }

        // otherwise, we have to work with the participant
        $participant_id = (int) $participant_id;
        if ($participant_id) {
            if (!isset($cached_participant_results[$participant_id])) {
                $cached_participant_results[$participant_id] = (boolean) CRM_Core_DAO::singleValueQuery("
                SELECT settings.disable_default
                FROM civicrm_participant participant
                LEFT JOIN civicrm_value_event_messages_settings settings
                       ON settings.entity_id = participant.event_id
                WHERE participant.id = {$participant_id}");

//                Civi::log()->debug("EventMessages: suppress system messages for participant [{$participant_id}]: " .
//                                   ($cached_participant_results[$participant_id] ? 'yes' : 'no'));
            }
            return $cached_participant_results[$participant_id];
        }

        return false;
    }

    /**
     * Build an SQL query to fetch the right data set,
     *  including contact_name, contact_id, contact_email
     *
     * @param array $context
     *      some context information, see processStatusChange
     *
     * @return string
     *      sql query to gather the data required for generating an email
     */
    protected static function buildDataQuery($context)
    {
        $participant_id = (int) $context['participant_id'];
        return "
                SELECT 
                  email.email          AS contact_email,
                  contact.display_name AS contact_name,
                  contact.id           AS contact_id,
                  participant.id       AS participant_id
                FROM civicrm_participant   participant
                INNER JOIN civicrm_contact contact  
                        ON contact.id = participant.contact_id
                INNER JOIN civicrm_email   email
                        ON email.contact_id = contact.id
                        AND (email.on_hold IS NULL OR email.on_hold = 0)  
                INNER JOIN civicrm_event   event
                        ON event.id = participant.event_id
                WHERE participant.id = {$participant_id}
                  AND (contact.is_deleted IS NULL OR contact.is_deleted = 0)
                ORDER BY email.is_primary DESC, email.is_bulkmail ASC, email.is_billing ASC
            ";
    }
}
