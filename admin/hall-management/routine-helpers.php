<?php

/**
 * Aggregate schedule/enrolment rows without exposing student details.
 * Requires the clock parsers in helpers.php; performs no database writes.
 */
function hm_routine_groups(array $schedules, array $assignments): array
{
    $groups = [];
    $student_groups = [];
    foreach ($schedules as $row) {
        $slot = hm_parse_time_slot_interval((string)($row['time_slot'] ?? ''));
        $interval = $slot['ok']
            ? [$slot['start'], $slot['end']]
            : ['invalid', trim((string)($row['time_slot'] ?? ''))];
        $key = json_encode([
            $row['exam_date'], $interval, (int)$row['program_id'],
            trim((string)$row['exam_name']), trim((string)$row['semester']),
        ]);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'exam_date' => $row['exam_date'],
                'start' => $slot['ok'] ? $slot['start'] : '',
                'end' => $slot['ok'] ? $slot['end'] : '',
                'time_warning' => $slot['ok'] ? '' : $slot['error'],
                'program_id' => (int)$row['program_id'],
                'program_name' => (string)($row['program_name'] ?? ''),
                'exam_name' => trim((string)$row['exam_name']),
                'semester' => trim((string)$row['semester']),
                'students' => [], 'seated' => [], 'rooms' => [],
            ];
        }
        $sid = (int)($row['student_id'] ?? 0);
        if ($sid <= 0) continue;
        $course = strtoupper(trim((string)($row['course_code'] ?? '')));
        $groups[$key]['students'][$sid][$course] = true;
        $student_groups[$row['exam_date']][$sid][$key] = true;
    }

    foreach ($assignments as $assignment) {
        if (empty($assignment['is_active'])) continue;
        $date = (string)$assignment['exam_date'];
        $sid = (int)$assignment['student_id'];
        if (!empty($assignment['hall_exam_date']) && $assignment['hall_exam_date'] !== $date) continue;
        $raw_slot = trim((string)($assignment['time_slot'] ?? ''));
        $slot = hm_parse_time_slot_interval($raw_slot);
        $clock = hm_parse_clock_time((string)($assignment['exam_time'] ?? ''));
        $course = strtoupper(trim((string)($assignment['course_code'] ?? '')));
        $matches = [];
        foreach ($student_groups[$date][$sid] ?? [] as $key => $_) {
            $group = $groups[$key];
            if ($group['start'] === '') continue;
            if ($course !== '' && !isset($group['students'][$sid][$course])) continue;
            if ($raw_slot !== '') {
                if (!$slot['ok'] || $slot['start'] !== $group['start'] || $slot['end'] !== $group['end']) continue;
            } elseif ($clock === null || $clock[1] !== $group['start']) {
                continue;
            }
            $matches[] = $key;
        }
        // A legacy start-only booking cannot distinguish different end times/cycles.
        if ($raw_slot === '' && count($matches) !== 1) continue;
        foreach ($matches as $key) {
            $groups[$key]['seated'][$sid] = true;
            $room = trim((string)$assignment['room_number']);
            if ($room !== '') $groups[$key]['rooms'][$room] = true;
        }
    }

    foreach ($groups as &$group) {
        $group['total_students'] = count($group['students']);
        $group['seated_students'] = count($group['seated']);
        $group['rooms'] = array_map('strval', array_keys($group['rooms']));
        natcasesort($group['rooms']);
        $group['rooms'] = array_values($group['rooms']);
        unset($group['students'], $group['seated']);
    }
    unset($group);
    $groups = array_values($groups);
    usort($groups, static function (array $a, array $b): int {
        return [$a['exam_date'], $a['start'], $a['end'], $a['program_name'], $a['program_id'], $a['exam_name'], $a['semester']]
            <=> [$b['exam_date'], $b['start'], $b['end'], $b['program_name'], $b['program_id'], $b['exam_name'], $b['semester']];
    });
    return $groups;
}
