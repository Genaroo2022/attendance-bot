<?php
/**
 * Moodle mirroring for recording backup
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\backup;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles uploading recordings to Moodle course folders
 */
class MoodleMirroring {
    
    const SECTION_NAME = 'Clases grabadas';
    
    /**
     * Ensure recordings section exists in course
     *
     * @param int $courseid Course ID
     * @return object Course section
     */
    public static function ensureRecordingsSection($courseid) {
        global $DB;
        
        // Check if section already exists
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => self::SECTION_NAME
        ]);
        
        if ($section) {
            return $section;
        }
        
        // Get course
        $course = $DB->get_record('course', ['id' => $courseid], '*', \MUST_EXIST);
        
        // Get last section number
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section DESC', 'section', 0, 1);
        $lastSection = reset($sections);
        $newSectionNum = $lastSection ? $lastSection->section + 1 : 1;
        
        // Create new section
        $section = new \stdClass();
        $section->course = $courseid;
        $section->section = $newSectionNum;
        $section->name = self::SECTION_NAME;
        $section->summary = '';
        $section->summaryformat = \FORMAT_HTML;
        $section->sequence = '';
        $section->visible = 1;
        $section->availability = null;
        $section->timemodified = time();
        
        $section->id = $DB->insert_record('course_sections', $section);
        
        // Rebuild course cache
        \rebuild_course_cache($courseid);
        
        return $section;
    }
    
    /**
     * Get or create folder module for recordings
     *
     * @param int $courseid Course ID
     * @param string $folderName Folder name
     * @return object Course module
     */
    public static function getOrCreateFolder($courseid, $folderName) {
        global $CFG, $DB;
        
        require_once($CFG->dirroot . '/mod/folder/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        
        // Ensure recordings section exists
        $section = self::ensureRecordingsSection($courseid);
        
        // Check if folder already exists in the section
        $sql = "SELECT cm.*, f.name as foldername
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                JOIN {folder} f ON f.id = cm.instance
                WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND m.name = 'folder'
                AND f.name = :foldername
                AND cm.deletioninprogress = 0";
        
        $existing = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'sectionid' => $section->id,
            'foldername' => $folderName
        ]);
        
        if ($existing) {
            return $existing;
        }
        
        // Create new folder
        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'folder';
        
        $module = $DB->get_record('modules', ['name' => 'folder'], '*', \MUST_EXIST);
        $moduleinfo->module = $module->id;
        $moduleinfo->files = null;
        
        $moduleinfo->course = $courseid;
        $moduleinfo->section = $section->section;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->name = $folderName;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = \FORMAT_HTML;
        $moduleinfo->showexpanded = 0;
        $moduleinfo->showdownloadfolder = 1;
        
        $moduleinfo = \add_moduleinfo($moduleinfo, $DB->get_record('course', ['id' => $courseid]));
        
        return $DB->get_record('course_modules', ['id' => $moduleinfo->coursemodule]);
    }
    
    /**
     * Upload file to Moodle folder
     *
     * @param int $courseid Course ID
     * @param string $folderName Main folder name
     * @param string $subfolderName Subfolder name (typically date)
     * @param string $filename File name
     * @param string $filepath Local file path
     * @return int File ID
     */
    public static function uploadToMoodle($courseid, $folderName, $subfolderName, $filename, $filepath) {
        global $DB;
        
        // Get or create folder
        $cm = self::getOrCreateFolder($courseid, $folderName);
        
        // Check for duplicates
        $existingFile = self::checkDuplicate($cm->instance, $subfolderName, $filename, filesize($filepath));
        if ($existingFile) {
            return $existingFile->get_id();
        }
        
        // Get context
        $context = \context_module::instance($cm->id);
        
        // Prepare file record
        $fileRecord = [
            'contextid' => $context->id,
            'component' => 'mod_folder',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/' . $subfolderName . '/',
            'filename' => $filename,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        
        // Create file from pathname
        $fs = \get_file_storage();
        $file = $fs->create_file_from_pathname($fileRecord, $filepath);
        
        if (!$file) {
            throw new \Exception("Failed to create file in Moodle: $filename");
        }
        
        return $file->get_id();
    }
    
    /**
     * Check if file already exists (duplicate detection)
     *
     * @param int $folderInstance Folder instance ID
     * @param string $subfolder Subfolder name
     * @param string $filename Filename
     * @param int $filesize File size in bytes
     * @return object|false Existing file or false
     */
    private static function checkDuplicate($folderInstance, $subfolder, $filename, $filesize) {
        global $DB;
        
        $fs = \get_file_storage();
        
        $cm = \get_coursemodule_from_instance('folder', $folderInstance);
        $context = \context_module::instance($cm->id);
        
        $files = $fs->get_area_files(
            $context->id,
            'mod_folder',
            'content',
            0,
            'filepath, filename',
            false
        );
        
        $filepath = '/' . $subfolder . '/';
        
        foreach ($files as $file) {
            if ($file->get_filepath() === $filepath && $file->get_filename() === $filename) {
                $existingSize = $file->get_filesize();
                $sizeDiff = abs($existingSize - $filesize);
                $threshold = $filesize * 0.05; // 5% tolerance
                
                if ($sizeDiff <= $threshold) {
                    return $file;
                }
            }
        }
        
        return false;
    }
}