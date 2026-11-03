<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Accessibility screenshots on the private file system.
 */
final class DrupalReportShots implements ReportShotStoreInterface {

  private const TYPES = [
    'image/webp' => 'webp',
    'image/png' => 'png',
    'image/jpeg' => 'jpeg',
    'image/gif' => 'gif',
  ];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function read(string $runId, string $file): ?array {
    $uri = $this->uri($runId, $file);
    $type = self::typeFor($file);
    if ($uri === NULL || $type === NULL || !is_file($uri)) {
      return NULL;
    }
    $bytes = file_get_contents($uri);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 2000000) {
      return NULL;
    }
    return [
      'bytes' => $bytes,
      'type' => $type,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $runId, string $file, string $bytes, string $type): void {
    $uri = $this->uri($runId, $file);
    if ($uri === NULL || !isset(self::TYPES[$type]) || $bytes === '' || strlen($bytes) > 2000000) {
      throw new ArcApiException('That screenshot is not available.', 502);
    }
    $directory = dirname($uri);
    $ready = $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$ready) {
      throw new ArcApiException('The screenshots could not be stored.', 500);
    }
    $saved = $this->fileSystem->saveData($bytes, $uri, FileExists::Replace);
    if (!is_string($saved)) {
      throw new ArcApiException('The screenshots could not be stored.', 500);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function markMissing(string $runId, string $file): void {
    $uri = $this->uri($runId, $file);
    if ($uri === NULL) {
      return;
    }
    $marker = $uri . '.missing';
    $directory = dirname($marker);
    $ready = $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$ready) {
      throw new ArcApiException('The screenshots could not be stored.', 500);
    }
    $saved = $this->fileSystem->saveData('1', $marker, FileExists::Replace);
    if (!is_string($saved)) {
      throw new ArcApiException('The screenshots could not be stored.', 500);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $runId, string $file): bool {
    $uri = $this->uri($runId, $file);
    if ($uri === NULL) {
      return FALSE;
    }
    if (is_file($uri) && (int) filesize($uri) > 0) {
      return TRUE;
    }
    return is_file($uri . '.missing');
  }

  private function uri(string $runId, string $file): ?string {
    if (1 !== preg_match('/^[A-Za-z0-9_-]{1,128}$/', $runId)) {
      return NULL;
    }
    if (str_contains($file, '..') || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $file)) {
      return NULL;
    }
    return 'private://pendulum-arc/reports/' . $runId . '/' . $file;
  }

  private static function typeFor(string $file): ?string {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    foreach (self::TYPES as $type => $name) {
      if ($name === $extension || ($type === 'image/jpeg' && $extension === 'jpg')) {
        return $type;
      }
    }
    return NULL;
  }

}
