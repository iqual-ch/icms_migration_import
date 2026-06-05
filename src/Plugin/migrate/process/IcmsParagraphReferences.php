<?php

namespace Drupal\icms_migration_import\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Writes migrated paragraph references to the row's configured paragraph field.
 *
 * The import plan can target multiple node bundles. They usually use
 * `field_icms_paragraphs`, but the plan exposes the concrete field name as
 * `paragraphField`. This process plugin resolves the collected
 * `paragraphRefs[]` against the paragraph migration map and writes them to the
 * field named by that source property.
 *
 * Usage (in YAML):
 * @code
 * process:
 *   _paragraph_refs:
 *     plugin: icms_paragraph_references
 *     source: paragraphRefs
 *     field_source: paragraphField
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "icms_paragraph_references",
 *   handle_multiples = TRUE
 * )
 */
class IcmsParagraphReferences extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
    $fieldSource = (string) ($this->configuration['field_source'] ?? 'paragraphField');
    $fieldName = (string) $row->getSourceProperty($fieldSource);
    if ($fieldName === '') {
      $fieldName = 'field_icms_paragraphs';
    }
    if (!is_array($value) || $value === []) {
      return NULL;
    }

    $refs = [];
    foreach ($value as $item) {
      if (!is_array($item)) {
        continue;
      }
      $sourceParagraphId = (string) ($item['sourceParagraphId'] ?? '');
      if ($sourceParagraphId === '') {
        continue;
      }
      $ref = $this->lookupParagraphRefByCompositeId($sourceParagraphId);
      if ($ref !== NULL) {
        $refs[] = $ref;
      }
    }

    if ($refs !== []) {
      $row->setDestinationProperty($fieldName, $refs);
    }

    return NULL;
  }

  /**
   * Look up a paragraph entity_reference_revisions ref by composite source id.
   */
  protected function lookupParagraphRefByCompositeId(string $compositeId): ?array {
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
