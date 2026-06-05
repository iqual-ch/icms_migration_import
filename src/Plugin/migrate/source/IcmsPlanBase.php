<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Base class for ICMS migration plan source plugins.
 *
 * All `icms_plan_*` source plugins read from a single JSON document
 * (`icms-import-plan.json`) produced by the icms-migration-workbench.
 *
 * The plan's absolute container path is resolved in this order:
 *  1. State `icms_migration_import.plan_path` (set by `drush icms-migration:run`).
 *  2. Source configuration `plan_path` (set in the YAML, useful for tests
 *     or manual `drush migrate:import` invocations).
 *
 * Plan format (v2):
 *
 * @code
 * {
 *   "format": "icms-import-plan-v2",
 *   "site":   {"name": "...", "defaultLangcode": "de"},
 *   "gate":   {"status": "ALLOWED|BLOCKED"},
 *   "media":  [ ...IcmsPlanMedia rows... ],
 *   "pages":  [ ...IcmsPlanPages rows...  ],
 *   "content": [ ...additional node rows... ]
 * }
 * @endcode
 *
 * The decoded plan is cached statically so the five sibling migrations
 * only parse the file once per Drush invocation.
 */
abstract class IcmsPlanBase extends SourcePluginBase {

  /**
   * Static cache of decoded plans, keyed by resolved file path.
   *
   * @var array<string, array>
   */
  protected static array $planCache = [];

  /**
   * Resolve the absolute path to the plan JSON.
   *
   * @throws \Drupal\migrate\MigrateException
   *   If neither State nor configuration provides a usable path.
   */
  protected function getPlanPath(): string {
    $path = (string) \Drupal::state()->get('icms_migration_import.plan_path', '');
    if ($path === '') {
      $path = (string) ($this->configuration['plan_path'] ?? '');
    }
    if ($path === '') {
      throw new \Drupal\migrate\MigrateException(
        'No plan path configured. Run `drush icms-migration:run <plan.json>` or '
        . 'set `source.plan_path` in the migration YAML.'
      );
    }
    if (!is_file($path) || !is_readable($path)) {
      throw new \Drupal\migrate\MigrateException("ICMS plan not found or unreadable: $path");
    }
    return $path;
  }

  /**
   * Load and validate the plan.
   *
   * @return array
  *   Decoded plan with at least `format` and (optionally) `media`, `pages`,
  *   and `content` arrays.
   *
   * @throws \Drupal\migrate\MigrateException
   *   On invalid JSON or unknown format.
   */
  protected function loadPlan(): array {
    $path = $this->getPlanPath();
    if (isset(static::$planCache[$path])) {
      return static::$planCache[$path];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      throw new \Drupal\migrate\MigrateException("Plan is not valid JSON: $path");
    }
    $format = (string) ($data['format'] ?? '');
    if (strpos($format, 'icms-import-plan') !== 0) {
      throw new \Drupal\migrate\MigrateException("Plan format unrecognized: '$format' (expected 'icms-import-plan-v*')");
    }
    if (isset($data['pages']) && !is_array($data['pages'])) {
      throw new \Drupal\migrate\MigrateException("Plan 'pages' value is not an array: $path");
    }
    if (isset($data['content']) && !is_array($data['content'])) {
      throw new \Drupal\migrate\MigrateException("Plan 'content' value is not an array: $path");
    }
    if (empty($data['pages']) && empty($data['content'])) {
      throw new \Drupal\migrate\MigrateException("Plan has no 'pages' or 'content' rows: $path");
    }
    static::$planCache[$path] = $data;
    return $data;
  }

  /**
   * Return all node-like rows from the plan.
   *
   * Earlier exports used only `pages[]`. Newer exports can place additional
   * node bundles such as news and events in `content[]`. Both shapes use the
   * same row schema, so the node, paragraph, and translation migrations should
   * process them together.
   *
   * @param array $plan
   *   Decoded migration plan.
   *
   * @return array<int, array>
   *   Node rows from `pages[]` followed by `content[]`.
   */
  protected function planNodeRows(array $plan): array {
    $rows = [];
    foreach (['pages', 'content'] as $key) {
      foreach (($plan[$key] ?? []) as $row) {
        if (is_array($row)) {
          $rows[] = $row;
        }
      }
    }
    return $rows;
  }

  /**
   * Reset the plan cache (used by Drush wrapper between successive runs).
   */
  public static function resetPlanCache(): void {
    static::$planCache = [];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->pluginId;
  }

}
