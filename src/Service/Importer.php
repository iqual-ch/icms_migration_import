<?php

namespace Drupal\icms_migration_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Walks an `icms-import-plan.json` and creates/updates Drupal entities.
 *
 * The plan structure (per page):
 *
 * @code
 * {
 *   "source":     {"nid": 33, "uuid": "…", "title": "…",
 *                  "translations": {"de": {"path":"/x","title":"…","status":true}}},
 *   "target":     {"bundle": "icms_page", "paragraphField": "field_icms_paragraphs",
 *                  "langcode": "de", "pathAlias": "/startseite",
 *                  "fields": {"title":"…"}},
 *   "components": [
 *     {
 *       "delta": 0,
 *       "status": "AUTO|AUTO_SPLIT|REVIEW|BLOCKED",
 *       "target": {
 *         "paragraphBundle": "icms_layout_text_media",
 *         "paragraphField": "field_icms_paragraphs",
 *         "paragraphs": [
 *           {"fields": {"field_icms_title": {"value": "…"},
 *                       "field_icms_text":  {"value": "<p>…</p>"}}}
 *         ]
 *       }
 *     }
 *   ],
 *   "status": "AUTO|REVIEW|BLOCKED",
 *   "validation": {"messages": [...]}
 * }
 * @endcode
 *
 * Behaviour:
 *  - BLOCKED pages/components: skipped unless --include-review or --blocked=placeholder.
 *  - --blocked=placeholder    : creates an empty paragraph stub for blocked components.
 *  - --blocked=skip           : skips the component entirely.
 *  - --dry-run                : runs validation only, no entity is saved.
 *  - Idempotent: looks up nodes by (uuid OR title+bundle) and updates instead of duplicating.
 */
class Importer {

  protected LoggerChannelInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected AliasManagerInterface $aliasManager,
    protected MediaImporter $mediaImporter,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('icms_migration_import');
  }

  /**
   * Import every page in the plan.
   *
   * @param array $plan
   *   Decoded plan.
   * @param array $options
   *   - blocked: 'placeholder'|'skip'
   *   - include-review: bool
   *   - dry-run: bool
   *   - title-prefix: string|null
   *   - alias-prefix: string|null
   *
   * @return array
   *   Stats: pages, paragraphs, skipped, errors.
   */
  public function importPlan(array $plan, array $options = []): array {
    $defaults = [
      'blocked' => 'placeholder',
      'include-review' => FALSE,
      'dry-run' => FALSE,
      'title-prefix' => NULL,
      'alias-prefix' => NULL,
    ];
    $options += $defaults;

    $stats = [
      'pages' => 0,
      'paragraphs' => 0,
      'skipped' => 0,
      'blocked' => 0,
      'errors' => [],
    ];

    foreach ($plan['pages'] as $page) {
      try {
        $r = $this->importPage($page, $options);
        $stats['pages'] += $r['saved'] ? 1 : 0;
        $stats['paragraphs'] += $r['paragraphs'];
        $stats['skipped'] += $r['skipped'];
        $stats['blocked'] += $r['blocked'];
      }
      catch (\Throwable $e) {
        $nid = $page['source']['nid'] ?? '?';
        $stats['errors'][] = "page nid=$nid: " . $e->getMessage();
        $this->logger->error("page nid=@nid failed: @msg", ['@nid' => $nid, '@msg' => $e->getMessage()]);
      }
    }
    return $stats;
  }

  /**
   * Create / update one ICMS page.
   *
   * @return array{saved:bool, paragraphs:int, skipped:int, blocked:int}
   */
  protected function importPage(array $page, array $options): array {
    $result = ['saved' => FALSE, 'paragraphs' => 0, 'skipped' => 0, 'blocked' => 0];

    $pageStatus = (string) ($page['status'] ?? 'AUTO');
    if ($pageStatus === 'BLOCKED' && !$options['include-review']) {
      $result['blocked']++;
      return $result;
    }

    $target = $page['target'] ?? [];
    $bundle = (string) ($target['bundle'] ?? 'page');
    $paragraphField = (string) ($target['paragraphField'] ?? '');
    $defaultLang = (string) ($target['langcode'] ?? 'en');
    $title = (string) ($target['fields']['title'] ?? $page['source']['title'] ?? 'Untitled');
    if (!empty($options['title-prefix'])) {
      $title = $options['title-prefix'] . $title;
    }
    $sourceUuid = $page['source']['uuid'] ?? NULL;

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Look up existing node (idempotency).
    $node = NULL;
    if ($sourceUuid) {
      $found = $nodeStorage->loadByProperties(['uuid' => $sourceUuid]);
      $node = $found ? reset($found) : NULL;
    }
    if (!$node) {
      $found = $nodeStorage->loadByProperties(['type' => $bundle, 'title' => $title]);
      $node = $found ? reset($found) : NULL;
    }

    if ($options['dry-run']) {
      // Count paragraphs we would create.
      foreach ($page['components'] ?? [] as $comp) {
        $cs = (string) ($comp['status'] ?? 'AUTO');
        if ($cs === 'BLOCKED' && $options['blocked'] === 'skip') {
          $result['skipped']++;
          continue;
        }
        $result['paragraphs'] += count($comp['target']['paragraphs'] ?? []);
      }
      $result['saved'] = TRUE;
      return $result;
    }

    if (!$node) {
      $values = [
        'type' => $bundle,
        'title' => $title,
        'langcode' => $defaultLang,
        'status' => 1,
      ];
      if ($sourceUuid) {
        $values['uuid'] = $sourceUuid;
      }
      $node = $nodeStorage->create($values);
    }
    else {
      $node->setTitle($title);
      $node->set('status', 1);
    }

    // Build paragraphs.
    $paragraphRefs = [];
    foreach ($page['components'] ?? [] as $comp) {
      $cs = (string) ($comp['status'] ?? 'AUTO');
      if ($cs === 'BLOCKED') {
        if ($options['blocked'] === 'skip') {
          $result['skipped']++;
          continue;
        }
        // placeholder: create an empty stub of the proposed bundle (or text fallback).
        $bundle = $comp['target']['paragraphBundle'] ?? 'icms_layout_text';
        $stub = $this->createParagraph($bundle, ['field_icms_text' => ['value' => '<!-- BLOCKED: needs manual mapping -->', 'format' => 'basic_html']]);
        if ($stub) {
          $paragraphRefs[] = ['target_id' => $stub->id(), 'target_revision_id' => $stub->getRevisionId()];
          $result['blocked']++;
        }
        continue;
      }
      foreach ($comp['target']['paragraphs'] ?? [] as $pData) {
        $paragraph = $this->createParagraphFromPlan($comp['target']['paragraphBundle'] ?? '', $pData);
        if ($paragraph) {
          $paragraphRefs[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
          $result['paragraphs']++;
        }
      }
    }
    if ($paragraphField !== '' && $node->hasField($paragraphField)) {
      $node->set($paragraphField, $paragraphRefs);
    }

    $node->save();

    // Path alias.
    $alias = (string) ($target['pathAlias'] ?? '');
    if (!empty($options['alias-prefix'])) {
      $alias = rtrim($options['alias-prefix'], '/') . '/' . ltrim($alias, '/');
    }
    if ($alias !== '') {
      $this->ensureAlias('/node/' . $node->id(), '/' . ltrim($alias, '/'), $defaultLang);
    }

    $result['saved'] = TRUE;
    return $result;
  }

  /**
   * Create a paragraph entity from the plan's `paragraphs[i]` block.
   */
  protected function createParagraphFromPlan(string $bundle, array $pData): ?object {
    if ($bundle === '') {
      return NULL;
    }
    $values = [];
    foreach ($pData['fields'] ?? [] as $name => $def) {
      // The `source` key inside fields holds non-field metadata; ignore.
      if ($name === 'source') {
        continue;
      }
      $value = $this->normalizeFieldValue($def);
      if ($value !== NULL) {
        $values[$name] = $value;
      }
    }
    return $this->createParagraph($bundle, $values);
  }

  /**
   * Convert a plan field definition into Drupal-compatible field value(s).
   */
  protected function normalizeFieldValue(mixed $def): mixed {
    // Strings come through directly.
    if (is_string($def) || is_numeric($def)) {
      return $def;
    }
    if (!is_array($def)) {
      return NULL;
    }
    // {value: "...", format?: "..."} → assume formatted text.
    if (array_key_exists('value', $def)) {
      $value = $def['value'];
      if (is_string($value) && (strpos($value, '<') !== FALSE || isset($def['format']))) {
        return [
          'value' => $value,
          'format' => $def['format'] ?? 'basic_html',
        ];
      }
      return $value;
    }
    // Lists.
    if (array_is_list($def)) {
      $out = [];
      foreach ($def as $item) {
        $v = $this->normalizeFieldValue($item);
        if ($v !== NULL) {
          $out[] = $v;
        }
      }
      return $out;
    }
    // Status markers from the planner: skip silently.
    if (isset($def['status']) || isset($def['source'])) {
      return NULL;
    }
    return $def;
  }

  /**
   * Save a paragraph entity if the bundle exists.
   */
  protected function createParagraph(string $bundle, array $values): ?object {
    try {
      $storage = $this->entityTypeManager->getStorage('paragraph');
    }
    catch (\Throwable $e) {
      $this->logger->error('paragraphs module not available: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
    $bundles = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    if (!isset($bundles[$bundle])) {
      $this->logger->warning('Unknown paragraph bundle "@b" — skipping.', ['@b' => $bundle]);
      return NULL;
    }
    $values['type'] = $bundle;
    $paragraph = $storage->create($values);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Create or update a path alias.
   */
  protected function ensureAlias(string $source, string $alias, string $langcode): void {
    try {
      $storage = $this->entityTypeManager->getStorage('path_alias');
    }
    catch (\Throwable $e) {
      return;
    }
    $existing = $storage->loadByProperties(['path' => $source, 'langcode' => $langcode]);
    if ($existing) {
      $entity = reset($existing);
      $entity->set('alias', $alias);
      $entity->save();
      return;
    }
    $storage->create([
      'path' => $source,
      'alias' => $alias,
      'langcode' => $langcode,
    ])->save();
  }

}
