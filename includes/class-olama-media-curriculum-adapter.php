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
        if (class_exists('Olama_School_Academic') && method_exists('Olama_School_Academic', 'get_years')) {
            return Olama_School_Academic::get_years();
        }

        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_academic_years ORDER BY start_date DESC");
    }

    public function get_active_year()
    {
        if (class_exists('Olama_School_Academic') && method_exists('Olama_School_Academic', 'get_active_year')) {
            return Olama_School_Academic::get_active_year();
        }

        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}olama_academic_years WHERE is_active = 1 LIMIT 1");
    }

    public function get_semesters($academic_year_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_semesters WHERE academic_year_id = %d ORDER BY start_date ASC",
            absint($academic_year_id)
        ));
    }

    public function get_active_semester($academic_year_id = null)
    {
        if (class_exists('Olama_School_Academic') && method_exists('Olama_School_Academic', 'get_active_semester')) {
            return Olama_School_Academic::get_active_semester($academic_year_id);
        }

        global $wpdb;
        if (!$academic_year_id) {
            $year = $this->get_active_year();
            $academic_year_id = $year ? $year->id : 0;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_semesters WHERE academic_year_id = %d AND is_active = 1 LIMIT 1",
            absint($academic_year_id)
        ));
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
