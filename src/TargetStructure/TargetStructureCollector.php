<?php

namespace Drupal\icms_migration_import\TargetStructure;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Throwable;

/**
 * Collects a read-only description of the target ICMS Drupal structure.
 */
class TargetStructureCollector {

  public const SCHEMA_VERSION = '0.1.0';

  /**
   * Entity types that are useful to the standalone migration runner.
   */
  protected const RELEVANT_ENTITY_TYPES = [
    'node',
    'paragraph',
    'media',
    'block_content',
    'taxonomy_term',
  ];

  /**
   * Modules that commonly influence ICMS/page-designer mappings.
   */
  protected const RELEVANT_MODULES = [
    'node',
    'paragraphs',
    'media',
    'file',
    'taxonomy',
    'block_content',
    'content_translation',
    'language',
    'layout_builder',
    'layout_discovery',
    'field_layout',
    'blokkli',
    'paragraphs_blokkli',
    'migrate',
    'migrate_plus',
    'migrate_tools',
    'icms_migration_import',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected RequestContext $requestContext,
  ) {}

  /**
   * Builds the target-structure artifact as an array ready for JSON encoding.
   */
  public function collect(): array {
    $warnings = [];
    $entity_types = [];
    $bundles = [];

    foreach (self::RELEVANT_ENTITY_TYPES as $entity_type_id) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$definition) {
        $warnings[] = sprintf('Entity type "%s" is not available on this site.', $entity_type_id);
        continue;
      }
      if (!$definition->entityClassImplements('Drupal\Core\Entity\ContentEntityInterface')) {
        $warnings[] = sprintf('Entity type "%s" is not a content entity type and was skipped.', $entity_type_id);
        continue;
      }

      $entity_types[$entity_type_id] = [
        'id' => $entity_type_id,
        'label' => $this->stringify($definition->getLabel()),
        'class' => $definition->getClass(),
        'provider' => $definition->getProvider(),
        'bundle_entity_type' => $definition->getBundleEntityType(),
        'translatable' => $definition->isTranslatable(),
        'revisionable' => $definition->isRevisionable(),
      ];

      try {
        $bundle_infos = $this->bundleInfo->getBundleInfo($entity_type_id);
      }
      catch (Throwable $e) {
        $warnings[] = sprintf('Could not read bundle information for "%s": %s', $entity_type_id, $e->getMessage());
        continue;
      }

      foreach ($bundle_infos as $bundle => $info) {
        $bundles[$entity_type_id][$bundle] = $this->collectBundle($entity_type_id, $bundle, $info, $warnings);
      }
    }

    return [
      'schema_version' => self::SCHEMA_VERSION,
      'generated_at' => time(),
      'site' => $this->collectSiteInfo(),
      'entity_types' => $entity_types,
      'bundles' => $bundles,
      'paragraphs' => $this->collectParagraphSummary($bundles, $warnings),
      'media' => $this->collectMediaSummary($bundles, $warnings),
      'layout_or_blokkli' => $this->collectLayoutOrBlokkliInfo($warnings),
      'warnings' => $warnings,
    ];
  }

  /**
   * Collects site-level metadata.
   */
  protected function collectSiteInfo(): array {
    $site_config = $this->configFactory->get('system.site');

    return [
      'name' => (string) $site_config->get('name'),
      'uuid' => (string) $site_config->get('uuid'),
      'base_url' => $this->getBaseUrl(),
      'drupal_version' => \Drupal::VERSION,
      'enabled_relevant_modules' => $this->collectRelevantModules(),
    ];
  }

  /**
   * Collects enabled relevant module versions where available.
   */
  protected function collectRelevantModules(): array {
    $modules = [];
    $module_list = $this->moduleHandler->getModuleList();
    foreach (self::RELEVANT_MODULES as $module_name) {
      if (!isset($module_list[$module_name])) {
        continue;
      }
      $extension = $module_list[$module_name];
      $info = $extension->info ?? [];
      $modules[$module_name] = [
        'name' => (string) ($info['name'] ?? $module_name),
        'version' => (string) ($info['version'] ?? ''),
        'package' => (string) ($info['package'] ?? ''),
      ];
    }
    ksort($modules);
    return $modules;
  }

  /**
   * Collects one bundle and its field definitions.
   */
  protected function collectBundle(string $entity_type_id, string $bundle, array $info, array &$warnings): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $fields = [];

    try {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      foreach ($field_definitions as $field_name => $field_definition) {
        $fields[$field_name] = $this->collectField($field_name, $field_definition);
      }
      ksort($fields);
    }
    catch (Throwable $e) {
      $warnings[] = sprintf('Could not read field definitions for "%s.%s": %s', $entity_type_id, $bundle, $e->getMessage());
    }

    return [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'label' => $this->stringify($info['label'] ?? $bundle),
      'description' => $this->stringify($info['description'] ?? ''),
      'translatable' => $this->isBundleTranslatable($entity_type_id, $bundle, $definition->isTranslatable()),
      'revisionable' => $definition->isRevisionable(),
      'fields' => $fields,
    ];
  }

  /**
   * Collects one field definition.
   */
  protected function collectField(string $field_name, FieldDefinitionInterface $field_definition): array {
    $storage = $field_definition->getFieldStorageDefinition();
    $settings = $field_definition->getSettings();
    $storage_settings = $storage ? $storage->getSettings() : [];
    $type = $field_definition->getType();

    $field = [
      'name' => $field_name,
      'label' => $this->stringify($field_definition->getLabel()),
      'type' => $type,
      'required' => $field_definition->isRequired(),
      'translatable' => $field_definition->isTranslatable(),
      'cardinality' => $storage ? $storage->getCardinality() : FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => $this->normalize($settings),
    ];

    if (isset($settings['target_type']) || isset($storage_settings['target_type'])) {
      $field['target_type'] = (string) ($settings['target_type'] ?? $storage_settings['target_type']);
    }
    if (isset($settings['handler_settings'])) {
      $field['handler_settings'] = $this->normalize($settings['handler_settings']);
    }
    if (isset($settings['allowed_values']) || isset($storage_settings['allowed_values'])) {
      $field['allowed_values'] = $this->normalize($settings['allowed_values'] ?? $storage_settings['allowed_values']);
    }
    if (isset($settings['allowed_values_function']) || isset($storage_settings['allowed_values_function'])) {
      $field['allowed_values_function'] = (string) ($settings['allowed_values_function'] ?? $storage_settings['allowed_values_function']);
    }

    return $field;
  }

  /**
   * Summarizes paragraph bundles when Paragraphs is available.
   */
  protected function collectParagraphSummary(array $bundles, array &$warnings): array {
    if (!$this->moduleHandler->moduleExists('paragraphs') || !$this->entityTypeManager->getDefinition('paragraph', FALSE)) {
      $warnings[] = 'Paragraphs is not installed; paragraph bundle summary is unavailable.';
      return [
        'installed' => FALSE,
        'bundles' => [],
      ];
    }

    return [
      'installed' => TRUE,
      'bundles' => $this->summarizeBundles($bundles['paragraph'] ?? []),
    ];
  }

  /**
   * Summarizes media bundles when Media is available.
   */
  protected function collectMediaSummary(array $bundles, array &$warnings): array {
    if (!$this->moduleHandler->moduleExists('media') || !$this->entityTypeManager->getDefinition('media', FALSE)) {
      $warnings[] = 'Media is not installed; media bundle summary is unavailable.';
      return [
        'installed' => FALSE,
        'bundles' => [],
      ];
    }

    return [
      'installed' => TRUE,
      'bundles' => $this->summarizeBundles($bundles['media'] ?? []),
    ];
  }

  /**
   * Collects best-effort layout/blökkli information without assuming modules.
   */
  protected function collectLayoutOrBlokkliInfo(array &$warnings): array {
    $enabled_modules = [];
    foreach (['layout_builder', 'layout_discovery', 'field_layout', 'blokkli', 'paragraphs_blokkli'] as $module_name) {
      if ($this->moduleHandler->moduleExists($module_name)) {
        $enabled_modules[] = $module_name;
      }
    }

    $layout_builder_displays = [];
    foreach ($this->configFactory->listAll('core.entity_view_display.') as $config_name) {
      $config = $this->configFactory->get($config_name);
      $third_party = (array) ($config->get('third_party_settings.layout_builder') ?? []);
      if (!empty($third_party['enabled']) || !empty($third_party['sections'])) {
        $layout_builder_displays[] = [
          'config' => $config_name,
          'targetEntityType' => (string) ($config->get('targetEntityType') ?? ''),
          'bundle' => (string) ($config->get('bundle') ?? ''),
          'mode' => (string) ($config->get('mode') ?? ''),
          'layout_builder_enabled' => (bool) ($third_party['enabled'] ?? FALSE),
          'allow_custom' => (bool) ($third_party['allow_custom'] ?? FALSE),
        ];
      }
    }

    $blokkli_config_names = array_values(array_filter(
      $this->configFactory->listAll(),
      static fn(string $name): bool => str_contains($name, 'blokkli')
    ));

    if (!$enabled_modules && !$layout_builder_displays && !$blokkli_config_names) {
      $warnings[] = 'No layout-builder or blökkli-related modules/config were detected.';
    }

    return [
      'enabled_modules' => $enabled_modules,
      'layout_builder_displays' => $layout_builder_displays,
      'blokkli_config_names' => $blokkli_config_names,
    ];
  }

  /**
   * Builds a compact bundle summary.
   */
  protected function summarizeBundles(array $bundles): array {
    $summary = [];
    foreach ($bundles as $bundle => $bundle_info) {
      $summary[$bundle] = [
        'label' => $bundle_info['label'] ?? $bundle,
        'description' => $bundle_info['description'] ?? '',
        'translatable' => (bool) ($bundle_info['translatable'] ?? FALSE),
        'revisionable' => (bool) ($bundle_info['revisionable'] ?? FALSE),
        'field_count' => count($bundle_info['fields'] ?? []),
      ];
    }
    ksort($summary);
    return $summary;
  }

  /**
   * Returns whether content translation is enabled for the bundle.
   */
  protected function isBundleTranslatable(string $entity_type_id, string $bundle, bool $entity_type_translatable): bool {
    if (!$entity_type_translatable) {
      return FALSE;
    }

    $config = $this->configFactory->get("language.content_settings.$entity_type_id.$bundle");
    if ($config && !$config->isNew()) {
      return (bool) $config->get('third_party_settings.content_translation.enabled');
    }

    return $entity_type_translatable;
  }

  /**
   * Builds a base URL if Drush has enough request context to do so.
   */
  protected function getBaseUrl(): string {
    if (method_exists($this->requestContext, 'getCompleteBaseUrl')) {
      return (string) $this->requestContext->getCompleteBaseUrl();
    }

    $scheme = $this->requestContext->getScheme() ?: 'https';
    $host = $this->requestContext->getHost();
    if ($host === '' || $host === 'localhost') {
      return '';
    }

    return rtrim($scheme . '://' . $host . $this->requestContext->getBaseUrl(), '/');
  }

  /**
   * Converts Drupal labels/objects to plain strings.
   */
  protected function stringify(mixed $value): string {
    if ($value instanceof TranslatableMarkup || (is_object($value) && method_exists($value, '__toString'))) {
      return (string) $value;
    }
    if (is_scalar($value) || $value === NULL) {
      return (string) $value;
    }
    return '';
  }

  /**
   * Normalizes values so the artifact can be safely JSON encoded.
   */
  protected function normalize(mixed $value): mixed {
    if ($value instanceof TranslatableMarkup || (is_object($value) && method_exists($value, '__toString'))) {
      return (string) $value;
    }
    if (is_scalar($value) || $value === NULL) {
      return $value;
    }
    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalize($item);
      }
      return $normalized;
    }
    if ($value instanceof \JsonSerializable) {
      return $this->normalize($value->jsonSerialize());
    }

    return get_debug_type($value);
  }

}
