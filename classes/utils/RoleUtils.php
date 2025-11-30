<?php
namespace mod_ortattendance\utils;

defined('MOODLE_INTERNAL') || die();

class RoleUtils {
    
    public static function isTeacher($userId, $courseId) {
        global $DB;
        
        $context = \context_course::instance($courseId);
        $roles = get_user_roles($context, $userId);
        
        $teacherRoles = self::getAllowedTeacherRoles();
        
        foreach ($roles as $role) {
            if (in_array($role->roleid, $teacherRoles)) {
                return true;
            }
        }
        
        return false;
    }
    
    public static function getAllowedTeacherRoles() {
        return [3, 4, 15, 9, 12]; // Teacher, Non-editing teacher, Manager, etc.
    }
}
