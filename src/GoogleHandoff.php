<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\pendulum_arc\Rules\GoogleConnect;
use Drupal\pendulum_arc\Rules\InviteRules;
use Drupal\pendulum_arc\Rules\Pkce;

/**
 * Mints a Google connect handoff and checks the return on the dashboard.
 *
 * An Arc account is sent to the portal URL. An email with no Arc account
 * is invited, and the inbox link continues onto that same ticket.
 */
final class GoogleHandoff {

  public function __construct(
    private readonly ConnectionStoreInterface $store,
    private readonly ConnectService $connect,
    private readonly ArcGatewayInterface $client,
    private readonly IdGeneratorInterface $ids,
  ) {}

  /**
   * Starts Google connect for the admin who clicked the button.
   *
   * @return array<string, mixed>
   */
  public function start(int $userId, string $email, string $returnUri): array {
    $creds = $this->store->credentials();
    if ($creds === NULL) {
      throw new ArcApiException('Connect this site to Arc first.', 401);
    }
    $projectId = (string) ($creds['projectId'] ?? '');
    if ($projectId === '') {
      throw new ArcApiException('Reconnect this site in Arc before connecting Google.', 400);
    }
    $email = InviteRules::email($email);
    if ($email === '') {
      throw new ArcApiException('This Drupal user needs an email address before connecting Google.', 400);
    }
    $state = Pkce::state();
    $handoff = $this->connect->withArc(function (string $env, string $token, string $siteId) use ($returnUri, $state) {
      return $this->client->googleConnectHandoff($env, $token, $siteId, $returnUri, $state);
    });
    $url = isset($handoff['url']) && is_string($handoff['url']) ? $handoff['url'] : '';
    $continue = GoogleConnect::ticketPath($url);
    if ($continue === '') {
      throw new ArcApiException('Arc did not return the Google connect link.', 502);
    }
    $this->store->putGoogleState($userId, $state);
    try {
      $status = $this->connect->withArc(function (string $env, string $token, string $siteId) use ($projectId, $email) {
        return $this->client->inviteStatus($env, $token, $projectId, [$email]);
      });
    }
    catch (ArcApiException $error) {
      if ($error->errorCode() === 'INSUFFICIENT_SCOPE') {
        return [
          'url' => $url,
          'reconnect' => TRUE,
          'message' => InviteRules::reconnectMessage(),
        ];
      }
      throw $error;
    }
    $row = GoogleConnect::inviteRow($status, $email);
    if ($row === NULL) {
      throw new ArcApiException('Arc did not return the Google connect link.', 502);
    }
    $destination = GoogleConnect::destination($row['status'], $row['account']);
    if ($destination === 'email') {
      return self::checkEmail();
    }
    if ($destination === 'url') {
      return ['url' => $url];
    }
    if ($destination !== 'invite') {
      throw new ArcApiException('Arc did not return the Google connect link.', 502);
    }
    try {
      $this->connect->withArc(function (string $env, string $token, string $siteId) use ($projectId, $email, $continue) {
        return $this->client->createInvite($env, $token, $projectId, $email, $continue, $this->ids->generate());
      });
    }
    catch (ArcApiException $error) {
      if ($error->errorCode() === 'OPEN_INVITE') {
        return self::checkEmail();
      }
      if ($error->errorCode() === 'ALREADY_MEMBER') {
        return ['url' => $url];
      }
      throw $error;
    }
    return self::checkEmail();
  }

  /**
   * Records the return from Arc, then the dashboard drops the query.
   *
   * The query is not proof that Google is bound. The next scores read is.
   *
   * @return bool
   *   TRUE when this request was a Google return and the caller should redirect.
   */
  public function finish(int $userId, string $state, string $issuer, string $status, string $error): bool {
    $status = strtolower((string) preg_replace('/[^a-z0-9_]/i', '', $status));
    $error = strtolower((string) preg_replace('/[^a-z0-9_]/i', '', $error));
    $state = trim($state);
    $issuer = trim($issuer);
    if ($state === '' || $issuer === '' || ($status === '' && $error === '')) {
      return FALSE;
    }
    if (filter_var($issuer, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }
    $stored = $this->store->takeGoogleState($userId);
    if ($stored === '' || strlen($stored) !== strlen($state) || !hash_equals($stored, $state)) {
      $this->store->putNotice($userId, 'error', 'That return did not match this browser. Start Connect Google again.');
      return TRUE;
    }
    if ($error === 'access_denied') {
      $this->store->putNotice($userId, 'error', 'Google connect was cancelled.');
      return TRUE;
    }
    if ($status === 'connected' && $error === '') {
      $this->store->putNotice($userId, 'success', 'You are back from Arc. Refresh the page if you do not see your Google connection yet.');
      return TRUE;
    }
    $this->store->putNotice($userId, 'error', 'Google connect did not finish. Try again.');
    return TRUE;
  }

  /**
   * @return array{checkEmail: true, message: string}
   */
  private static function checkEmail(): array {
    return [
      'checkEmail' => TRUE,
      'message' => 'Check your email for an Arc invite. Accept it to connect Google. You will return to this site.',
    ];
  }

}
