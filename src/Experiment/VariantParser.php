<?php

namespace Drupal\rl\Experiment;

/**
 * Parses textarea variant input into a normalized list.
 *
 * Used by consumer modules that accept variant text in a "one per line"
 * textarea on entity forms.
 */
final class VariantParser {

  /**
   * Parse a textarea string into a list of trimmed, non-empty lines.
   *
   * @param string|null $raw
   *   The raw textarea value.
   *
   * @return string[]
   *   Variant lines with surrounding whitespace removed and empty lines
   *   filtered out.
   */
  public static function parse(?string $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $cleaned = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $cleaned[] = $line;
      }
    }
    return $cleaned;
  }

}
