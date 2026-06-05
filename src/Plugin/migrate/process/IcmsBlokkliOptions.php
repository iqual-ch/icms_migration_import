<?php

namespace Drupal\icms_migration_import\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Stores plan paragraph options as Paragraphs Blokkli behavior settings.
 *
 * Plan rows can provide an `options` map next to their field values. Blökkli
 * stores those values in the serialized `behavior_settings` base field under
 * the `paragraphs_blokkli_data` behavior plugin id.
 *
 * Usage (in YAML):
 * @code
 * process:
 *   _blokkli_options:
 *     plugin: icms_blokkli_options
 *     source: options
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "icms_blokkli_options",
 *   handle_multiples = TRUE
 * )
 */
class IcmsBlokkliOptions extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_array($value) || $value === []) {
      return NULL;
    }

    $mapped = [];
    foreach ($value as $key => $optionValue) {
      if (!is_string($key) || $key === '' || $key[0] === '_') {
        continue;
      }
      $mapped[$key] = $this->toPersistableValue($optionValue);
    }

    if ($mapped !== []) {
      $row->setDestinationProperty('behavior_settings', serialize([
        'paragraphs_blokkli_data' => $mapped,
      ]));
    }

    return NULL;
  }

  /**
   * Convert plan option values to the string format used by Blökkli settings.
   */
  protected function toPersistableValue(mixed $value): string {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        if (is_scalar($item) || $item === NULL) {
          $parts[] = $this->toPersistableValue($item);
        }
      }
      return implode(',', $parts);
    }
    if ($value === NULL) {
      return '';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    return '';
  }

}
