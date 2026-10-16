<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Controller;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\pendulum_arc\ArcConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\pendulum_arc\ArcApiException;
use Drupal\pendulum_arc\ConnectService;
use Drupal\pendulum_arc\ConnectionStoreInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dashboard and Connect screens.
 */
final class PageController extends ControllerBase {

  public function __construct(
    private readonly ConnectService $connect,
    private readonly ConnectionStoreInterface $store,
    private readonly ArcConfig $config,
    private readonly CsrfTokenGenerator $csrf,
    private readonly FileUrlGeneratorInterface $files,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('pendulum_arc.connect'),
      $container->get('pendulum_arc.store'),
      $container->get('pendulum_arc.config'),
      $container->get('csrf_token'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Score and report screen. Sends an unconnected admin to Connect.
   */
  public function dashboard(): array|RedirectResponse {
    if (!$this->sodiumReady()) {
      return $this->sodiumPage('Arc Analytics - Dashboard');
    }
    if (!$this->store->isConnected()) {
      return $this->redirect('pendulum_arc.connect');
    }
    $this->connect->ensureProjectSlug();
    return $this->page('dashboard', 'Arc Analytics - Dashboard');
  }

  /**
   * Connect screen. Completes an OAuth return before the page renders.
   */
  public function connect(Request $request): array|RedirectResponse {
    if (!$this->sodiumReady()) {
      return $this->sodiumPage('Arc Analytics - Connect');
    }
    $code = (string) $request->query->get('code', '');
    $state = (string) $request->query->get('state', '');
    $issuer = (string) $request->query->get('iss', '');
    $error = (string) $request->query->get('error', '');
    if ($code !== '' || $error !== '') {
      return $this->finishOauth($code, $state, $issuer, $error);
    }
    return $this->page('connect', 'Arc Analytics - Connect');
  }

  private function finishOauth(string $code, string $state, string $issuer, string $error): RedirectResponse {
    $userId = (int) $this->currentUser()->id();
    if ($error !== '' || $code === '' || $state === '') {
      $message = $error === 'access_denied'
        ? 'Arc sign-in was cancelled.'
        : 'Arc did not complete sign-in. Try again.';
      $this->store->putNotice($userId, 'error', $message);
      return $this->redirect('pendulum_arc.connect');
    }
    try {
      $this->connect->completeOauth($userId, $code, $state, $issuer);
    }
    catch (ArcApiException $failure) {
      $this->store->putNotice($userId, 'error', $failure->getMessage());
      return $this->redirect('pendulum_arc.connect');
    }
    return $this->redirect('pendulum_arc.dashboard');
  }

  /**
   * @return array<string, mixed>
   */
  private function page(string $page, string $title): array {
    $userId = (int) $this->currentUser()->id();
    $bootstrap = [
      'page' => $page,
      'notice' => $this->store->takeNotice($userId),
      'connection' => $this->store->publicView(),
      'pending' => $this->store->pendingPublic($userId),
      'urls' => [
        'dashboard' => Url::fromRoute('pendulum_arc.dashboard')->toString(),
        'connect' => Url::fromRoute('pendulum_arc.connect')->toString(),
        'portal' => $this->portalUrl(),
        'arcMark' => $this->assetUrl('arc-mark-color.svg'),
        'pendulumMark' => $this->assetUrl('pendulum-mark.svg'),
      ],
    ];
    return [
      '#theme' => 'pendulum_arc_page',
      '#title' => $title,
      '#mark' => $this->assetUrl('arc-mark-color.svg'),
      '#bootstrap' => (string) json_encode($bootstrap),
      '#message' => '',
      '#attached' => $this->attachments(),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function sodiumPage(string $title): array {
    return [
      '#theme' => 'pendulum_arc_page',
      '#title' => $title,
      '#mark' => $this->assetUrl('arc-mark-color.svg'),
      '#bootstrap' => '{}',
      '#message' => (string) $this->t('Arc Analytics needs the PHP sodium extension before it can store a connection.'),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function attachments(): array {
    return [
      'library' => [
        'pendulum_arc/admin',
      ],
      'drupalSettings' => [
        'pendulumArc' => [
          'csrfToken' => $this->csrf->get('rest'),
        ],
      ],
    ];
  }

  private function portalUrl(): string {
    $view = $this->store->publicView();
    if (!empty($view['portalUrl'])) {
      return (string) $view['portalUrl'];
    }
    return $this->config->portal($this->config->env());
  }

  private function assetUrl(string $file): string {
    $path = $this->moduleHandler()->getModule('pendulum_arc')->getPath() . '/assets/' . $file;
    return $this->files->generateString($path);
  }

  private function sodiumReady(): bool {
    return function_exists('sodium_crypto_secretbox');
  }

}
