<?php

namespace Drupal\icms_migration_import\Plugin\migrate\process;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Downloads a remote file over HTTP(S) into Drupal's file system.
 *
 * Usage:
 * @code
 * uri:
 *   plugin: icms_file_download
 *   source: sourceUrl
 *   destination_prefix: 'public://icms-imported/'
 *   skip_on_missing_filename: TRUE
 * @endcode
 *
 * The destination filename is derived from the URL path (basename). A
 * directory bucket based on a short hash of the URL is inserted so two
 * identical filenames from different URLs do not collide:
 *
 *   public://icms-imported/<8-hex-of-sha256(url)>/<filename>
 *
 * If a file already exists at the destination, it is replaced unless the
 * `file_exists` configuration is overridden.
 *
 * @MigrateProcessPlugin(
 *   id = "icms_file_download"
 * )
 */
class IcmsFileDownload extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
  ) {
    parent::__construct(
      $configuration + [
        'destination_prefix' => 'public://icms-imported/',
        'file_exists' => 'replace',
        'skip_on_missing_filename' => TRUE,
        'timeout' => 60,
      ],
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $url = is_string($value) ? trim($value) : '';
    if ($url === '') {
      throw new MigrateException('icms_file_download: empty source URL.');
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $filename = basename(rawurldecode($path));
    if ($filename === '' || $filename === '/' || $filename === '.') {
      if ($this->configuration['skip_on_missing_filename']) {
        throw new MigrateException("icms_file_download: cannot derive filename from URL: $url");
      }
      $filename = substr(hash('sha256', $url), 0, 16);
    }

    $bucket = substr(hash('sha256', $url), 0, 8);
    $prefix = rtrim((string) $this->configuration['destination_prefix'], '/');
    $directory = sprintf('%s/%s', $prefix, $bucket);
    $destination = sprintf('%s/%s', $directory, $filename);

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new MigrateException("icms_file_download: cannot prepare directory: $directory");
    }

    $realPath = $this->fileSystem->realpath($destination);
    if ($realPath === FALSE) {
      // Use the URI itself if no realpath (e.g. non-local stream wrapper).
      $realPath = $destination;
    }

    try {
      $this->httpClient->request('GET', $url, [
        'sink' => $realPath,
        'timeout' => (int) $this->configuration['timeout'],
        'allow_redirects' => TRUE,
        'http_errors' => TRUE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new MigrateException(sprintf('icms_file_download: GET %s failed: %s', $url, $e->getMessage()));
    }

    return $destination;
  }

}
