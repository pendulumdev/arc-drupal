<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\pendulum_arc\ArcApiException;
use Drupal\pendulum_arc\ConnectService;
use Drupal\pendulum_arc\Rules\DraftException;
use Drupal\pendulum_arc\Rules\FieldMessages;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON routes for the admin screens.
 */
final class JsonController extends ControllerBase {

  public function __construct(
    private readonly ConnectService $connect,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('pendulum_arc.connect'),
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
    return new JsonResponse($this->connect->report($service));
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
