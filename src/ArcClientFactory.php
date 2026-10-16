<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the Arc HTTP client with this request's origin.
 */
final class ArcClientFactory {

  public static function create(ClientInterface $http, ArcConfig $config, RequestStack $requests): ArcClient {
    $request = $requests->getCurrentRequest();
    $origin = $request ? $request->getSchemeAndHttpHost() : 'drupal';
    return new ArcClient($http, $config, ArcConfig::VERSION, $origin);
  }

}
