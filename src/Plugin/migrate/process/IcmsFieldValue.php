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
    // Stash the row so normalize() can resolve sibling paragraph references.
    $this->currentRow = $row;
    try {
      foreach ($value as $name => $def) {
        // Ignore planner metadata keys that share the same map.
        if (!is_string($name) || $name === '' || $name[0] === '_') {
          continue;
        }
        $normalized = $this->normalize($def, $name);
        if ($normalized === NULL) {
          continue;
        }
        $row->setDestinationProperty($name, $normalized);
      }
    }
    finally {
      $this->currentRow = NULL;
    }
    return NULL;
  }

  /**
   * Currently-processed row (set during transform()).
   */
  protected ?Row $currentRow = NULL;

  /**
   * Convert a plan field definition into a Drupal-compatible field value.
   */
  protected function normalize(mixed $def, ?string $fieldName = NULL): mixed {
    if ($def === NULL || $def === '') {
      return NULL;
    }
    if (is_string($def) || is_numeric($def) || is_bool($def)) {
      if ($def === 'default' && $fieldName !== NULL) {
        return match ($fieldName) {
          // The plan uses `default` as a placeholder, but GraphQL exposes the
          // stored value directly. The frontend only renders title tags for
          // concrete weights (h1/h2/h3/p), so keep migrated paragraphs aligned
          // with Drupal's configured field default.
          'field_icms_title_weight' => 'h2',
          'field_icms_title_style' => 'heading-2',
          default => $def,
        };
      }
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

    // Entity reference to a sibling paragraph row emitted by the same
    // icms_paragraphs migration (used for child icms_button_element paragraphs
    // emitted alongside their parent component). Resolves the sibling's
    // composite source id and returns target_id + target_revision_id.
    if (array_key_exists('sourceParagraphRelative', $def)) {
      $index = (int) $def['sourceParagraphRelative'];
      $ref = $this->lookupSiblingParagraphRef($index);
      return $ref;
    }
    if (array_key_exists('sourceParagraphId', $def)) {
      $ref = $this->lookupParagraphRefByCompositeId((string) $def['sourceParagraphId']);
      return $ref;
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
        $n = $this->normalize($item, $fieldName);
        if ($n !== NULL) {
          $out[] = $n;
        }
      }
      return $out === [] ? NULL : $out;
    }

    // Plan multi-value wrapper: {source: '...', values: [...]}.
    if (array_key_exists('values', $def) && is_array($def['values'])) {
      return $this->normalize(array_values($def['values']), $fieldName);
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

  /**
   * Resolve a sibling paragraph reference using the current row context.
   *
   * Builds the composite source id `<pageUuid>:<delta>:<index>` and looks it
   * up in the paragraphs migrate_map so the parent paragraph can reference
   * children emitted just before it in the same migration.
   */
  protected function lookupSiblingParagraphRef(int $relativeIndex): ?array {
    if ($this->currentRow === NULL) {
      return NULL;
    }
    $pageUuid = (string) $this->currentRow->getSourceProperty('pageUuid');
    $delta = (int) $this->currentRow->getSourceProperty('delta');
    if ($pageUuid === '') {
      return NULL;
    }
    $compositeId = sprintf('%s:%d:%d', $pageUuid, $delta, $relativeIndex);
    return $this->lookupParagraphRefByCompositeId($compositeId);
  }

  /**
   * Look up a paragraph entity_reference_revisions ref by composite source id.
   */
  protected function lookupParagraphRefByCompositeId(string $compositeId): ?array {
    if ($compositeId === '') {
      return NULL;
    }
    $migration = (string) ($this->configuration['paragraphs_migration'] ?? 'icms_paragraphs');
    $table = 'migrate_map_' . $migration;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $found = $this->database->select($table, 'm')
      ->fields('m', ['destid1', 'destid2'])
      ->condition('sourceid1', $compositeId)
      ->execute()
      ->fetchAssoc();
    if (!$found || empty($found['destid1'])) {
      return NULL;
    }
    $ref = ['target_id' => (int) $found['destid1']];
    if (!empty($found['destid2'])) {
      $ref['target_revision_id'] = (int) $found['destid2'];
    }
    return $ref;
  }

}
