<?php

namespace Drupal\icms_migration_import\TargetStructure;

use Drupal\Core\File\FileSystemInterface;
use RuntimeException;

/**
 * Writes target-structure artifacts as pretty JSON.
 */
class TargetStructureJsonWriter {

  public function __construct(
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Writes the artifact and returns the destination path/URI.
   *
   * @throws \JsonException
   *   Thrown when JSON encoding fails.
   * @throws \RuntimeException
   *   Thrown when the directory or destination cannot be written.
   */
  public function write(array $artifact, string $destination): string {
    $destination = trim($destination);
    if ($destination === '') {
      throw new RuntimeException('Output destination is required.');
    }

    $directory = $this->fileSystem->dirname($destination);
    if ($directory === '' || $directory === '.') {
      $directory = getcwd() ?: '.';
      $destination = $directory . DIRECTORY_SEPARATOR . $destination;
    }

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException(sprintf('Could not create or write to output directory: %s', $directory));
    }

    $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $json .= PHP_EOL;

    $bytes = @file_put_contents($destination, $json, LOCK_EX);
    if ($bytes === FALSE) {
      $error = error_get_last();
      throw new RuntimeException(sprintf('Could not write target structure to %s%s', $destination, isset($error['message']) ? ': ' . $error['message'] : '.'));
    }

    return $destination;
  }

}
