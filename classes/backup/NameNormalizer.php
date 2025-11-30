<?php
/**
 * Meeting name normalization for recording backup
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\backup;

defined('MOODLE_INTERNAL') || die();

class NameNormalizer {
    
    public static function normalizeFileName($rawName, $meetingTimestamp) {
        
        $cleanName = self::removeBrackets($rawName);
        $date = self::extractDate($cleanName, $meetingTimestamp);
        $code = self::detectCodePattern($cleanName);
        
        if ($code !== null) {
            return self::parseCode($code, $date);
        } else {
            return self::parseText($cleanName, $date);
        }
    }
    
    private static function removeBrackets($name) {
        return preg_replace('/\s*\[.*?\]\s*/', ' ', $name);
    }
    
    private static function detectCodePattern($name) {
        if (preg_match('/\b([A-Z]{2,3}-[A-Z0-9]{3,4}[A-Z]?)\b/i', $name, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }
    
    private static function parseCode($code, $date) {
        $parts = explode('-', $code);
        
        if (count($parts) >= 2) {
            $prefix = $parts[0]; 
            $middleAndSuffix = $parts[1]; 
            $suffix = substr($middleAndSuffix, -1); 
            $middle = substr($middleAndSuffix, 0, -1); 
            $normalizedName = $prefix . '-' . $middle . '-' . $suffix;
            $pathParts = [$prefix, $middle, $suffix, $date];
            
            return [
                'name' => $normalizedName,
                'date' => $date,
                'path' => $pathParts
            ];
        }
        
        return [
            'name' => $code,
            'date' => $date,
            'path' => [$code, $date]
        ];
    }
    
    private static function parseText($name, $date) {
        $name = trim($name);
        
        if (preg_match('/\bCURSO\s+([A-Z])\b/i', $name, $matches)) {
            $suffix = strtoupper($matches[1]); 
            $baseName = preg_replace('/\s*-\s*CURSO\s+[A-Z]\b/i', '', $name);
            $baseName = trim($baseName);
            $baseName = preg_replace('/[^A-Za-z0-9\s]/', '', $baseName);
            $baseName = preg_replace('/\s+/', ' ', $baseName); 
            $baseName = trim($baseName);
            $normalizedName = $baseName . '-' . $suffix;
            $pathParts = [$baseName, $suffix, $date];
            
            return [
                'name' => $normalizedName,
                'date' => $date,
                'path' => $pathParts
            ];
        }
        
        $cleanName = preg_replace('/[^A-Za-z0-9\s]/', '', $name);
        $cleanName = preg_replace('/\s+/', '-', trim($cleanName));
        
        return [
            'name' => $cleanName,
            'date' => $date,
            'path' => [$cleanName, $date]
        ];
    }
    
    private static function extractDate($name, $timestamp) {
        if (preg_match('/\b(20\d{6})\b/', $name, $matches)) {
            return $matches[1];
        }
        return date('Ymd', $timestamp);
    }
}
