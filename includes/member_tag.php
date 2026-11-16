<?php
// Coloured tag naming the household member an item belongs to (its payer).
// Only shown once a household has more than one member - alone, it says nothing.
function wallos_member_tag(array $members, $memberId): string
{
    if (count($members) < 2 || !isset($members[$memberId])) {
        return '';
    }
    $color = (string) ($members[$memberId]['color'] ?? '');
    $style = '';
    if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
        // Dark text on light colours, so any picked colour stays readable.
        [$r, $g, $b] = sscanf($color, "#%02x%02x%02x");
        $text = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? "#1a1a1a" : "#fff";
        $style = " style=\"--member-color: $color; color: $text\"";
    }
    // name is stored escaped (validate()), like everywhere else it is printed.
    return '<span class="member-tag"' . $style . '>' . $members[$memberId]['name'] . '</span>';
}
