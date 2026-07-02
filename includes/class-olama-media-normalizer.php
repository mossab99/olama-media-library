<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Normalizer
{
    public function normalize_text($text)
    {
        $text = wp_strip_all_tags((string) $text);
        $text = strtr($text, array(
            "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
            "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
            "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
            "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
            "\u{0623}" => "\u{0627}", "\u{0625}" => "\u{0627}", "\u{0622}" => "\u{0627}", "\u{0671}" => "\u{0627}",
            "\u{0649}" => "\u{064A}", "\u{0629}" => "\u{0647}", "\u{0640}" => '',
        ));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    public function normalize_filename($filename)
    {
        return $this->normalize_text(pathinfo((string) $filename, PATHINFO_FILENAME));
    }

    public function extract_lesson_number($filename)
    {
        $text = $this->normalize_filename($filename);
        if (preg_match('/(?:^|\s)(?:درس|الدرس|lesson|l)\s*0*([0-9]{1,3})(?:\s|$)/iu', $text, $matches)) {
            return (int) $matches[1];
        }
        $ordinals = array(
            'الاول' => 1, 'الثاني' => 2, 'الثالث' => 3, 'الرابع' => 4, 'الخامس' => 5,
            'السادس' => 6, 'السابع' => 7, 'الثامن' => 8, 'التاسع' => 9, 'العاشر' => 10,
        );
        foreach ($ordinals as $word => $number) {
            if (preg_match('/(?:^|\s)(?:درس|الدرس)\s+' . preg_quote($word, '/') . '(?:\s|$)/u', $text)) {
                return $number;
            }
        }
        return null;
    }

    public function extract_part_number($filename)
    {
        $text = $this->normalize_filename($filename);
        if (preg_match('/(?:^|\s)(?:جزء|الجزء|part|p)\s*0*([0-9]{1,3})(?:\s|$)/iu', $text, $matches)) {
            return (int) $matches[1];
        }
        $ordinals = array('الاول' => 1, 'الثاني' => 2, 'الثالث' => 3, 'الرابع' => 4, 'الخامس' => 5);
        foreach ($ordinals as $word => $number) {
            if (preg_match('/(?:^|\s)(?:جزء|الجزء)\s+' . preg_quote($word, '/') . '(?:\s|$)/u', $text)) {
                return $number;
            }
        }
        return null;
    }

    public function extract_extension($filename)
    {
        return strtolower(sanitize_key(pathinfo((string) $filename, PATHINFO_EXTENSION)));
    }
}
