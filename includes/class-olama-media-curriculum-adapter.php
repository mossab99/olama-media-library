<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Curriculum_Adapter
{
    public function is_available()
    {
        global $wpdb;
        return $this->table_exists($wpdb->prefix . 'olama_curriculum_units')
            && $this->table_exists($wpdb->prefix . 'olama_curriculum_lessons');
    }

    public function get_academic_years()
    {
        if (function_exists('olama_core') && method_exists(olama_core(), 'academic_calendar')) {
            return olama_core()->academic_calendar()->years();
        }
        return array();
    }

    public function get_active_year()
    {
        if (function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
            return olama_core()->academic_context()->current_year();
        }
        return null;
    }

    public function get_semesters($academic_year_id)
    {
        return function_exists('olama_core') && method_exists(olama_core(), 'academic_calendar')
            ? olama_core()->academic_calendar()->semesters(absint($academic_year_id))
            : array();
    }

    public function get_active_semester($academic_year_id = null)
    {
        if (function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
            $context = olama_core()->academic_context()->current();
            if ($context && (!$academic_year_id || (int) $context->academic_year_id === (int) $academic_year_id)) {
                return olama_core()->academic_context()->current_semester();
            }
        }
        return null;
    }

    public function get_grades()
    {
        if (class_exists('Olama_School_Grade') && method_exists('Olama_School_Grade', 'get_grades')) {
            return Olama_School_Grade::get_grades();
        }

        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades ORDER BY CAST(grade_level AS UNSIGNED) ASC");
    }

    public function get_subjects($grade_id)
    {
        if (class_exists('Olama_School_Subject') && method_exists('Olama_School_Subject', 'get_by_grade')) {
            return Olama_School_Subject::get_by_grade(absint($grade_id), true);
        }

        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_subjects WHERE grade_id = %d AND is_active = 1 ORDER BY subject_name ASC",
            absint($grade_id)
        ));
    }

    public function get_curriculum_lessons($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        $db = new Olama_Media_DB();
        return $db->get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id);
    }

    public function get_names($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        return array(
            'academic_year' => $wpdb->get_var($wpdb->prepare("SELECT year_name FROM {$wpdb->prefix}olama_academic_years WHERE id = %d", absint($academic_year_id))),
            'semester' => $wpdb->get_var($wpdb->prepare("SELECT semester_name FROM {$wpdb->prefix}olama_semesters WHERE id = %d", absint($semester_id))),
            'grade' => $wpdb->get_var($wpdb->prepare("SELECT grade_name FROM {$wpdb->prefix}olama_grades WHERE id = %d", absint($grade_id))),
            'subject' => $wpdb->get_var($wpdb->prepare("SELECT subject_name FROM {$wpdb->prefix}olama_subjects WHERE id = %d", absint($subject_id))),
        );
    }

    private function table_exists($table)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
