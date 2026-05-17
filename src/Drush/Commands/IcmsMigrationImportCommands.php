<?php

namespace Drupal\icms_migration_import\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\Core\State\StateInterface;
use Drupal\icms_migration_import\Plugin\migrate\source\IcmsPlanBase;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * Drush wrapper around the `icms_migration` migrate group.
 *
 * Responsibilities:
 *  - Validate the plan path passed by the user.
 *  - Store it in State (`icms_migration_import.plan_path`) so the
 *    icms_plan_* source plugins can pick it up.
 *  - Delegate the actual import to Drupal Migrate via
 *    `drush migrate:import --group=icms_migration`.
 *
 * Once the plan path is set in State, you can also call the underlying
 * Migrate commands directly:
 *
 *   drush migrate:status   --group=icms_migration
 *   drush migrate:import   --group=icms_migration --update
 *   drush migrate:rollback --group=icms_migration
 *   drush migrate:messages icms_pages
 */
class IcmsMigrationImportCommands extends DrushCommands {

  use AutowireTrait;

  public const STATE_KEY = 'icms_migration_import.plan_path';
  public const GROUP = 'icms_migration';

  public function __construct(
    protected StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * Import an ICMS migration plan via the Migrate API.
   */
  #[CLI\Command(name: 'icms-migration:run', aliases: ['icms-mig-run'])]
  #[CLI\Argument(name: 'planFile', description: 'Absolute container path to icms-import-plan.json.')]
  #[CLI\Option(name: 'update', description: 'Re-import rows whose source has changed.')]
  #[CLI\Option(name: 'rollback-first', description: 'Run `migrate:rollback --group` before importing.')]
  #[CLI\Option(name: 'limit', description: 'Limit each migration to N rows (useful for smoke tests).')]
  #[CLI\Option(name: 'sync', description: 'Pass --sync to migrate:import (removes rows missing from source).')]
  #[CLI\Option(name: 'migrations', description: 'Comma-separated subset of migration ids to run (default: full group).')]
  #[CLI\Usage(name: 'drush icms-migration:run /var/www/html/tmp/plan.json', description: 'Run a fresh import.')]
  #[CLI\Usage(name: 'drush icms-migration:run plan.json --update', description: 'Re-import only changed rows.')]
  #[CLI\Usage(name: 'drush icms-migration:run plan.json --rollback-first', description: 'Clean slate, then re-import.')]
  public function run(
    string $planFile,
    array $options = [
      'update' => FALSE,
      'rollback-first' => FALSE,
      'limit' => 0,
      'sync' => FALSE,
      'migrations' => '',
    ],
  ): CommandResult {
    $resolved = $this->resolvePlan($planFile);
    if ($resolved === NULL) {
      return CommandResult::exitCode(2);
    }
    $this->state->set(self::STATE_KEY, $resolved);
    IcmsPlanBase::resetPlanCache();
    $this->io()->writeln(sprintf('Plan path stored in state: %s', $resolved));

    if (!empty($options['rollback-first'])) {
      $this->io()->section('Rollback existing data');
      $code = $this->invokeMigrate('migrate:rollback', $options);
      if ($code !== 0) {
        $this->logger()->warning("migrate:rollback exited with code $code (continuing).");
      }
    }

    $this->io()->section('Import');
    $code = $this->invokeMigrate('migrate:import', $options);
    if ($code !== 0) {
      $this->logger()->error("migrate:import exited with code $code");
      return CommandResult::exitCode($code);
    }

    $this->io()->success('ICMS import finished. Run `drush icms-migration:status` for details.');
    return CommandResult::exitCode(0);
  }

  /**
   * Shortcut for `drush migrate:status --group=icms_migration`.
   */
  #[CLI\Command(name: 'icms-migration:status', aliases: ['icms-mig-status'])]
  public function status(): int {
    return $this->invokeMigrate('migrate:status', []);
  }

  /**
   * Shortcut for `drush migrate:rollback --group=icms_migration`.
   */
  #[CLI\Command(name: 'icms-migration:rollback', aliases: ['icms-mig-rollback'])]
  #[CLI\Option(name: 'migrations', description: 'Comma-separated subset of migration ids to roll back.')]
  public function rollback(array $options = ['migrations' => '']): int {
    return $this->invokeMigrate('migrate:rollback', $options);
  }

  /**
   * Validate the plan file and return its absolute path.
   */
  protected function resolvePlan(string $planFile): ?string {
    if ($planFile === '') {
      $this->logger()->error('Plan file is required.');
      return NULL;
    }
    if (!is_file($planFile) || !is_readable($planFile)) {
      $this->logger()->error("Plan file not found or unreadable: $planFile");
      return NULL;
    }
    $data = json_decode((string) file_get_contents($planFile), TRUE);
    if (!is_array($data) || !isset($data['format'])) {
      $this->logger()->error("Plan is not valid JSON or missing 'format': $planFile");
      return NULL;
    }
    if (strpos((string) $data['format'], 'icms-import-plan') !== 0) {
      $this->logger()->error("Plan format unrecognized: '{$data['format']}'");
      return NULL;
    }
    $gate = (string) ($data['gate']['status'] ?? 'ALLOWED');
    if (strtoupper($gate) !== 'ALLOWED') {
      $this->io()->warning("Plan gate status is '$gate'. Blocked pages/components will be skipped by the source plugins.");
    }
    return realpath($planFile) ?: $planFile;
  }

  /**
   * Invoke a Drush migrate command on the icms_migration group.
   */
  protected function invokeMigrate(string $command, array $options): int {
    $args = [];
    $opts = ['group' => self::GROUP];
    if (!empty($options['migrations'])) {
      // `migrate:*` accept a positional migration list too; prefer that
      // so callers can target a subset of the group.
      $args[] = (string) $options['migrations'];
      unset($opts['group']);
    }
    if (!empty($options['update'])) {
      $opts['update'] = TRUE;
    }
    if (!empty($options['sync'])) {
      $opts['sync'] = TRUE;
    }
    if (!empty($options['limit'])) {
      $opts['limit'] = (int) $options['limit'];
    }
    $process = Drush::drush(Drush::aliasManager()->getSelf(), $command, $args, $opts);
    // Always run without TTY: the wrapper is typically invoked from CI or
    // from `ddev drush ...` where /dev/tty is not available even though
    // Drush's own input may report itself as interactive.
    $process->setTty(FALSE);
    $process->mustRun(function (string $type, string $buffer): void {
      $this->output()->write($buffer);
    });
    return $process->getExitCode() ?? 0;
  }

}
