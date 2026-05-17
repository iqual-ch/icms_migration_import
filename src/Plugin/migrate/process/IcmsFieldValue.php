<?php

namespace Drupal\icms_migration_import\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unpacks an ICMS plan `fields` map onto a row's destination properties.
 *
 * Plan field values can take several shapes:
 *  - scalar (string, int, bool)                         → stored as-is
 *  - {value, format?}                                   → formatted-text field
 *  - {sourceMediaId: N}                                 → entity reference resolved
 *                                                         via migrate_map_icms_media
 *  - list of any of the above                           → multi-value field
 *  - {status, source, ...} (planning markers, no value) → skipped
 *
 * Usage (in YAML):
 * @code
 * process:
 *   type: bundle
 *   _unpack_fields:
 *     plugin: icms_field_value
 *     source: fields
 * @endcode
 *
 * The `_unpack_fields` key is a pseudo-destination — the plugin emits
 * destination properties via `Row::setDestinationProperty()` for every
 * entry in the map and returns NULL so no `_unpack_fields` column is
 * written to the destination.
 *
 * Configuration:
 *  - media_migration (default 'icms_media'): id of the upstream media
 *    migration used to translate {sourceMediaId} into a Drupal mid.
 *
 * @MigrateProcessPlugin(
 *   id = "icms_field_value",
 *   handle_multiples = TRUE
 * )
 */
class IcmsFieldValue extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_array($value) || $value === []) {
      return NULL;
    }
    foreach ($value as $name => $def) {
      // Ignore planner metadata keys that share the same map.
      if (!is_string($name) || $name === '' || $name[0] === '_') {
        continue;
      }
      $normalized = $this->normalize($def);
      if ($normalized === NULL) {
        continue;
      }
      $row->setDestinationProperty($name, $normalized);
    }
    return NULL;
  }

  /**
   * Convert a plan field definition into a Drupal-compatible field value.
   */
  protected function normalize(mixed $def): mixed {
    if ($def === NULL || $def === '') {
      return NULL;
    }
    if (is_string($def) || is_numeric($def) || is_bool($def)) {
      return $def;
    }
    if (!is_array($def)) {
      return NULL;
    }

    // Entity reference to media via the icms_media migration.
    if (array_key_exists('sourceMediaId', $def)) {
      $sid = (int) $def['sourceMediaId'];
      if ($sid <= 0) {
        return NULL;
      }
      $mid = $this->lookupMediaTargetId($sid);
      return $mid === NULL ? NULL : ['target_id' => $mid];
    }

    // Formatted text: {value: '...', format?: '...'}.
    if (array_key_exists('value', $def)) {
      $value = $def['value'];
      if (is_string($value) && (str_contains($value, '<') || isset($def['format']))) {
        return [
          'value' => $value,
          'format' => $def['format'] ?? 'basic_html',
        ];
      }
      return $value;
    }

    // Multi-value field: list of the above.
    if (array_is_list($def)) {
      $out = [];
      foreach ($def as $item) {
        $n = $this->normalize($item);
        if ($n !== NULL) {
          $out[] = $n;
        }
      }
      return $out === [] ? NULL : $out;
    }

    // Planning-only markers — never written.
    if (isset($def['status']) || isset($def['source'])) {
      return NULL;
    }

    // Unknown associative shape: pass through verbatim so the destination
    // can attempt to consume it (preserves forward compatibility).
    return $def;
  }

  /**
   * Look up the target media id for a given source media id.
   */
  protected function lookupMediaTargetId(int $sourceMediaId): ?int {
    $migration = (string) ($this->configuration['media_migration'] ?? 'icms_media');
    // The migrate_map table name is derived from the migration id.
    $table = 'migrate_map_' . $migration;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $found = $this->database->select($table, 'm')
      ->fields('m', ['destid1'])
      ->condition('sourceid1', $sourceMediaId)
      ->execute()
      ->fetchField();
    return $found ? (int) $found : NULL;
  }

}
