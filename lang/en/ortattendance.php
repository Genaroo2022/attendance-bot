<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'ORT Attendance';
$string['modulenameplural'] = 'ORT Attendances';
$string['pluginname'] = 'ORT Attendance';
$string['pluginadministration'] = 'ORT Attendance administration';

// Configuration
$string['configuration'] = 'Configuration';
$string['camerarequired'] = 'Camera required';
$string['camerarequired_desc'] = 'Students must have camera on to be marked present';
$string['useemailmatching'] = 'Use email matching';
$string['useemailmatching_desc'] = 'Match participants by email address instead of name';
$string['minpercentage'] = 'Minimum attendance percentage';
$string['latetolerance'] = 'Late tolerance (minutes)';

// Date/Time
$string['datetimerange'] = 'Date and time range';
$string['startdate'] = 'Start date';
$string['enddate'] = 'End date';
$string['starttime'] = 'Start time';
$string['endtime'] = 'End time';

// Recordings
$string['recordingsbackup'] = 'Recording backup';
$string['backuprecordings'] = 'Backup recordings';
$string['backuprecordings_desc'] = 'Automatically backup Zoom recordings';
$string['deletefromsource'] = 'Delete from source';
$string['deletefromsource_desc'] = 'Delete recordings from Zoom after backup';

// Settings
$string['zoomsettings'] = 'Zoom API Settings';
$string['zoomsettings_desc'] = 'Configure Zoom OAuth credentials';
$string['zoomclientid'] = 'Zoom Client ID';
$string['zoomclientid_desc'] = 'OAuth Client ID from Zoom';
$string['zoomclientsecret'] = 'Zoom Client Secret';
$string['zoomclientsecret_desc'] = 'OAuth Client Secret from Zoom';
$string['zoomaccountid'] = 'Zoom Account ID';
$string['zoomaccountid_desc'] = 'Zoom Account ID for Server-to-Server OAuth';

$string['backupsettings'] = 'Backup Settings';
$string['backupsettings_desc'] = 'Configure recording backup options';
$string['localdirectory'] = 'Local directory';
$string['localdirectory_desc'] = 'Local path for storing recordings';
$string['backuplimit'] = 'Backup download limit';
$string['backuplimit_desc'] = 'Maximum number of recordings to download per scheduled task run';

// Zoom configuration
$string['zoomconfig'] = 'Zoom Configuration';
$string['zoomconfig_desc'] = 'ORT Attendance uses credentials from mod_zoom plugin. Please ensure mod_zoom is installed and configured with your Zoom OAuth Server-to-Server credentials (Client ID, Client Secret, Account ID).';

// View page strings (from attendancebot)
$string['viewtitle'] = 'ORT Attendance Bot Instructions';
$string['viewdescription1'] = 'ORT Attendance is a plugin that installs in a course and functions automatically in the background through a cron job. The cron job is implemented to run at 1am and starts a scheduler.';
$string['viewdescription2'] = 'The scheduler runs every 24 hours, and for each course where the plugin is installed, it starts an ad-hoc task, which is responsible for calculating attendance for people who belong to a course, for all groups.';
$string['viewinstructions'] = '
    <p>For correct use, you must configure the necessary settings through the form. To do this, go to the Settings tab, in the "ORT Attendance Configuration" section:</p>
    <ul>
        <li><strong>Camera required:</strong> If enabled, students must have their camera on to be marked present.</li>
        <li><strong>Minimum attendance percentage:</strong> Percentage value from 0 to 100% that indicates the minimum attendance required. This percentage is based on the meeting duration.</li>
        <li><strong>Late tolerance:</strong> Value from 0 to 60 minutes indicating how many minutes a person has before being considered late. If you choose 0 minutes, this option will be disabled and there will be no late arrivals.</li>
        <li><strong>Date range:</strong> Start and end dates for class attendance tracking.</li>
        <li><strong>Time range:</strong> Start and end times for daily meetings (used to create attendance sessions).</li>
        <li><strong>Backup recordings:</strong> If enabled, Zoom meeting recordings will be automatically backed up.</li>
        <li><strong>Delete from source:</strong> If enabled, recordings will be deleted from Zoom after backup.</li>
    </ul>
';
$string['viewwarning'] = 'ORT Attendance depends on the Attendance plugin to persist information, as it creates a session to save attendance. If Attendance is uninstalled from the course, a warning message will be displayed, as the plugin will not function correctly.';
$string['errornoattendance'] = 'WARNING: The Attendance plugin is not installed, and without it, ORT Attendance will not function correctly';

// Tasks
$string['schedulertask'] = 'ORT Attendance Scheduler';
$string['backuptask'] = 'ORT Attendance Recording Backup';

// Errors
$string['error_daterange'] = 'End date must be after start date';
$string['error_timerange'] = 'End time must be after start time';

// Capabilities
$string['ortattendance:addinstance'] = 'Add a new ORT Attendance activity';
$string['ortattendance:view'] = 'View ORT Attendance activity';

// Recollector
$string['recollectorsettings'] = 'Recollector Settings';
$string['recollectorsettings_desc'] = 'Configure which data source to use for attendance collection and recording management';

$string['recollectortype'] = 'Recollector Type';
$string['recollectortype_desc'] = 'Select the type of recollector to use:<br>
<strong>Zoom Recollector:</strong> Uses real Zoom API and Moodle Zoom plugin database';

$string['recollector_zoom'] = 'Zoom Recollector (Production)';

$string['zoomconfig'] = 'Zoom Configuration';
$string['zoomconfig_desc'] = 'Configuration settings for Zoom integration';

$string['backupsettings'] = 'Recording Backup Settings';
$string['backupsettings_desc'] = 'Configure automatic backup of Zoom recordings';

$string['localdirectory'] = 'Local Directory';
$string['localdirectory_desc'] = 'Directory path where recordings will be stored locally';

$string['backuplimit'] = 'Backup Limit';
$string['backuplimit_desc'] = 'Maximum number of recordings to backup per scheduled task run';

$string['keeplocalafterupload'] = 'keep local files after Moodle upload';
$string['keeplocalafterupload_desc'] = 'keep recording files from local filesystem after successful upload to Moodle';

$string['chunkingsettings'] = 'Chunking settings';
$string['chunkingsettings_desc'] = 'Configuration options for splitting attendance processing into smaller chunks to avoid timeouts.';

$string['maxdaysperrun'] = 'Maximum days per run';
$string['maxdaysperrun_desc'] = 'Maximum number of days of attendance data to process per execution. Lower values reduce the risk of timeouts.';

$string['maxexecutiontime'] = 'Maximum execution time (minutes)';
$string['maxexecutiontime_desc'] = 'Maximum amount of time (in minutes) the attendance processing task is allowed to run before stopping. Default is 50 minutes.';
