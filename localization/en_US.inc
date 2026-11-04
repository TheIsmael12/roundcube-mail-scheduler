<?php

/**
 * Mail Scheduler · English (US)
 */

$labels = [];

// Compose
$labels['schedule']          = 'Schedule';
$labels['schedulesend']      = 'Schedule send';
$labels['scheduletitle']     = 'Choose when this message will be sent';
$labels['schedulebutton']    = 'Schedule send';
$labels['quickpicks']        = 'Suggestions';
$labels['quick_in1h']        = 'In one hour';
$labels['quick_afternoon']   = 'This afternoon';
$labels['quick_tomorrow']    = 'Tomorrow morning';
$labels['quick_tomorrowpm']  = 'Tomorrow afternoon';
$labels['quick_monday']      = 'Monday morning';
$labels['customdatetime']    = 'Custom date and time';
$labels['date']              = 'Date';
$labels['time']              = 'Time';
$labels['repeat']            = 'Repeat';
$labels['rec_none']          = 'Does not repeat';
$labels['rec_daily']         = 'Every day';
$labels['rec_weekdays']      = 'Every weekday (Monday to Friday)';
$labels['rec_weekly']        = 'Every week';
$labels['rec_monthly']       = 'Every month';
$labels['rec_short_daily']    = 'Daily';
$labels['rec_short_weekdays'] = 'Weekdays';
$labels['rec_short_weekly']   = 'Weekly';
$labels['rec_short_monthly']  = 'Monthly';
$labels['repeatuntil']       = 'Repeat until';
$labels['repeatuntilhint']   = 'Optional. Leave empty to repeat forever.';
$labels['signature']         = 'Signature';
$labels['sig_keep']          = 'Keep current signature';
$labels['sig_none']          = 'No signature';
$labels['sig_of']            = 'Signature of $name';
$labels['sig_preview']       = 'Signature preview';
$labels['nosignatures']      = 'You have no saved signatures. You can create them in Settings › Identities.';
$labels['willbesent']        = 'Will be sent on $date';
$labels['willrepeat']        = 'First delivery on $date · $rec';
$labels['timezonehint']      = 'Time zone: $tz';
$labels['scheduledfor']      = 'Scheduled for $date';
$labels['scheduledok']       = 'Message scheduled for $date.';
$labels['scheduledrecurringok'] = 'Recurring message scheduled. The first one goes out on $date.';

// Errors
$labels['invaliddatetime']   = 'Invalid date or time.';
$labels['datepast']          = 'Please choose a date and time in the future.';
$labels['datetoofar']        = 'Messages can be scheduled at most $n days ahead.';
$labels['invaliduntil']      = 'The repeat end date is invalid or earlier than the first delivery.';
$labels['toobig']            = 'The message is too big to be scheduled (maximum $size).';
$labels['nocredentials']     = 'Background delivery could not be prepared. Please log in again and retry.';
$labels['errorsaving']       = 'The scheduled message could not be saved.';
$labels['notfound']          = 'The item no longer exists.';
$labels['notEditable']       = 'This message can no longer be changed.';

// Settings · scheduled
$labels['scheduled']         = 'Scheduled messages';
$labels['scheduledtitle']    = 'Review and manage your scheduled messages';
$labels['filter_pending']    = 'Pending';
$labels['filter_sent']       = 'Sent';
$labels['filter_all']        = 'All';
$labels['listempty_pending'] = 'You have no scheduled messages. While composing, use “Schedule” to pick when a message goes out.';
$labels['listempty_sent']    = 'No scheduled message has been sent yet.';
$labels['listempty_all']     = 'There are no scheduled messages.';
$labels['to']                = 'To:';
$labels['status_pending']    = 'Pending';
$labels['status_retrying']   = 'Retrying';
$labels['status_sending']    = 'Sending';
$labels['status_sent']       = 'Sent';
$labels['status_failed']     = 'Failed';
$labels['status_cancelled']  = 'Cancelled';
$labels['sendon']            = 'Send on';
$labels['schedulefieldset']  = 'Schedule';
$labels['preview']           = 'Preview';
$labels['emptybody']         = '(Empty message)';
$labels['sentcount']         = 'Sent $n times';
$labels['sentcount1']        = 'Sent once';
$labels['lastsent']          = 'last sent: $date';
$labels['errorfailed']       = 'The message could not be sent.';
$labels['errorretry']        = 'Last attempt failed; it will be retried automatically.';
$labels['err_nocredentials'] = 'User credentials are missing. Log in again and press “Retry”.';
$labels['err_interrupted']   = 'Delivery was interrupted and may or may not have gone out. Check your Sent folder before retrying.';
$labels['reschedule']        = 'Save changes';
$labels['sendnow']           = 'Send now';
$labels['retry']             = 'Retry';
$labels['todraft']           = 'Edit message';
$labels['cancelsend']        = 'Cancel send';
$labels['confirmcancel']     = 'Do you really want to cancel this message? It will be deleted.';
$labels['confirmsendnow']    = 'The message will be sent within the next minute. Continue?';
$labels['confirmtodraft']    = 'The message will be moved to Drafts and opened in the editor. You will need to schedule it again. Continue?';
$labels['rescheduledok']     = 'Message rescheduled for $date.';
$labels['sendnowok']         = 'The message will go out within the next minute.';
$labels['cancelledok']       = 'Scheduled message cancelled.';
$labels['noselection']       = 'Select a message to see its details.';

// Settings · automatic replies
$labels['autoreplies']       = 'Automatic replies';
$labels['autorepliestitle']  = 'Out of office, vacation and other automatic replies';
$labels['arlistempty']       = 'You have no automatic replies yet. Press “Create” to let people know you are out of the office or on holiday.';
$labels['newautoreply']      = 'New automatic reply';
$labels['editautoreply']     = 'Edit automatic reply';
$labels['deleteautoreply']   = 'Delete';
$labels['confirmdeleteautoreply'] = 'Do you really want to delete this automatic reply?';
$labels['autoreplydeleted']  = 'Automatic reply deleted.';
$labels['autoreplyunnamed']  = 'Automatic reply';
$labels['ar_state_off']      = 'Disabled';
$labels['ar_state_active']   = 'Active now';
$labels['ar_state_scheduled'] = 'Scheduled';
$labels['ar_state_ended']    = 'Ended';
$labels['period_range']      = 'From $from to $until';
$labels['period_from']       = 'From $from, no end date';
$labels['period_until']      = 'Until $until';
$labels['period_always']     = 'Whenever it is enabled';
$labels['argeneral']         = 'General';
$labels['arperiod']          = 'Period';
$labels['armessagefs']       = 'Message';
$labels['aroptions']         = 'Options';
$labels['arname']            = 'Name';
$labels['arnameplaceholder'] = 'e.g. Summer holidays';
$labels['arenabled']         = 'Enabled';
$labels['arfrom']            = 'From';
$labels['arfromhint']        = 'Leave empty to start right now.';
$labels['aruntil']           = 'Until';
$labels['aruntilhint']       = 'Leave empty for no end date. Time zone: $tz';
$labels['aridentity']        = 'Reply as';
$labels['armessage']         = 'Text';
$labels['arhtml']            = 'HTML message';
$labels['arvarshint']        = 'You can use <code>{subject}</code> (original subject), <code>{sender}</code> (sender name), <code>{since}</code> and <code>{until}</code> (period dates).';
$labels['arinterval']        = 'Reply to each sender';
$labels['days']              = 'days at most once';
$labels['arintervalhint']    = 'Prevents sending the same reply to the same person several times.';
$labels['ar_only_direct']    = 'Only messages addressed to me';
$labels['ar_only_direct_hint'] = 'Replies only if your address is in To or Cc (not Bcc or mailing lists).';
$labels['ar_skip_bulk']      = 'Ignore lists and newsletters';
$labels['ar_skip_bulk_hint'] = 'Does not reply to mailing lists, newsletters, notifications or spam. Automatic messages and “no-reply” senders are always ignored.';
$labels['ar_save_sent']      = 'Save a copy in Sent';
$labels['ar_save_sent_hint'] = 'Stores every automatic reply in your Sent folder.';
$labels['ar_domains']        = 'Only these domains';
$labels['ar_domains_hint']   = 'One per line. Empty = reply to any domain.';
$labels['ar_exclude']        = 'Never reply to';
$labels['ar_exclude_hint']   = 'Addresses or domains, one per line. Wildcards allowed: *@vendor.com';
$labels['arcount']           = '$n replies sent';
$labels['arcount1']          = '1 reply sent';
$labels['aroverlap']         = 'Another active automatic reply overlaps this period. Only one should be active at a time.';
$labels['arnocredentials']   = 'Credential storage is disabled by the administrator: automatic replies cannot read your inbox.';
$labels['defaultarsubject']  = 'Out of office: {subject}';
$labels['defaultarbody']     = '<p>Hello {sender},</p><p>Thank you for your message. I am out of the office until {until} with limited access to email.</p><p>I will get back to you as soon as possible.</p>';
$labels['invalidfrom']       = 'The start date is invalid.';
$labels['untilbeforefrom']   = 'The end date must be later than the start date.';
$labels['invalididentity']   = 'Please choose a valid identity.';
$labels['emptyarbody']       = 'Please write the reply text.';

// Date formats (ICU)
$labels['date_pattern_short'] = "EEE, MMM d, y, h:mm a";
$labels['date_pattern_long']  = "EEEE, MMMM d, y 'at' h:mm a";

$messages = [];
