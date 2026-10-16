<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Plugin\Block;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\pendulum_arc\ConnectionStoreInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scores-only block for the core Dashboard or any region.
 */
#[Block(
  id: 'pendulum_arc_scores',
  admin_label: new TranslatableMarkup('Arc Analytics'),
  category: new TranslatableMarkup('Arc Analytics'),
)]
final class ScoresBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConnectionStoreInterface $store,
    private readonly CsrfTokenGenerator $csrf,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('pendulum_arc.store'),
      $container->get('csrf_token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $connected = $this->store->isConnected();
    return [
      '#theme' => 'pendulum_arc_widget',
      '#dashboard_url' => Url::fromRoute('pendulum_arc.dashboard')->toString(),
      '#connect_url' => Url::fromRoute('pendulum_arc.connect')->toString(),
      '#portal_url' => (string) ($this->store->publicView()['portalUrl'] ?? ''),
      '#connected' => $connected ? '1' : '0',
      '#attached' => [
        'library' => [
          'pendulum_arc/admin',
        ],
        'drupalSettings' => [
          'pendulumArc' => [
            'csrfToken' => $this->csrf->get('rest'),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'administer pendulum arc');
  }

}
