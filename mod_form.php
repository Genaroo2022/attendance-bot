<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_ortattendance_mod_form extends moodleform_mod {
    
    public function definition() {
        global $CFG;
        $mform = $this->_form;
        
        $mform->addElement('header', 'general', get_string('general', 'form'));
        
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        
        $this->standard_intro_elements();
        
        $mform->addElement('header', 'config', get_string('configuration', 'mod_ortattendance'));
        
        $mform->addElement('advcheckbox', 'camera_required', 
            get_string('camerarequired', 'mod_ortattendance'),
            get_string('camerarequired_desc', 'mod_ortattendance'), null, [0, 1]);
        $mform->setDefault('camera_required', 0);
        
        $mform->addElement('advcheckbox', 'use_email_matching', 
            get_string('useemailmatching', 'mod_ortattendance'),
            get_string('useemailmatching_desc', 'mod_ortattendance'), null, [0, 1]);
        $mform->setDefault('use_email_matching', 0);
        
        $mform->addElement('text', 'min_percentage', get_string('minpercentage', 'mod_ortattendance'), ['size' => '10']);
        $mform->setType('min_percentage', PARAM_INT);
        $mform->setDefault('min_percentage', 80);
        
        $mform->addElement('text', 'late_tolerance', get_string('latetolerance', 'mod_ortattendance'), ['size' => '10']);
        $mform->setType('late_tolerance', PARAM_INT);
        $mform->setDefault('late_tolerance', 10);
        
        $mform->addElement('header', 'datetime', get_string('datetimerange', 'mod_ortattendance'));

        $mform->setDefault('start_date', strtotime('-7 days'));
        $mform->setDefault('end_date', strtotime('+7 days'));

        $mform->addElement('date_selector', 'start_date', get_string('startdate', 'mod_ortattendance'));
        $mform->addElement('date_selector', 'end_date', get_string('enddate', 'mod_ortattendance'));
        
        $hours = [];
        for ($i = 0; $i < 24; $i++) {
            $hours[$i] = sprintf('%02d', $i);
        }
        $minutes = ['00' => '00', '15' => '15', '30' => '30', '45' => '45'];
        
        $starttime = [];
        $starttime[] = $mform->createElement('select', 'start_hour', '', $hours);
        $starttime[] = $mform->createElement('select', 'start_minute', '', $minutes);
        $mform->addGroup($starttime, 'start_time', get_string('starttime', 'mod_ortattendance'), ' : ', false);
        $mform->setDefault('start_hour', 18);
        $mform->setDefault('start_minute', 30);
        
        $endtime = [];
        $endtime[] = $mform->createElement('select', 'end_hour', '', $hours);
        $endtime[] = $mform->createElement('select', 'end_minute', '', $minutes);
        $mform->addGroup($endtime, 'end_time', get_string('endtime', 'mod_ortattendance'), ' : ', false);
        $mform->setDefault('end_hour', 23);
        $mform->setDefault('end_minute', 30);
        
        $mform->addElement('header', 'recordings', get_string('recordingsbackup', 'mod_ortattendance'));
        
        $mform->addElement('advcheckbox', 'backup_recordings', 
            get_string('backuprecordings', 'mod_ortattendance'),
            get_string('backuprecordings_desc', 'mod_ortattendance'), null, [0, 1]);
        $mform->setDefault('backup_recordings', 0);
        
        $mform->addElement('advcheckbox', 'delete_from_source', 
            get_string('deletefromsource', 'mod_ortattendance'),
            get_string('deletefromsource_desc', 'mod_ortattendance'), null, [0, 1]);
        $mform->setDefault('delete_from_source', 0);
        $mform->disabledIf('delete_from_source', 'backup_recordings', 'eq', 0);

        $mform->addElement('advcheckbox', 'keep_local_files', 
            get_string('keeplocalafterupload', 'mod_ortattendance'),
            get_string('keeplocalafterupload_desc', 'mod_ortattendance'));
        $mform->setDefault('keep_local_files', 0);
        $mform->disabledIf('keep_local_files', 'backup_recordings', 'eq', 0);
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
    
    public function data_preprocessing(&$default_values) {
        parent::data_preprocessing($default_values);
        
        if (isset($default_values['start_time'])) {
            $default_values['start_hour'] = floor($default_values['start_time'] / 3600);
            $default_values['start_minute'] = ($default_values['start_time'] % 3600) / 60;
        }
        
        if (isset($default_values['end_time'])) {
            $default_values['end_hour'] = floor($default_values['end_time'] / 3600);
            $default_values['end_minute'] = ($default_values['end_time'] % 3600) / 60;
        }
    }
    
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        if ($data['start_date'] >= $data['end_date']) {
            $errors['end_date'] = get_string('error_daterange', 'mod_ortattendance');
        }
        
        $startTime = ($data['start_hour'] * 3600) + ($data['start_minute'] * 60);
        $endTime = ($data['end_hour'] * 3600) + ($data['end_minute'] * 60);
        
        if ($startTime >= $endTime) {
            $errors['end_time'] = get_string('error_timerange', 'mod_ortattendance');
        }
        
        return $errors;
    }
}