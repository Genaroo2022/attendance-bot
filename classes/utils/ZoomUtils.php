<?php
namespace mod_ortattendance\utils;

defined('MOODLE_INTERNAL') || die();

class ZoomUtils {
    
    private static $tokenCache = null;
    private static $tokenExpires = 0;
    
    /**
     * Get Zoom OAuth access token from mod_zoom configuration
     */
    public static function getZoomToken() {
        if (self::$tokenCache && time() < self::$tokenExpires) {
            return self::$tokenCache;
        }
        
        // Extract credentials from mod_zoom plugin settings
        $clientId = get_config('zoom', 'clientid');
        $clientSecret = get_config('zoom', 'clientsecret');
        $accountId = get_config('zoom', 'accountid');
        
        if (empty($clientId) || empty($clientSecret) || empty($accountId)) {
            throw new \moodle_exception('zoomerror', 'mod_ortattendance', '', null, 
                'Zoom credentials not configured in mod_zoom. Please configure mod_zoom plugin first.');
        }
        
        $url = "https://zoom.us/oauth/token?grant_type=account_credentials&account_id=" . $accountId;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \moodle_exception('zoomerror', 'mod_ortattendance', '', null,
                'Failed to initialize cURL for Zoom token request');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \moodle_exception('zoomerror', 'mod_ortattendance', '', null,
                'cURL error getting Zoom token: ' . $curlError);
        }

        if ($httpcode !== 200) {
            throw new \moodle_exception('zoomerror', 'mod_ortattendance', '', null,
                'Failed to get Zoom token. HTTP ' . $httpcode);
        }
        
        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            self::$tokenCache = $data['access_token'];
            self::$tokenExpires = time() + ($data['expires_in'] ?? 3600) - 60;
            return self::$tokenCache;
        }
        
        throw new \moodle_exception('zoomerror', 'mod_ortattendance', '', null, 
            'Invalid token response from Zoom');
    }
    
    public static function getRecordingMetadata($meetingId) {
        $token = self::getZoomToken();

        $url = "https://api.zoom.us/v2/meetings/{$meetingId}/recordings";

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \Exception("Failed to initialize cURL for recording metadata request");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("cURL error fetching recording metadata: " . $curlError);
        }

        if ($httpcode === 404) {
            return null;
        }

        if ($httpcode !== 200) {
            throw new \Exception("Zoom API error: HTTP $httpcode");
        }

        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to parse Zoom API response: " . json_last_error_msg());
        }

        return $data;
    }
    
    public static function downloadRecording($downloadUrl, $destination) {
        $token = self::getZoomToken();

        $url = $downloadUrl . "?access_token=" . $token;

        $fp = fopen($destination, 'w+');
        if ($fp === false) {
            throw new \Exception("Failed to open destination file for writing: $destination");
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            @unlink($destination);
            throw new \Exception("Failed to initialize cURL for recording download");
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) {
                if ($downloadSize > 0) {
                    $percent = ($downloaded / $downloadSize) * 100;
                    if ($percent % 10 == 0) {
                        mtrace("  Download progress: " . round($percent) . "%");
                    }
                }
            }
        ]);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($result === false) {
            @unlink($destination);
            throw new \Exception("cURL error downloading recording: " . $curlError);
        }

        if ($httpcode !== 200) {
            @unlink($destination);
            throw new \Exception("Download failed: HTTP $httpcode");
        }

        if ($size === 0 || !file_exists($destination)) {
            @unlink($destination);
            throw new \Exception("Downloaded file is empty or does not exist");
        }

        return $size;
    }
    
    public static function deleteRecording($meetingId) {
        $token = self::getZoomToken();

        $url = "https://api.zoom.us/v2/meetings/{$meetingId}/recordings";

        $ch = curl_init($url);
        if ($ch === false) {
            mtrace("Warning: Failed to initialize cURL for recording deletion");
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token"
            ]
        ]);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            mtrace("Warning: cURL error deleting recording");
            return false;
        }

        return $httpcode === 204 || $httpcode === 200;
    }
}