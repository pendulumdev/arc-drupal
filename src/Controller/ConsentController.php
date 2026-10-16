<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\pendulum_arc\ArcConfig;
use Drupal\pendulum_arc\ConnectionStoreInterface;
use Drupal\pendulum_arc\ConsentLog;
use Drupal\pendulum_arc\ConsentStore;
use Drupal\pendulum_arc\Rules\ConsentLogPayload;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GDPR Consent screen, its JSON routes, and the public log route.
 */
final class ConsentController extends ControllerBase {

  public function __construct(
    private readonly ConsentStore $consent,
    private readonly ConsentLog $log,
    private readonly ConnectionStoreInterface $store,
    private readonly ArcConfig $config,
    private readonly CsrfTokenGenerator $csrf,
    private readonly FileUrlGeneratorInterface $files,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('pendulum_arc.consent_store'),
      $container->get('pendulum_arc.consent_log'),
      $container->get('pendulum_arc.store'),
      $container->get('pendulum_arc.config'),
      $container->get('csrf_token'),
      $container->get('file_url_generator'),
      $container->get('file_system'),
    );
  }

  /**
   * Consent screen. Open before the site is paired.
   *
   * @return array<string, mixed>
   */
  public function page(Request $request): array {
    $this->consent->detect(ConsentStore::cookieNames($request), []);
    $userId = (int) $this->currentUser()->id();
    $bootstrap = [
      'page' => 'consent',
      'notice' => $this->store->takeNotice($userId),
      'connection' => $this->store->publicView(),
      'pending' => $this->store->pendingPublic($userId),
      'consent' => $this->consent->view(),
      'logs' => $this->log->latest(),
      'cookieIcon' => $this->assetUrl('cookie-icon.svg'),
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
      '#title' => 'Arc Analytics - GDPR Consent',
      '#mark' => $this->assetUrl('arc-mark-color.svg'),
      '#bootstrap' => (string) json_encode($bootstrap),
      '#message' => '',
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
   * Saves settings and keeps the consent version.
   */
  public function save(Request $request): JsonResponse {
    return new JsonResponse([
      'settings' => $this->consent->save($this->payload($request)),
    ]);
  }

  /**
   * Asks every visitor to choose again.
   */
  public function renew(): JsonResponse {
    return new JsonResponse([
      'settings' => $this->consent->renew(),
    ]);
  }

  /**
   * Restores factory settings and asks again.
   */
  public function reset(): JsonResponse {
    return new JsonResponse([
      'settings' => $this->consent->reset(),
    ]);
  }

  /**
   * Restores the stylesheet only.
   */
  public function resetStyles(): JsonResponse {
    return new JsonResponse([
      'settings' => $this->consent->resetStyles(),
    ]);
  }

  /**
   * Stores a public image and returns its URL. The settings save keeps it.
   */
  public function uploadIcon(Request $request): JsonResponse {
    $upload = $request->files->get('icon');
    if (!$upload instanceof UploadedFile || !$upload->isValid()) {
      return new JsonResponse(['message' => 'Choose an image.'], 400);
    }
    $mime = (string) $upload->getMimeType();
    $allowed = [
      'image/png' => 'png',
      'image/jpeg' => 'jpg',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
      return new JsonResponse(['message' => 'Choose a PNG, JPEG, GIF, WebP, or SVG image.'], 400);
    }
    if ((int) $upload->getSize() > 512 * 1024) {
      return new JsonResponse(['message' => 'The icon must be 512 KB or smaller.'], 400);
    }
    $directory = 'public://pendulum-arc';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $data = file_get_contents($upload->getPathname());
    if (!is_string($data) || $data === '') {
      return new JsonResponse(['message' => 'Choose an image.'], 400);
    }
    $destination = $directory . '/cookie-icon-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $uri = $this->fileSystem->saveData($data, $destination, FileExists::Rename);
    if (!is_string($uri)) {
      return new JsonResponse(['message' => 'The icon could not be saved.'], 500);
    }
    return new JsonResponse([
      'url' => $this->files->generateAbsoluteString($uri),
    ]);
  }

  /**
   * Records one visitor choice. A cached page cannot carry a fresh token,
   * so this route checks the payload and the hourly limit instead.
   */
  public function log(Request $request): JsonResponse {
    $settings = $this->consent->settings();
    if (empty($settings['logging_enabled'])) {
      return new JsonResponse(NULL, 204);
    }
    $ip = self::remoteAddr($request);
    if ($this->log->limited($ip)) {
      return new JsonResponse(NULL, 429);
    }
    $body = json_decode($request->getContent(), TRUE);
    $action = is_array($body) && isset($body['action']) ? (string) $body['action'] : '';
    $allowed = is_array($body) && isset($body['allowed']) && is_array($body['allowed']) ? $body['allowed'] : [];
    $ids = [];
    foreach ($allowed as $id) {
      $ids[] = (string) $id;
    }
    $cookies = isset($settings['cookies']) && is_array($settings['cookies']) ? $settings['cookies'] : [];
    $payload = ConsentLogPayload::build(
      TRUE,
      !empty($settings['disable_identifiable']),
      $action,
      $ids,
      $cookies,
      $ip,
      self::userAgent($request)
    );
    if ($payload === NULL) {
      return new JsonResponse(['message' => 'Consent could not be logged.'], 400);
    }
    $this->log->insert($payload, (int) $settings['consent_version']);
    return new JsonResponse(NULL, 204);
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   *
   * @return array<string, mixed>
   */
  private function payload(Request $request): array {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return [];
    }
    if (isset($body['settings']) && is_array($body['settings'])) {
      return $body['settings'];
    }
    return $body;
  }

  /**
   * Direct remote address. Forwarded headers are not trusted.
   */
  private static function remoteAddr(Request $request): string {
    $ip = (string) $request->server->get('REMOTE_ADDR', '');
    return filter_var($ip, FILTER_VALIDATE_IP) === FALSE ? '' : $ip;
  }

  /**
   * Browser user agent. The payload clips it.
   */
  private static function userAgent(Request $request): string {
    return (string) $request->headers->get('User-Agent', '');
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

}
