<?php

namespace Drupal\icms_migration_import\Service;

/**
 * Reads and validates an icms-import-plan.json file.
 */
class PlanReader {

  /**
   * Load and decode a plan file from disk.
   *
   * @param string $path
   *   Absolute path inside the container (e.g. /var/www/html/tmp/...).
   *
   * @return array
   *   Decoded plan.
   *
   * @throws \InvalidArgumentException
   *   On missing/invalid file.
   */
  public function load(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
      throw new \InvalidArgumentException("Plan file not found or unreadable: $path");
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      throw new \InvalidArgumentException("Plan is not valid JSON: $path");
    }
    if (!isset($data['format']) || strpos($data['format'], 'icms-import-plan') !== 0) {
      throw new \InvalidArgumentException("Plan format unrecognized: " . ($data['format'] ?? '(missing)'));
    }
    if (!isset($data['pages']) || !is_array($data['pages'])) {
      throw new \InvalidArgumentException("Plan has no 'pages' array.");
    }
    return $data;
  }

  /**
   * Filter pages by NIDs (comma-separated string or array of ints).
   */
  public function filterByNids(array $plan, ?string $nids): array {
    if ($nids === NULL || $nids === '') {
      return $plan;
    }
    $allowed = array_filter(array_map('trim', explode(',', $nids)), 'strlen');
    $allowed = array_map('intval', $allowed);
    if (!$allowed) {
      return $plan;
    }
    $plan['pages'] = array_values(array_filter(
      $plan['pages'],
      fn(array $p) => isset($p['source']['nid']) && in_array((int) $p['source']['nid'], $allowed, TRUE)
    ));
    return $plan;
  }

}
