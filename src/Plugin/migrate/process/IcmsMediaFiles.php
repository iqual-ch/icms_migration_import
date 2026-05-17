<?php

namespace Drupal\icms_migration_import\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attaches files from the plan's `media[].files[]` list to dynamic fields.
 *
 * Each entry has the shape:
 *   {fieldName: 'field_media_image', sourceUrl: 'https://…', filename: '…', mimeType: '…'}
 *
 * The plugin resolves each sourceUrl through migrate_map_icms_files,
 * builds a Drupal entity reference value, and calls
 * `Row::setDestinationProperty($fieldName, …)` so the media bundle's
 * file-reference field gets a real file id.
 *
 * If the same field name appears multiple times, values are aggregated
 * (multi-value support).
 *
 * Configuration:
 *  - files_migration (default 'icms_files'): id of the upstream files migration.
 *  - alt_field, title_field: optional keys to copy `alt` / `title` from
 *    the file entry onto the image-reference value.
 *
 * @MigrateProcessPlugin(
 *   id = "icms_media_files",
 *   handle_multiples = TRUE
 * )
 */
class IcmsMediaFiles extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected Connection $database,
  ) {
    parent::__construct(
      $configuration + [
        'files_migration' => 'icms_files',
        'alt_field' => 'alt',
        'title_field' => 'title',
      ],
      $plugin_id,
      $plugin_definition,
    );
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
    $byField = [];
    foreach ($value as $file) {
      if (!is_array($file)) {
        continue;
      }
      $field = (string) ($file['fieldName'] ?? '');
      $url = (string) ($file['sourceUrl'] ?? '');
      if ($field === '' || $url === '') {
        continue;
      }
      $fid = $this->lookupFileId($url);
      if ($fid === NULL) {
        continue;
      }
      $entry = ['target_id' => $fid];
      if (!empty($file[$this->configuration['alt_field']])) {
        $entry['alt'] = (string) $file[$this->configuration['alt_field']];
      }
      if (!empty($file[$this->configuration['title_field']])) {
        $entry['title'] = (string) $file[$this->configuration['title_field']];
      }
      $byField[$field][] = $entry;
    }
    foreach ($byField as $field => $entries) {
      // Single-cardinality fields can take just the first element; multi-value
      // fields accept the whole list. Drupal entity destinations tolerate
      // an array of values either way.
      $row->setDestinationProperty($field, $entries);
    }
    return NULL;
  }

  /**
   * Resolve a source URL to its imported file entity id.
   */
  protected function lookupFileId(string $url): ?int {
    $migration = (string) $this->configuration['files_migration'];
    $table = 'migrate_map_' . $migration;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $found = $this->database->select($table, 'm')
      ->fields('m', ['destid1'])
      ->condition('sourceid1', $url)
      ->execute()
      ->fetchField();
    return $found ? (int) $found : NULL;
  }

}
