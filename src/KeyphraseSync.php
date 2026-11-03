<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\pendulum_arc\Rules\KeyphraseHash;
use Drupal\pendulum_arc\Rules\KeyphrasePush;

/**
 * Checkbox, background scan, and the click that sends the list to Arc.
 *
 * The cron keeps the local list. Arc is called only from the status check and the sync button.
 */
final class KeyphraseSync {

  public const LOCK = 'pendulum_arc_keyphrase_scan';

  public function __construct(
    private readonly ConnectionStoreInterface $connections,
    private readonly ConnectService $connect,
    private readonly ArcGatewayInterface $client,
    private readonly KeyphraseList $list,
    private readonly KeyphraseScan $scan,
    private readonly KeyphraseRead $read,
    private readonly IdGeneratorInterface $ids,
    private readonly ConfigFactoryInterface $config,
    private readonly LockBackendInterface $lock,
    private readonly SiteProfileInterface $profile,
  ) {}

  /**
   * One cron page.
   */
  public function cron(): void {
    $this->pump();
  }

  /**
   * Refreshes one saved entity, or the entity a path alias points at.
   */
  public function refreshEntity(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'path_alias') {
      $path = '';
      if (method_exists($entity, 'getPath')) {
        $path = (string) $entity->getPath();
      }
      $this->scan->refreshInternal($path, $this->origin(), $this->front());
      return;
    }
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $this->scan->refresh($entity, $this->origin(), $this->front());
  }

  /**
   * Tombstones an entity that is about to be deleted.
   */
  public function forgetEntity(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $this->scan->forget($entity, $this->origin());
  }

  /**
   * Dashboard view. Opening the screen also reads one page of the scan.
   *
   * @return array{enabled: bool, scanning: bool, scanDone: bool, diverged: bool, notice: bool, pageCount: int, error: string}
   */
  public function status(): array {
    $this->pump();
    $state = $this->list->state();
    $view = $this->connections->publicView();
    $scanning = $state['enabled'] && !$state['scan_done'];
    $diverged = FALSE;
    $error = '';
    if ($state['enabled'] && $state['scan_done'] && !empty($view['connected'])) {
      try {
        $remote = $this->connect->withArc(function (string $env, string $token, string $siteId) {
          return $this->client->seoTargets($env, $token, $siteId);
        });
        $arcHash = (string) ($remote['hash'] ?? '');
        $local = KeyphraseHash::of($state['focus'], $this->list->currentPages());
        $diverged = $arcHash !== $local;
        if ($diverged) {
          if (empty($state['diverged'])) {
            $state['diverged'] = TRUE;
            $this->list->write($state);
          }
        }
        elseif ($this->list->needsSync(TRUE)) {
          $this->list->ack($arcHash, $state['focus']);
        }
      }
      catch (ArcApiException $failure) {
        $error = $failure->getMessage();
        $diverged = $this->list->needsSync(TRUE);
      }
    }
    return [
      'enabled' => $state['enabled'],
      'scanning' => $scanning,
      'scanDone' => $state['scan_done'],
      'diverged' => $diverged,
      'notice' => $state['notice'],
      'pageCount' => $state['enabled'] ? $this->list->pageCount() : 0,
      'error' => $error,
    ];
  }

  /**
   * Turns the background list on or off. Off does not change the Arc target.
   *
   * @return array{enabled: bool, scanning: bool, scanDone: bool, diverged: bool, notice: bool, pageCount: int, error: string}
   */
  public function setEnabled(bool $enabled): array {
    $state = $this->list->state();
    if ($enabled === $state['enabled']) {
      return $this->status();
    }
    if (!$enabled && $state['sync_id'] !== '') {
      $this->abandon($state['sync_id']);
    }
    $this->list->setEnabled($enabled, $enabled ? $this->read->entityTypes() : []);
    return $this->status();
  }

  /**
   * Sends the delta, or the whole list when Arc has moved, then queues the SEO report.
   *
   * @return array{ok: bool, message: string}
   */
  public function push(): array {
    $state = $this->list->state();
    if (!$state['enabled'] || !$state['scan_done']) {
      throw new ArcApiException('Keyphrase sync is still reading this site.', 409);
    }
    $remote = $this->connect->withArc(function (string $env, string $token, string $siteId) {
      return $this->client->seoTargets($env, $token, $siteId);
    });
    $arcHash = (string) ($remote['hash'] ?? '');
    $plan = KeyphrasePush::plan($state['acked_hash'], $arcHash, $state['focus'], $state['focus_synced'], $this->list->rows());
    if ($state['sync_id'] !== '') {
      $this->abandon($state['sync_id']);
    }
    $started = $this->connect->withArc(function (string $env, string $token, string $siteId) {
      return $this->client->startSeoTargetSync($env, $token, $siteId, $this->ids->generate());
    });
    $syncId = (string) ($started['syncId'] ?? '');
    if ($syncId === '') {
      throw new ArcApiException('Arc refused the request. Try again in a moment.', 502);
    }
    $state = $this->list->state();
    $state['sync_id'] = $syncId;
    $this->list->write($state);
    try {
      foreach (KeyphrasePush::batches($plan['upserts'], $plan['deletes']) as $batch) {
        $this->connect->withArc(function (string $env, string $token, string $siteId) use ($syncId, $batch) {
          return $this->client->appendSeoTargetBatch($env, $token, $siteId, $syncId, $batch, $this->ids->generate());
        });
      }
      $body = [
        'mode' => $plan['mode'],
        'baseHash' => $arcHash,
        'auto_run' => TRUE,
      ];
      if ($plan['send_focus']) {
        $body['focusKeyphrase'] = $plan['focus'];
      }
      $committed = $this->connect->withArc(function (string $env, string $token, string $siteId) use ($syncId, $body) {
        return $this->client->commitSeoTargetSync($env, $token, $siteId, $syncId, $body, $this->ids->generate());
      });
    }
    catch (ArcApiException $failure) {
      $this->abandon($syncId);
      throw $failure;
    }
    $focus = $committed['focusKeyphrase'] ?? $state['focus'];
    if (!is_string($focus)) {
      $focus = '';
    }
    $this->list->ack((string) ($committed['hash'] ?? ''), $focus);
    return [
      'ok' => TRUE,
      'message' => self::resultMessage($committed),
    ];
  }

  /**
   * Reads one page unless another tick is already running.
   */
  private function pump(): bool {
    $state = $this->list->state();
    if (!$state['enabled'] || $state['scan_done']) {
      return FALSE;
    }
    if (!$this->lock->acquire(self::LOCK, 30)) {
      return TRUE;
    }
    try {
      return $this->scan->tick($this->origin(), $this->front());
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Paired site address. Paths are stored relative to that origin.
   */
  private function origin(): string {
    $view = $this->connections->publicView();
    $url = (string) ($view['siteUrl'] ?? '');
    if ($url === '') {
      $url = (string) ($this->profile->get()['origin'] ?? '');
    }
    return $url;
  }

  /**
   * Configured front page, such as /node/1.
   */
  private function front(): string {
    return (string) $this->config->get('system.site')->get('page.front');
  }

  /**
   * Drops an open Arc session. A missing session is ignored.
   */
  private function abandon(string $syncId): void {
    if ($syncId === '') {
      return;
    }
    try {
      $this->connect->withArc(function (string $env, string $token, string $siteId) use ($syncId) {
        return $this->client->abandonSeoTargetSync($env, $token, $siteId, $syncId, $this->ids->generate());
      });
    }
    catch (ArcApiException) {
    }
    $state = $this->list->state();
    $state['sync_id'] = '';
    $this->list->write($state);
  }

  /**
   * @param array<string, mixed> $committed
   *   Commit response.
   */
  private static function resultMessage(array $committed): string {
    $queue = $committed['queueError'] ?? '';
    if (is_string($queue) && $queue !== '') {
      if ($queue === 'PAGE_QUOTA_EXHAUSTED') {
        return 'The target was saved. The SEO report was not queued because the page allowance is used.';
      }
      if ($queue === 'RUN_ACTIVE') {
        return 'The target was saved. An SEO report is already running.';
      }
      if ($queue === 'RUN_RATE_EXHAUSTED') {
        return 'The target was saved. The SEO report was not queued because the daily limit is reached.';
      }
      return 'The target was saved. The SEO report was not queued.';
    }
    if (empty($committed['declared'])) {
      return 'Arc already has this target.';
    }
    return 'Synced. The SEO report is queued.';
  }

}
