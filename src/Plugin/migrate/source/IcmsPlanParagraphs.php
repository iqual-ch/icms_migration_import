<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

/**
 * Source plugin emitting one row per paragraph the plan wants to create.
 *
 * A page in the plan contains `components[]`, each component contains
 * `target.paragraphs[]` (often a single paragraph, sometimes multiple
 * when the component was split for cardinality reasons). This plugin
 * flattens all (page, component, paragraph) tuples into one row each,
 * keyed by a synthetic `sourceParagraphId = <pageUuid>:<delta>:<index>`.
 *
 * Components whose status is BLOCKED are skipped (their pages are too,
 * unless the gate decided otherwise upstream).
 *
 * @MigrateSource(
 *   id = "icms_plan_paragraphs",
 *   source_module = "icms_migration_import"
 * )
 */
class IcmsPlanParagraphs extends IcmsPlanBase {

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
    $rows = [];
    foreach ($plan['pages'] ?? [] as $page) {
      $pageStatus = strtoupper((string) ($page['status'] ?? 'AUTO'));
      if ($pageStatus === 'BLOCKED') {
        continue;
      }
      $pageUuid = (string) ($page['source']['uuid'] ?? '');
      if ($pageUuid === '') {
        continue;
      }
      foreach ($page['components'] ?? [] as $comp) {
        $compStatus = strtoupper((string) ($comp['status'] ?? 'AUTO'));
        if ($compStatus === 'BLOCKED') {
          continue;
        }
        $delta = (int) ($comp['delta'] ?? 0);
        $bundle = (string) (($comp['target']['paragraphBundle'] ?? '') ?: '');
        if ($bundle === '') {
          continue;
        }
        $paragraphs = $comp['target']['paragraphs'] ?? [];
        foreach ($paragraphs as $index => $pData) {
          $rows[] = [
            'sourceParagraphId' => sprintf('%s:%d:%d', $pageUuid, $delta, (int) $index),
            'pageUuid' => $pageUuid,
            'delta' => $delta,
            'index' => (int) $index,
            'bundle' => $bundle,
            'fields' => $pData['fields'] ?? [],
            // Default langcode of the host page (translations are added later).
            'langcode' => (string) ($page['target']['langcode']
              ?? $page['source']['defaultLangcode']
              ?? ($plan['site']['defaultLangcode'] ?? 'en')),
          ];
        }
      }
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE): int {
    return count($this->rows());
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'sourceParagraphId' => $this->t('Synthetic stable id: <pageUuid>:<delta>:<index>.'),
      'pageUuid' => $this->t('UUID of the host page (for sub_process lookups).'),
      'delta' => $this->t('Position of the parent component in the page.'),
      'index' => $this->t('Position of the paragraph inside a split component.'),
      'bundle' => $this->t('Target paragraph bundle.'),
      'fields' => $this->t('Map of {fieldName: planFieldValue}; normalized by icms_field_value process plugin.'),
      'langcode' => $this->t('Default langcode inherited from the host page.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'sourceParagraphId' => [
        'type' => 'string',
        'max_length' => 255,
      ],
    ];
  }

}
