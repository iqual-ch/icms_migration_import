<?php

namespace Drupal\icms_migration_import\Commands;

use Drupal\icms_migration_import\Service\Importer;
use Drupal\icms_migration_import\Service\MediaImporter;
use Drupal\icms_migration_import\Service\PlanReader;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ICMS Migration Import module.
 */
class IcmsMigrationImportCommands extends DrushCommands {

  public function __construct(
    protected PlanReader $planReader,
    protected Importer $importer,
    protected MediaImporter $mediaImporter,
  ) {}

  /**
   * Import an ICMS migration plan into this site.
   *
   * @param string $planFile
   *   Absolute container path to icms-import-plan.json.
   * @param array $options
   *   Command options.
   *
   * @command icms-migration:import
   * @aliases icms-mig-import
   * @option media-assets
   *   Absolute container path to media-assets.json (optional).
   * @option blocked
   *   How to handle BLOCKED components: "placeholder" (default) or "skip".
   * @option nid
   *   Comma-separated source NIDs to restrict the import to.
   * @option dry-run
   *   Validate only, do not write any entity.
   * @option include-review
   *   Also import pages whose status is REVIEW or BLOCKED.
   * @option title-prefix
   *   Prepend this prefix to every imported page title.
   * @option alias-prefix
   *   Prepend this prefix to every imported path alias.
   *
   * @usage drush icms-migration:import /var/www/html/tmp/icms-migration-import/run-plan.json --media-assets=/var/www/html/tmp/icms-migration-import/media/media-assets.json
   * @usage drush icms-migration:import plan.json --dry-run
   */
  public function import(
    string $planFile,
    array $options = [
      'media-assets' => NULL,
      'blocked' => 'placeholder',
      'nid' => NULL,
      'dry-run' => FALSE,
      'include-review' => FALSE,
      'title-prefix' => NULL,
      'alias-prefix' => NULL,
    ],
  ): void {
    $this->output()->writeln(sprintf('Loading plan: %s', $planFile));
    $plan = $this->planReader->load($planFile);
    $plan = $this->planReader->filterByNids($plan, $options['nid'] ?? NULL);

    $totalPages = count($plan['pages']);
    $this->output()->writeln(sprintf('Plan loaded: %d page(s)%s', $totalPages, !empty($options['dry-run']) ? '  [DRY-RUN]' : ''));

    // Media first so that paragraph fields can reference imported mids.
    $mediaStats = $this->mediaImporter->importAll($options['media-assets'] ?? NULL, !empty($options['dry-run']));
    $this->output()->writeln(sprintf(
      'Media: imported=%d skipped=%d errors=%d',
      $mediaStats['imported'],
      $mediaStats['skipped'],
      count($mediaStats['errors'])
    ));
    foreach ($mediaStats['errors'] as $err) {
      $this->logger()->warning("media: $err");
    }

    $stats = $this->importer->importPlan($plan, [
      'blocked' => (string) ($options['blocked'] ?? 'placeholder'),
      'include-review' => (bool) ($options['include-review'] ?? FALSE),
      'dry-run' => (bool) ($options['dry-run'] ?? FALSE),
      'title-prefix' => $options['title-prefix'] ?? NULL,
      'alias-prefix' => $options['alias-prefix'] ?? NULL,
    ]);

    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      'Import summary: pages=%d paragraphs=%d skipped=%d blocked=%d errors=%d',
      $stats['pages'],
      $stats['paragraphs'],
      $stats['skipped'],
      $stats['blocked'],
      count($stats['errors'])
    ));
    foreach ($stats['errors'] as $err) {
      $this->logger()->error($err);
    }
  }

}
