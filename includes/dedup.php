<?php

/**
 * Deduplicates search results by grouping on normalized title + author.
 *
 * Within each group, selects the "best" entry:
 *   - Prefer exact format match with the requested extension
 *   - Avoid tiny files (<50KB, likely corrupt/incomplete)
 *   - Among remaining, prefer first occurrence (libgen default order is decent)
 *
 * Returns array of groups: each group has 'best' entry and 'editions' count.
 */
function deduplicateResults(array $results, string $preferredFormat = 'mobi'): array {
    $groups = [];

    foreach ($results as $item) {
        $key = normalizeForGrouping($item['title'], $item['author']);

        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $item;
    }

    $output = [];
    foreach ($groups as $key => $items) {
        usort($items, function ($a, $b) use ($preferredFormat) {
            return scoreResult($b, $preferredFormat) - scoreResult($a, $preferredFormat);
        });

        $output[] = [
            'best' => $items[0],
            'editions' => count($items),
            'all' => $items,
            'group_key' => $key,
        ];
    }

    return $output;
}

function normalizeForGrouping(string $title, string $author): string {
    $t = strtolower(trim($title));
    $t = preg_replace('/\b(the|a|an|of|and|in|to|for|on|with|by)\b/', '', $t);
    $t = preg_replace('/[^a-z0-9]/', '', $t);

    $a = strtolower(trim($author));
    $a = preg_replace('/[^a-z]/', '', $a);
    // Use first 15 chars of author to handle name variations
    $a = substr($a, 0, 15);

    return $t . '|' . $a;
}

function scoreResult(array $item, string $preferredFormat): int {
    $score = 0;
    $sizeBytes = $item['size_bytes'] ?? 0;

    // Strongly penalize tiny files (likely corrupt)
    if ($sizeBytes > 0 && $sizeBytes < 51200) {
        $score -= 100;
    }

    // Prefer the exact format requested
    if (strtolower($item['format']) === strtolower($preferredFormat)) {
        $score += 50;
    }

    // Mild preference for files in a "reasonable" range (100KB - 10MB)
    if ($sizeBytes >= 102400 && $sizeBytes <= 10485760) {
        $score += 10;
    }

    // Slight penalty for very large files (>50MB), might be anthologies or padded
    if ($sizeBytes > 52428800) {
        $score -= 5;
    }

    return $score;
}
