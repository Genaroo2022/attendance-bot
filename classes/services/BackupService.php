<?php
/**
 * Backup service for Zoom recordings
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\services;

use mod_ortattendance\backup\NameNormalizer;
use mod_ortattendance\backup\MoodleMirroring;
use mod_ortattendance\utils\ZoomUtils;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../backup/NameNormalizer.php');
require_once(__DIR__ . '/../backup/MoodleMirroring.php');
require_once(__DIR__ . '/../utils/ZoomUtils.php');

class BackupService {
    
    public static function processRecording($meetingId, $meetingName, $meetingTimestamp, $courseId, $deleteFromSource = false, $recollector = null) {
        global $DB;

        try {
            // Validate inputs
            if (empty($meetingId)) {
                return ['success' => false, 'error' => 'Meeting ID is required'];
            }
            if (empty($courseId)) {
                return ['success' => false, 'error' => 'Course ID is required'];
            }

            if ($recollector !== null) {
                $metadata = $recollector->getRecordingMetadata($meetingId);
            } else {
                $metadata = ZoomUtils::getRecordingMetadata($meetingId);
            }

            if (!$metadata || empty($metadata['recording_files'])) {
                return ['success' => false, 'error' => 'No recordings found'];
            }

            $normalized = NameNormalizer::normalizeFileName($meetingName, $meetingTimestamp);
            $videoFile = self::findVideoFile($metadata['recording_files']);

            if (!$videoFile) {
                return ['success' => false, 'error' => 'No video file found'];
            }

            if (empty($videoFile['download_url'])) {
                return ['success' => false, 'error' => 'Recording download URL is missing'];
            }

            $localPath = self::downloadToLocal($videoFile, $normalized, $recollector);

            // Upload to Moodle course folders using MoodleMirroring
            $filename = $normalized['name'] . '_' . $normalized['date'] . '.mp4';
            $folderName = $normalized['name'];
            $subfolderName = $normalized['date'];

            $fileId = MoodleMirroring::uploadToMoodle(
                $courseId,
                $folderName,
                $subfolderName,
                $filename,
                $localPath
            );

            if (!$fileId) {
                throw new \Exception("Failed to upload recording to Moodle");
            }

            // Delete from source if requested
            if ($deleteFromSource) {
                try {
                    if ($recollector !== null && method_exists($recollector, 'deleteRecording')) {
                        $deleted = $recollector->deleteRecording($meetingId);
                    } else {
                        $deleted = ZoomUtils::deleteRecording($meetingId);
                    }

                    if (!$deleted) {
                        mtrace("  Warning: Failed to delete recording from source");
                    }
                } catch (\Exception $e) {
                    mtrace("  Warning: Error deleting recording from source: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'localPath' => $localPath,
                'fileId' => $fileId,
                'size' => filesize($localPath)
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private static function findVideoFile($recordingFiles) {
        foreach ($recordingFiles as $file) {
            if ($file['file_type'] === 'MP4' && $file['recording_type'] === 'shared_screen_with_speaker_view') {
                return $file;
            }
        }
        foreach ($recordingFiles as $file) {
            if ($file['file_type'] === 'MP4') {
                return $file;
            }
        }
        return null;
    }
    
    private static function downloadToLocal($videoFile, $normalized, $recollector = null) {
        $baseDir = get_config('mod_ortattendance', 'local_directory');

        if (empty($baseDir)) {
            throw new \Exception("Local directory not configured. Please set 'local_directory' in plugin settings.");
        }

        if (!file_exists($baseDir)) {
            if (!mkdir($baseDir, 0755, true)) {
                throw new \Exception("Failed to create base directory: $baseDir");
            }
        }

        if (!is_writable($baseDir)) {
            throw new \Exception("Base directory is not writable: $baseDir");
        }

        $folderPath = $baseDir . '/' . implode('/', $normalized['path']);
        if (!file_exists($folderPath)) {
            if (!mkdir($folderPath, 0755, true)) {
                throw new \Exception("Failed to create folder path: $folderPath");
            }
        }

        $filename = $normalized['name'] . '_' . $normalized['date'] . '.mp4';
        $filepath = $folderPath . '/' . $filename;

        if ($recollector !== null && method_exists($recollector, 'downloadRecording')) {
            $recollector->downloadRecording($videoFile['download_url'], $filepath);
        } else {
            ZoomUtils::downloadRecording($videoFile['download_url'], $filepath);
        }

        // Verify download succeeded
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new \Exception("Download verification failed: file is missing or empty");
        }

        return $filepath;
    }
    
    private static function storeInMoodle($localPath, $normalized, $courseId) {
        global $USER;
        
        $context = \context_course::instance($courseId);
        $fs = get_file_storage();
        
        $fileRecord = [
            'contextid' => $context->id,
            'component' => 'mod_ortattendance',
            'filearea' => 'recordings',
            'itemid' => 0,
            'filepath' => '/' . implode('/', $normalized['path']) . '/',
            'filename' => $normalized['name'] . '_' . $normalized['date'] . '.mp4',
            'userid' => $USER->id ?? 2
        ];
        
        $existing = $fs->get_file(
            $fileRecord['contextid'],
            $fileRecord['component'],
            $fileRecord['filearea'],
            $fileRecord['itemid'],
            $fileRecord['filepath'],
            $fileRecord['filename']
        );
        
        if ($existing) {
            $existing->delete();
        }
        
        $file = $fs->create_file_from_pathname($fileRecord, $localPath);
        return $file->get_id();
    }
    
    public static function getCourseRecordings($courseId) {
        $context = \context_course::instance($courseId);
        $fs = get_file_storage();
        
        $files = $fs->get_area_files($context->id, 'mod_ortattendance', 'recordings', 0, 'filepath, filename', false);
        
        $recordings = [];
        foreach ($files as $file) {
            $recordings[] = [
                'id' => $file->get_id(),
                'name' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'size' => $file->get_filesize(),
                'timecreated' => $file->get_timecreated(),
                'file' => $file
            ];
        }
        
        return $recordings;
    }
}
