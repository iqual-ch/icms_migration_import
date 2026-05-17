<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

/**
 * Source plugin emitting one row per page (default-language node).
 *
 * Translations are handled by `icms_plan_translations` so that the node
 * exists first and translations can be attached via migration_lookup.
 *
 * @MigrateSource(
 *   id = "icms_plan_pages",
 *   source_module = "icms_migration_import"
 * )
 */
class IcmsPlanPages extends IcmsPlanBase {

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    return new \ArrayIterator($this->rows());
  }

  /**
   * @return array<int, array>
   */
  protected function rows(): array {
    $plan = $this->loadPlan();
    $defaultLang = (string) ($plan['site']['defaultLangcode'] ?? 'en');
    $rows = [];
    foreach ($plan['pages'] ?? [] as $page) {
      $pageStatus = strtoupper((string) ($page['status'] ?? 'AUTO'));
      if ($pageStatus === 'BLOCKED') {
        continue;
      }
      $uuid = (string) ($page['source']['uuid'] ?? '');
      if ($uuid === '') {
        continue;
      }
      $target = $page['target'] ?? [];
      $source = $page['source'] ?? [];

      // Collect ordered paragraph references for the field_icms_paragraphs
      // reference. sub_process iterates over arrays of arrays, so each entry
      // is wrapped in {sourceParagraphId: '<id>'} for downstream migration_lookup.
      $paragraphRefs = [];
      foreach ($page['components'] ?? [] as $comp) {
        $compStatus = strtoupper((string) ($comp['status'] ?? 'AUTO'));
        if ($compStatus === 'BLOCKED') {
          continue;
        }
        $delta = (int) ($comp['delta'] ?? 0);
        foreach (array_keys($comp['target']['paragraphs'] ?? []) as $index) {
          $paragraphRefs[] = [
            'sourceParagraphId' => sprintf('%s:%d:%d', $uuid, $delta, (int) $index),
          ];
        }
      }

      $rows[] = [
        'sourceUuid' => $uuid,
        'sourceNid' => (int) ($source['nid'] ?? 0),
        'bundle' => (string) ($target['bundle'] ?? 'icms_page'),
        'paragraphField' => (string) ($target['paragraphField'] ?? 'field_icms_paragraphs'),
        'langcode' => (string) ($target['langcode'] ?? ($source['defaultLangcode'] ?? $defaultLang)),
        'title' => (string) ($target['title'] ?? ($source['title'] ?? 'Untitled')),
        'pathAlias' => (string) ($target['pathAlias'] ?? ''),
        'fields' => $target['fields'] ?? [],
        'paragraphRefs' => $paragraphRefs,
      ];
    }
    return $rows;
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
      'sourceUuid' => $this->t('Source node UUID (preserved as target UUID).'),
      'sourceNid' => $this->t('Source nid (for messages / debugging only).'),
      'bundle' => $this->t('Target node bundle.'),
      'paragraphField' => $this->t('Name of the field holding the paragraph references.'),
      'langcode' => $this->t('Default language of the target node.'),
      'title' => $this->t('Default-language title.'),
      'pathAlias' => $this->t('Path alias for the default language (optional).'),
      'fields' => $this->t('Map of extra node field values.'),
      'paragraphRefs' => $this->t('Ordered list of {sourceParagraphId: id} entries, resolved via icms_paragraphs lookup in a sub_process.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'sourceUuid' => [
        'type' => 'string',
        'max_length' => 36,
      ],
    ];
  }

}
