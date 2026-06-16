<?php

function ei_get_faculty_list(): array
{
    return db()->query(
        "SELECT f.*, d.name AS dept_name
         FROM ei_faculty f
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.is_active = 1
         ORDER BY d.name ASC, f.name ASC"
    )->fetchAll();
}

function ei_format_faculty_option_label(array $faculty): string
{
    $label = trim((string)($faculty['name'] ?? ''));
    if (!empty($faculty['designation'])) {
        $label .= ' (' . trim((string)$faculty['designation']) . ')';
    }
    if (!empty($faculty['dept_name'])) {
        $label .= ' — ' . trim((string)$faculty['dept_name']);
    }
    return $label;
}

function ei_parse_time_value(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') return null;

    $formats = ['H:i', 'G:i', 'h:i A', 'g:i A', 'h:iA', 'g:iA', 'h:i a', 'g:i a', 'h:ia', 'g:ia'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $value) {
            return $parsed;
        }
    }

    return null;
}

function ei_normalize_time_slot_range(string $start_time, string $end_time): ?string
{
    $start = ei_parse_time_value($start_time);
    $end   = ei_parse_time_value($end_time);
    if (!$start || !$end) return null;
    if ($end <= $start) return null;

    return $start->format('h:i A') . ' – ' . $end->format('h:i A');
}

function ei_parse_time_slot_range(string $time_slot): array
{
    $time_slot = trim($time_slot);
    if ($time_slot === '') return ['', ''];

    if (!preg_match('/^\s*(.+?)\s*[–-]\s*(.+?)\s*$/u', $time_slot, $matches)) {
        return ['', ''];
    }

    $start = ei_parse_time_value($matches[1]);
    $end   = ei_parse_time_value($matches[2]);
    if (!$start || !$end) return ['', ''];

    return [$start->format('H:i'), $end->format('H:i')];
}

function ei_normalize_slot_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $value) {
            return $parsed->format('Y-m-d');
        }
    }

    return null;
}
