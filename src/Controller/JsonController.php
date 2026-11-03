<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\pendulum_arc\ArcApiException;
use Drupal\pendulum_arc\ConnectService;
use Drupal\pendulum_arc\GoogleHandoff;
use Drupal\pendulum_arc\InviteService;
use Drupal\pendulum_arc\KeyphraseSync;
use Drupal\pendulum_arc\Rules\DraftException;
use Drupal\pendulum_arc\Rules\FieldMessages;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON routes for the admin screens.
 */
final class JsonController extends ControllerBase {

  public function __construct(
    private readonly ConnectService $connect,
    private readonly GoogleHandoff $google,
    private readonly InviteService $invites,
    private readonly KeyphraseSync $keyphrases,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('pendulum_arc.connect'),
      $container->get('pendulum_arc.google_handoff'),
      $container->get('pendulum_arc.invites'),
      $container->get('pendulum_arc.keyphrases'),
    );
  }

  public function apiKey(Request $request): JsonResponse {
    $body = $this->body($request);
    return $this->respond(function () use ($body) {
      return $this->connect->beginApiKey((string) ($body['apiKey'] ?? ''), (int) $this->currentUser()->id());
    });
  }

  public function reconnect(): JsonResponse {
    return $this->respond(function () {
      return $this->connect->reconnect((int) $this->currentUser()->id());
    });
  }

  public function oauthStart(): JsonResponse {
    $redirect = Url::fromRoute('pendulum_arc.connect', [], ['absolute' => TRUE])->toString();
    return $this->respond(function () use ($redirect) {
      return $this->connect->oauthStart((int) $this->currentUser()->id(), $redirect);
    });
  }

  public function pair(Request $request): JsonResponse {
    $body = $this->body($request);
    return $this->respond(function () use ($body) {
      return $this->connect->pairMatch((int) $this->currentUser()->id(), (string) ($body['siteId'] ?? ''));
    });
  }

  public function createSite(Request $request): JsonResponse {
    $body = $this->body($request);
    try {
      $result = $this->connect->createSite(
        (int) $this->currentUser()->id(),
        (string) ($body['name'] ?? ''),
        (string) ($body['baseUrl'] ?? ''),
        (string) ($body['projectId'] ?? ''),
      );
      return new JsonResponse($result);
    }
    catch (DraftException $error) {
      return new JsonResponse([
        'message' => 'Check the site details.',
        'fields' => FieldMessages::map($error->fields()),
      ], 400);
    }
    catch (ArcApiException $error) {
      return $this->apiError($error);
    }
  }

  public function disconnect(): JsonResponse {
    $this->connect->disconnect((int) $this->currentUser()->id());
    return new JsonResponse(['connected' => FALSE]);
  }

  public function scores(): JsonResponse {
    return new JsonResponse($this->connect->scores());
  }

  public function analytics(): JsonResponse {
    return new JsonResponse($this->connect->analytics());
  }

  public function report(string $service): JsonResponse {
    $view = $this->connect->report($service);
    $runId = (string) ($view['runId'] ?? '');
    if ($runId !== '' && is_array($view['blocks'] ?? NULL)) {
      $view['blocks'] = $this->attachPageShots($view['blocks'], $runId);
    }
    return new JsonResponse($view);
  }

  public function reportMedia(Request $request): Response {
    $media = $this->connect->reportMedia(
      (string) $request->query->get('run', ''),
      (string) $request->query->get('file', ''),
    );
    if ($media === NULL) {
      return new JsonResponse(['message' => 'That screenshot is not available.'], 404);
    }
    return new Response($media['bytes'], 200, [
      'Content-Type' => $media['type'],
      'Content-Length' => (string) strlen($media['bytes']),
      'Cache-Control' => 'private, max-age=3600',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Points each accessibility screenshot at this site's media route.
   *
   * @param array<int, array<string, mixed>> $blocks
   *   Report blocks.
   *
   * @return array<int, array<string, mixed>>
   */
  private function attachPageShots(array $blocks, string $runId): array {
    foreach ($blocks as &$block) {
      if (!is_array($block) || ($block['id'] ?? '') !== 'pages' || !is_array($block['rows'] ?? NULL)) {
        continue;
      }
      $index = NULL;
      foreach ($block['columns'] ?? [] as $columnIndex => $column) {
        if (is_array($column) && ($column['id'] ?? '') === 'preview') {
          $index = $columnIndex;
          break;
        }
      }
      if ($index === NULL) {
        continue;
      }
      foreach ($block['rows'] as &$row) {
        if (!is_array($row) || !is_array($row['cells'] ?? NULL) || !isset($row['cells'][$index]) || !is_array($row['cells'][$index])) {
          continue;
        }
        $shot = '';
        if (is_array($row['page'] ?? NULL)) {
          $shot = (string) ($row['page']['shot'] ?? '');
        }
        if ($shot === '') {
          continue;
        }
        $row['cells'][$index]['image'] = [
          'src' => Url::fromRoute('pendulum_arc.report_media', [], [
            'query' => [
              'run' => $runId,
              'file' => $shot,
            ],
          ])->toString(),
          'alt' => '',
        ];
      }
    }
    return $blocks;
  }

  public function runsCollection(Request $request): JsonResponse {
    if ($request->isMethod('POST')) {
      return $this->enqueueRun($request);
    }
    return $this->runs();
  }

  public function runs(): JsonResponse {
    return new JsonResponse($this->connect->runs());
  }

  public function enqueueRun(Request $request): JsonResponse {
    $body = $this->body($request);
    return $this->respond(function () use ($body) {
      return $this->connect->enqueueRun((string) ($body['service'] ?? ''));
    });
  }

  public function googleConnect(): JsonResponse {
    $return = Url::fromRoute('pendulum_arc.dashboard', [], ['absolute' => TRUE])->toString();
    $email = (string) $this->currentUser()->getEmail();
    return $this->respond(function () use ($return, $email) {
      return $this->google->start((int) $this->currentUser()->id(), $email, $return);
    });
  }

  public function invites(Request $request): JsonResponse {
    if ($request->isMethod('POST')) {
      $body = $this->body($request);
      $ids = $body['userIds'] ?? $body['ids'] ?? [];
      return $this->respond(function () use ($ids) {
        return $this->invites->send(is_array($ids) ? $ids : []);
      });
    }
    return $this->respond(function () use ($request) {
      return $this->invites->page((int) $request->query->get('page', 1), (string) $request->query->get('search', ''));
    });
  }

  public function keyphrases(Request $request): JsonResponse {
    if ($request->isMethod('POST')) {
      $body = $this->body($request);
      return $this->respond(function () use ($body) {
        return $this->keyphrases->setEnabled(!empty($body['enabled']));
      });
    }
    return $this->respond(function () {
      return $this->keyphrases->status();
    });
  }

  public function keyphrasesSync(): JsonResponse {
    return $this->respond(function () {
      return $this->keyphrases->push();
    });
  }

  /**
   * @return array<string, mixed>
   */
  private function body(Request $request): array {
    $decoded = json_decode($request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  private function respond(callable $callback): JsonResponse {
    try {
      return new JsonResponse($callback());
    }
    catch (ArcApiException $error) {
      return $this->apiError($error);
    }
  }

  private function apiError(ArcApiException $error): JsonResponse {
    $status = $error->statusCode();
    if ($status < 400 || $status > 599) {
      $status = 502;
    }
    return new JsonResponse([
      'message' => $error->getMessage(),
    ], $status);
  }

}
