<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Builds the connection store with the site hash salt.
 */
final class ConnectionStoreFactory {

  public static function create(StateInterface $state, KeyValueExpirableFactoryInterface $expirable): ConnectionStore {
    return new ConnectionStore($state, $expirable, Settings::getHashSalt());
  }

}
