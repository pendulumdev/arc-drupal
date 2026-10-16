<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Site name and absolute front URL for the authorize request.
 */
final class DrupalSiteProfile implements SiteProfileInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requests,
  ) {}

  public function get(): array {
    $origin = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $name = trim((string) $this->configFactory->get('system.site')->get('name'));
    if (strlen($name) < 2) {
      $request = $this->requests->getCurrentRequest();
      $host = $request ? $request->getHost() : '';
      $name = $host !== '' ? $host : 'Drupal';
    }
    return [
      'origin' => $origin,
      'name' => $name,
    ];
  }

}
