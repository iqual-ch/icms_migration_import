<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

/**
 * Source plugin emitting one row per media entity declared by the plan.
 *
 * Files referenced by `files[].sourceUrl` are resolved at runtime via
 * `migration_lookup: icms_files` against the source URL.
 *
 * @MigrateSource(
 *   id = "icms_plan_media",
 *   source_module = "icms_migration_import"
 * )
 */
class IcmsPlanMedia extends IcmsPlanBase {

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $plan = $this->loadPlan();
    $rows = [];
    foreach ($plan['media'] ?? [] as $media) {
      $sid = $media['sourceMediaId'] ?? NULL;
      if ($sid === NULL) {
        continue;
      }
      $rows[] = [
        'sourceMediaId' => (int) $sid,
        'uuid' => (string) ($media['uuid'] ?? ''),
        'bundle' => (string) ($media['bundle'] ?? ''),
        'label' => (string) ($media['label'] ?? ''),
        'langcode' => (string) ($media['langcode'] ?? ($plan['site']['defaultLangcode'] ?? 'en')),
        'files' => $media['files'] ?? [],
        'fields' => $media['fields'] ?? [],
      ];
    }
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE): int {
    $plan = $this->loadPlan();
    return count(array_filter($plan['media'] ?? [], fn($m) => isset($m['sourceMediaId'])));
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'sourceMediaId' => $this->t('Source media entity id (unique).'),
      'uuid' => $this->t('Target media UUID (preserved for idempotency).'),
      'bundle' => $this->t('Target media bundle (image, file, remote_video, ...).'),
      'label' => $this->t('Media label / name field.'),
      'langcode' => $this->t('Media langcode.'),
      'files' => $this->t('List of {fieldName, sourceUrl, filename, mimeType} entries to attach.'),
      'fields' => $this->t('Map of extra non-file fields (e.g. field_media_oembed_video).'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'sourceMediaId' => ['type' => 'integer'],
    ];
  }

}
