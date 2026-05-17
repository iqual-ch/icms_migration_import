<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

/**
 * Source plugin emitting one row per remote file referenced by the plan.
 *
 * Files are deduplicated by their absolute source URL so the same image
 * referenced by several media entities is downloaded only once. The
 * `entity:file` destination uses the `file_copy` process plugin to fetch
 * the file from the source URL at import time.
 *
 * @MigrateSource(
 *   id = "icms_plan_files",
 *   source_module = "icms_migration_import"
 * )
 */
class IcmsPlanFiles extends IcmsPlanBase {

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    return new \ArrayIterator($this->rows());
  }

  /**
   * Build deduplicated file rows from the plan's media[].files[] entries.
   *
   * @return array<int, array{sourceUrl:string, filename:string, mimeType:?string, mediaUuid:?string}>
   */
  protected function rows(): array {
    $plan = $this->loadPlan();
    $rows = [];
    foreach ($plan['media'] ?? [] as $media) {
      $mediaUuid = $media['uuid'] ?? NULL;
      foreach ($media['files'] ?? [] as $file) {
        $url = (string) ($file['sourceUrl'] ?? '');
        if ($url === '' || isset($rows[$url])) {
          continue;
        }
        $rows[$url] = [
          'sourceUrl' => $url,
          'filename' => (string) ($file['filename'] ?? basename(parse_url($url, PHP_URL_PATH) ?: $url)),
          'mimeType' => $file['mimeType'] ?? NULL,
          'mediaUuid' => $mediaUuid,
        ];
      }
    }
    return array_values($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE) {
    return count($this->rows());
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'sourceUrl' => $this->t('Absolute source URL of the file.'),
      'filename' => $this->t('Base filename, used to build the destination URI.'),
      'mimeType' => $this->t('MIME type as reported by the source (optional).'),
      'mediaUuid' => $this->t('UUID of the first referencing media entity (optional).'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'sourceUrl' => [
        'type' => 'string',
        'max_length' => 2048,
      ],
    ];
  }

}
