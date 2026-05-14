<?php

namespace Drupal\icms_migration_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Imports media + files described by media-assets.json.
 *
 * The expected format is `icms-source-media-assets-v1`:
 *
 * @code
 * {
 *   "format": "icms-source-media-assets-v1",
 *   "filesBaseDir": "media/files",      // relative to the manifest file
 *   "assets": [
 *     {
 *       "sourceMediaId": 620,
 *       "uuid": "...",
 *       "bundle": "image|svg|...",
 *       "label": "...",
 *       "langcode": "de",
 *       "files": [
 *         {"fieldName": "field_media_image", "uuid": "...", "filename": "...",
 *          "uri": "public://...", "relativePath": "media/files/..."}
 *       ]
 *     }
 *   ]
 * }
 * @endcode
 */
class MediaImporter {

  protected LoggerChannelInterface $logger;

  /**
   * Index of imported media keyed by sourceMediaId.
   *
   * @var array<int, int>
   */
  protected array $mediaIdMap = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('icms_migration_import');
  }

  /**
   * Import every asset listed in the manifest.
   *
   * @param string|null $manifestPath
   *   Absolute container path to media-assets.json. If NULL, no-op.
   * @param bool $dryRun
   *   If TRUE, only report what would be imported.
   *
   * @return array{imported:int, skipped:int, errors:array<int,string>}
   */
  public function importAll(?string $manifestPath, bool $dryRun = FALSE): array {
    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => []];
    if ($manifestPath === NULL || $manifestPath === '') {
      return $stats;
    }
    if (!is_file($manifestPath)) {
      $stats['errors'][] = "Media manifest not found: $manifestPath";
      return $stats;
    }
    $manifest = json_decode(file_get_contents($manifestPath), TRUE);
    if (!is_array($manifest) || empty($manifest['assets'])) {
      return $stats;
    }
    $baseDir = dirname($manifestPath);
    $filesBase = (string) ($manifest['filesBaseDir'] ?? 'files');

    $mediaStorage = $this->entityTypeManager->getStorage('media');

    foreach ($manifest['assets'] as $asset) {
      $sourceId = (int) ($asset['sourceMediaId'] ?? 0);
      $uuid = (string) ($asset['uuid'] ?? '');
      $bundle = (string) ($asset['bundle'] ?? '');
      if ($uuid === '' || $bundle === '') {
        $stats['skipped']++;
        continue;
      }
      // Skip if already present (idempotent).
      $existing = $mediaStorage->loadByProperties(['uuid' => $uuid]);
      if ($existing) {
        $media = reset($existing);
        $this->mediaIdMap[$sourceId] = (int) $media->id();
        $stats['skipped']++;
        continue;
      }
      if ($dryRun) {
        $stats['imported']++;
        continue;
      }
      try {
        $values = [
          'uuid' => $uuid,
          'bundle' => $bundle,
          'name' => $asset['label'] ?? '',
          'langcode' => $asset['langcode'] ?? 'en',
        ];
        // Copy referenced files first.
        foreach ($asset['files'] ?? [] as $fileInfo) {
          $fid = $this->ensureFile($fileInfo, $baseDir, $filesBase);
          if ($fid && !empty($fileInfo['fieldName'])) {
            $values[$fileInfo['fieldName']] = ['target_id' => $fid];
          }
        }
        $media = $mediaStorage->create($values);
        $media->save();
        $this->mediaIdMap[$sourceId] = (int) $media->id();
        $stats['imported']++;
      }
      catch (\Throwable $e) {
        $stats['errors'][] = "media $sourceId ($bundle): " . $e->getMessage();
        $this->logger->warning("Failed to import media @id (@bundle): @msg", [
          '@id' => $sourceId,
          '@bundle' => $bundle,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return $stats;
  }

  /**
   * Map a source media id to the imported media entity id, if known.
   */
  public function getImportedMid(int $sourceMediaId): ?int {
    return $this->mediaIdMap[$sourceMediaId] ?? NULL;
  }

  /**
   * Copy a file from disk into Drupal's file system and return its fid.
   */
  protected function ensureFile(array $fileInfo, string $baseDir, string $filesBase): ?int {
    $relative = (string) ($fileInfo['relativePath'] ?? '');
    $uri = (string) ($fileInfo['uri'] ?? '');
    if ($relative === '' || $uri === '') {
      return NULL;
    }
    // Skip if a file with this URI already exists.
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $existing = $fileStorage->loadByProperties(['uri' => $uri]);
    if ($existing) {
      $file = reset($existing);
      return (int) $file->id();
    }
    $sourcePath = $baseDir . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($sourcePath)) {
      throw new \RuntimeException("file source missing: $sourcePath");
    }
    // Ensure destination directory.
    $directory = dirname($uri);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $contents = file_get_contents($sourcePath);
    $file = $this->fileRepository->writeData($contents, $uri, FileSystemInterface::EXISTS_REPLACE);
    return (int) $file->id();
  }

}
