<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\pendulum_arc\Rules\Pairing;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves an on-site path to a view URL and an edit URL.
 */
final class DrupalContentLinks implements ContentLinkerInterface {

  public function __construct(
    private readonly PathValidatorInterface $paths,
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requests,
  ) {}

  public function linkReportBlocks(array $blocks): array {
    $homeHost = $this->homeHost();
    foreach ($blocks as &$block) {
      if (!is_array($block) || ($block['kind'] ?? '') !== 'table' || !is_array($block['rows'] ?? NULL)) {
        continue;
      }
      foreach ($block['rows'] as &$row) {
        if (!is_array($row) || !is_array($row['cells'] ?? NULL)) {
          continue;
        }
        foreach ($row['cells'] as &$cell) {
          if (is_array($cell)) {
            $cell = $this->linkCell($cell, $homeHost);
          }
        }
      }
    }
    return $blocks;
  }

  public function landingPages(array $pages): array {
    $out = [];
    foreach ($pages as $page) {
      if (!is_array($page)) {
        continue;
      }
      $path = (string) ($page['path'] ?? '/');
      $linked = $this->urlsForPath($path);
      $page['viewUrl'] = $linked['viewUrl'];
      $page['editUrl'] = $linked['editUrl'];
      $out[] = $page;
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $cell
   *
   * @return array<string, mixed>
   */
  private function linkCell(array $cell, string $homeHost): array {
    $href = (string) ($cell['href'] ?? '');
    if ($href === '' || $homeHost === '') {
      return $cell;
    }
    $parts = parse_url($href);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if ($host !== $homeHost) {
      return $cell;
    }
    $path = Pairing::landing_path($href);
    $linked = $this->urlsForPath($path);
    if (is_string($linked['editUrl']) && $linked['editUrl'] !== '') {
      $cell['editHref'] = $linked['editUrl'];
    }
    return $cell;
  }

  /**
   * @return array{viewUrl: string, editUrl: string|null}
   */
  private function urlsForPath(string $path): array {
    $path = '/' . ltrim($path, '/');
    try {
      $view = Url::fromUserInput($path)->setAbsolute()->toString();
    }
    catch (\InvalidArgumentException) {
      $view = $path;
    }
    $edit = $this->editUrl($path);
    return [
      'viewUrl' => $view,
      'editUrl' => $edit,
    ];
  }

  private function editUrl(string $path): ?string {
    $internal = $path;
    if ($path === '/') {
      $front = (string) $this->configFactory->get('system.site')->get('page.front');
      if ($front !== '' && str_starts_with($front, '/')) {
        $internal = $front;
      }
    }
    $url = $this->paths->getUrlIfValid($internal);
    if (!$url instanceof Url) {
      return NULL;
    }
    try {
      $params = $url->getRouteParameters();
    }
    catch (\Exception) {
      return NULL;
    }
    foreach ($params as $entityType => $id) {
      if (!is_string($entityType) || !$this->entities->hasDefinition($entityType)) {
        continue;
      }
      $storage = $this->entities->getStorage($entityType);
      $entity = $storage->load((string) $id);
      if ($entity === NULL || !method_exists($entity, 'access') || !method_exists($entity, 'toUrl')) {
        continue;
      }
      if (!$entity->access('update', $this->currentUser)) {
        return NULL;
      }
      return $entity->toUrl('edit-form')->setAbsolute()->toString();
    }
    return NULL;
  }

  private function homeHost(): string {
    $request = $this->requests->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    return strtolower($request->getHost());
  }

}
