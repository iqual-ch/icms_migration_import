<?php

namespace Drupal\icms_migration_import\Plugin\migrate\source;

/**
 * Source plugin emitting one row per non-default-language page translation.
 *
 * A page in the plan exposes `source.translations[langcode]` for every
 * language it covers; the default language is materialised by
 * `icms_plan_pages` so it is skipped here. The destination migration
 * uses `translations: true` on `entity:node` and looks the parent node
 * up via `migration_lookup: icms_pages` on `sourceUuid`.
 *
 * @MigrateSource(
 *   id = "icms_plan_translations",
 *   source_module = "icms_migration_import"
 * )
 */
class IcmsPlanTranslations extends IcmsPlanBase {

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
      $pageDefault = (string) ($page['target']['langcode']
        ?? $page['source']['defaultLangcode']
        ?? $defaultLang);
      $translations = $page['source']['translations'] ?? [];
      if (!is_array($translations)) {
        continue;
      }
      foreach ($translations as $langcode => $tx) {
        $langcode = (string) $langcode;
        if ($langcode === '' || $langcode === $pageDefault) {
          continue;
        }
        $rows[] = [
          'sourceUuid' => $uuid,
          'langcode' => $langcode,
          'title' => (string) ($tx['title'] ?? ''),
          'pathAlias' => (string) ($tx['pathAlias'] ?? ''),
          'fields' => $tx['fields'] ?? [],
        ];
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
      'sourceUuid' => $this->t('UUID of the parent (default-language) node.'),
      'langcode' => $this->t('Target translation langcode.'),
      'title' => $this->t('Title in this translation.'),
      'pathAlias' => $this->t('Path alias for this language (optional).'),
      'fields' => $this->t('Extra field overrides for this translation.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'sourceUuid' => ['type' => 'string', 'max_length' => 36],
      'langcode' => ['type' => 'string', 'max_length' => 12],
    ];
  }

}
