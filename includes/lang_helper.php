<?php

function get_json_path() {
    return dirname(__DIR__) . '/json/all_classes_subject_V6.json';
}

function to_bangla_num($num) {
    if ($num === null || $num === '') return '';
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return str_replace($eng, $bn, (string)$num);
}

function get_bangla_position($pos) {
    if (empty($pos)) return '';
    $suffix = 'তম';
    $last_digit = $pos % 10;
    $last_two = $pos % 100;
    if ($last_two >= 11 && $last_two <= 19) {
        $suffix = 'তম';
    } else {
        if ($last_digit == 1) $suffix = 'ম';
        elseif ($last_digit == 2) $suffix = 'য়';
        elseif ($last_digit == 3) $suffix = 'য়';
        elseif ($last_digit == 4) $suffix = 'র্থ';
        elseif ($last_digit == 5) $suffix = 'ম';
        elseif ($last_digit == 6) $suffix = 'ষ্ঠ';
    }
    return to_bangla_num($pos) . $suffix;
}

function get_subject_translation_map($class_name) {
    static $maps = [];
    if (isset($maps[$class_name])) {
        return $maps[$class_name];
    }
    
    $json_path = get_json_path();
    if (!file_exists($json_path)) {
        return [];
    }
    
    $json_data = json_decode(file_get_contents($json_path), true);
    if (!$json_data || !isset($json_data['classes'])) {
        return [];
    }
    
    $class_json = null;
    foreach ($json_data['classes'] as $cj) {
        $json_cname = strtolower($cj['class_name']);
        $db_cname = strtolower($class_name);
        
        $match = ($json_cname == $db_cname ||
            str_replace(' ', '', $json_cname) == str_replace(' ', '', $db_cname) ||
            stripos($json_cname, $db_cname) !== false ||
            (isset($cj['class_id']) && strcasecmp($cj['class_id'], str_replace(' ', '', $class_name)) == 0));
            
        if (!$match && (stripos($db_cname, 'Class 9') !== false || stripos($db_cname, 'Class 10') !== false)) {
            if (stripos($json_cname, '9') !== false && stripos($json_cname, '10') !== false) {
                $match = true;
            }
        }
        
        if ($match) {
            $class_json = $cj;
            break;
        }
    }
    
    $map = [];
    if ($class_json && isset($class_json['groups'])) {
        foreach ($class_json['groups'] as $group_info) {
            foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
                if (isset($group_info['subjects'][$type])) {
                    foreach ($group_info['subjects'][$type] as $sub) {
                        $map[$sub['name_en']] = $sub['name_bn'];
                    }
                }
            }
        }
    }
    
    $maps[$class_name] = $map;
    return $map;
}

function get_class_and_group_translation_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    
    $map = [
        'class 6' => 'ষষ্ঠ শ্রেণি',
        'class 7' => 'সপ্তম শ্রেণি',
        'class 8' => 'অষ্টম শ্রেণি',
        'class 9' => 'নবম শ্রেণি',
        'class 10' => 'দশম শ্রেণি',
        'class 9 & 10 (ssc)' => 'নবম ও দশম শ্রেণি (এসএসসি)',
        'science' => 'বিজ্ঞান',
        'commerce' => 'ব্যবসায় শিক্ষা',
        'arts' => 'মানবিক',
        'arts / humanities' => 'মানবিক',
        'general' => 'সাধারণ',
        'half yearly' => 'অর্ধ-বার্ষিক',
        'final' => 'বার্ষিক',
        'final examination' => 'বার্ষিক পরীক্ষা'
    ];
    
    $json_path = get_json_path();
    if (file_exists($json_path)) {
        $json_data = json_decode(file_get_contents($json_path), true);
        if ($json_data && isset($json_data['classes'])) {
            foreach ($json_data['classes'] as $cj) {
                $map[strtolower($cj['class_name'])] = $cj['class_name_bn'];
                if (isset($cj['groups'])) {
                    foreach ($cj['groups'] as $g) {
                        $map[strtolower($g['group_name'])] = $g['group_name_bn'];
                    }
                }
            }
        }
    }
    return $map;
}

function translate_class_or_group($name, $lang) {
    if ($lang !== 'bn') return $name;
    $map = get_class_and_group_translation_map();
    $key = strtolower(trim($name));
    if (isset($map[$key])) {
        return $map[$key];
    }
    // Try substring matching for class
    foreach ($map as $k => $v) {
        if ($k !== '' && stripos($key, $k) !== false) {
            return $v;
        }
    }
    return $name;
}

function translate_label($label, $lang) {
    if ($lang !== 'bn') return $label;
    
    $translations = [
        // Student Info
        'Student' => 'শিক্ষার্থীর নাম',
        "Father's Name" => 'পিতার নাম',
        "Mother's Name" => 'মাতার নাম',
        'Class / Sec' => 'শ্রেণি / শাখা',
        'Roll Number' => 'রোল নম্বর',
        'Group' => 'বিভাগ',
        
        // Table Headers
        'SL' => 'ক্রমিক',
        'Subject' => 'বিষয়',
        'CQ' => 'তত্ত্বীয় (CQ)',
        'MCQ' => 'নৈর্ব্যক্তিক (MCQ)',
        'SQ' => 'সংক্ষিপ্ত প্রশ্ন (SQ)',
        'SQ Marks' => 'সংক্ষিপ্ত প্রশ্ন (SQ)',
        'PRAC' => 'ব্যবহারিক',
        'TOTAL' => 'মোট',
        'GRADE' => 'গ্রেড',
        'GPA' => 'জিপিএ (GPA)',
        
        // Summaries
        'Total Marks' => 'মোট প্রাপ্ত নম্বর',
        'Position' => 'মেধা স্থান',
        'Final Grade' => 'চূড়ান্ত গ্রেড',
        'GPA Score' => 'জিপিএ স্কোর',
        'Final results not published yet.' => 'চূড়ান্ত ফলাফল এখনও প্রকাশিত হয়নি।',
        
        // General text
        'Academic Achievement Record' => 'একাডেমিক ট্রান্সক্রিপ্ট (শিক্ষাগত অর্জনের রেকর্ড)',
        'Examination' => 'পরীক্ষা',
        'Session' => 'শিক্ষাবর্ষ',
        'Class Teacher' => 'শ্রেণি শিক্ষক',
        'Headmaster' => 'প্রধান শিক্ষক',
        'Scan to Verify Original Document' => 'মূল নথি যাচাই করতে স্ক্যান করুন',
        'Official Academic Record' => 'অফিসিয়াল একাডেমিক রেকর্ড',
        'Official Result Document' => 'অফিসিয়াল ফলাফল নথি',
        'Generated on:' => 'তৈরি করার সময়:',
        'Marksheet Preview' => 'মার্কশিট প্রাকদর্শন',
        'Close' => 'বন্ধ করুন',
        'Print / Save' => 'প্রিন্ট / সেভ',
        'Download Official Transcript' => 'অফিসিয়াল ট্রান্সক্রিপ্ট ডাউনলোড করুন',
        'Download Marksheets' => 'মার্কশিট ডাউনলোড',
        'Bulk All Sections' => 'সব শাখার বাল্ক ডাউনলোড',
        'Print Merit List' => 'মেধা তালিকা প্রিন্ট',
        'Back to Dashboard' => 'ড্যাশবোর্ডে ফিরে যান',
        'Optional' => 'ঐচ্ছিক',
        
        // Exam type translation fallback
        'Half Yearly Examination' => 'অর্ধ-বার্ষিক পরীক্ষা',
        'Final Examination' => 'বার্ষিক পরীক্ষা',
        'Half Yearly' => 'অর্ধ-বার্ষিক',
        'Final' => 'বার্ষিক'
    ];
    
    return $translations[$label] ?? $label;
}

function translate_subject($subject_name, $class_name, $lang) {
    if ($lang !== 'bn') return $subject_name;
    $map = get_subject_translation_map($class_name);
    return $map[$subject_name] ?? $subject_name;
}

function normalize_subject_name_for_matching($name) {
    $name = strtolower(trim($name));
    // Handle common aliases first
    if (stripos($name, 'ict') !== false && strlen(trim($name)) <= 10) {
        return 'ict';
    }
    if ((stripos($name, 'physical education') !== false) && strlen(trim(str_replace(['&', 'and', 'health'], '', $name))) < 50) {
        return 'physicaleducationhealth';
    }
    // Remove punctuation and extra spaces
    $name = str_replace(['&', '/', ' and ', '(', ')', '.', ',', '-', "'", '  '], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    return preg_replace('/[^a-z0-9]/', '', $name);
}

function get_subject_marks_distribution($class_name, $subject_name) {
    $json_path = get_json_path();
    if (!file_exists($json_path)) {
        return null;
    }
    
    $json_data = json_decode(file_get_contents($json_path), true);
    if (!$json_data || !isset($json_data['classes'])) {
        return null;
    }
    
    // Find class
    $class_json = null;
    foreach ($json_data['classes'] as $cj) {
        $json_cname = strtolower(trim($cj['class_name']));
        $db_cname = strtolower(trim($class_name));
        
        $match = ($json_cname == $db_cname ||
            str_replace(' ', '', $json_cname) == str_replace(' ', '', $db_cname) ||
            stripos($json_cname, $db_cname) !== false ||
            (isset($cj['class_id']) && strcasecmp($cj['class_id'], str_replace(' ', '', $class_name)) == 0));
            
        if (!$match && (stripos($db_cname, 'Class 9') !== false || stripos($db_cname, 'Class 10') !== false)) {
            if (stripos($json_cname, '9') !== false && stripos($json_cname, '10') !== false) {
                $match = true;
            }
        }
        
        if ($match) {
            $class_json = $cj;
            break;
        }
    }
    
    if ($class_json && isset($class_json['groups'])) {
        foreach ($class_json['groups'] as $group_info) {
            foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
                if (isset($group_info['subjects'][$type])) {
                    foreach ($group_info['subjects'][$type] as $sub) {
                        // First try exact match
                        if (strcasecmp(trim($sub['name_en']), trim($subject_name)) === 0) {
                            return $sub['marks_distribution'] ?? null;
                        }

                        // Try normalized matching for aliases (ICT, PE, etc.)
                        $normalized_sub = normalize_subject_name_for_matching($sub['name_en']);
                        $normalized_input = normalize_subject_name_for_matching($subject_name);
                        
                        // Check exact match after normalization
                        if ($normalized_sub === $normalized_input) {
                            return $sub['marks_distribution'] ?? null;
                        }
                        
                        // For short inputs, check if they're contained in the normalized JSON name
                        if (strlen($normalized_input) <= 15 && strpos($normalized_sub, $normalized_input) !== false) {
                            return $sub['marks_distribution'] ?? null;
                        }
                    }
                }
            }
        }
    }
    return null;
}

