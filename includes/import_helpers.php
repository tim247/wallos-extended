<?php
// CSV import helpers (import.php).

/** @return array{0: int, 1: int}|null [cycle, frequency] */
function importCycle(string $value): ?array
{
    $value = mb_strtolower(trim($value));
    return match (true) {
        $value === '' || str_starts_with($value, 'monat') || str_starts_with($value, 'month') => [3, 1],
        str_contains($value, 'viertel') || str_starts_with($value, 'quart') => [3, 3],
        str_contains($value, 'halb') || str_contains($value, 'half') => [3, 6],
        str_starts_with($value, 'jähr') || str_starts_with($value, 'jaehr') || str_starts_with($value, 'jahr') || str_starts_with($value, 'year') || str_starts_with($value, 'annual') => [4, 1],
        str_starts_with($value, 'wöch') || str_starts_with($value, 'woech') || str_starts_with($value, 'week') => [2, 1],
        default => null,
    };
}
