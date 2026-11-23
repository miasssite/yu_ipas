<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Drush\Commands;

use Drupal\yu_ipas\GeoIp\DbSourceManager;
use Drupal\yu_ipas\GeoIp\GeoDbDownloader;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the yu_ipas module.
 */
final class YuIpasCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly GeoDbDownloader $dbDownloader,
  ) {}

  /**
   * Downloads and installs the GeoIP City database from MaxMind or a mirror.
   */
  #[CLI\Command(name: 'yu-ipas:download-db', aliases: ['yuipas-db'])]
  #[CLI\Option(name: 'source', description: 'Download source: maxmind, p3terx, jsdelivr, dbip, custom. Falls back to yu_ipas.settings:db_source, then maxmind.')]
  #[CLI\Option(name: 'key', description: 'MaxMind license key (falls back to settings or MAXMIND_LICENSE_KEY env). Only needed for the maxmind source.')]
  public function downloadDb(array $options = ['source' => self::OPT, 'key' => self::REQ]): void {
    $source = (string) ($options['source'] ?? '');
    $key = (string) ($options['key'] ?? '');

    $allSources = DbSourceManager::all();
    if ($source !== '' && !isset($allSources[$source])) {
      $this->logger()->error(dt('Unknown source "@source". Available: @list', [
        '@source' => $source,
        '@list' => implode(', ', array_keys($allSources)),
      ]));
      return;
    }

    try {
      $result = $this->dbDownloader->download($source !== '' ? $source : NULL, $key);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

    $this->logger()->success(dt('@label: GeoLite2-City installed at @dest.', [
      '@label' => $result['label'],
      '@dest' => $result['path'],
    ]));
    $this->logger()->notice(dt('Downloaded from @url', ['@url' => $result['url']]));
  }

  /**
   * Shows the GeoIP database status and available download sources.
   */
  #[CLI\Command(name: 'yu-ipas:status', aliases: ['yuipas-status'])]
  public function status(): void {
    $geo = \Drupal::service('yu_ipas.geo_lookup');
    $this->logger()->notice('GeoLite2-City: ' . ($geo->hasDatabase() ? 'OK' : 'MISSING'));
    $path = $geo->databasePath();
    if ($path !== NULL) {
      $this->logger()->notice('Path: ' . $path . ' (' . round(filesize($path) / 1048576, 1) . ' MB)');
    }

    $config = \Drupal::config('yu_ipas.settings');
    $source = (string) ($config->get('db_source') ?: DbSourceManager::MAXMIND);
    $this->logger()->notice('Download source: ' . $source);
    if ($source === DbSourceManager::CUSTOM) {
      $this->logger()->notice('Custom URL: ' . ((string) $config->get('custom_db_url') ?: '(not set)'));
    }

    foreach (DbSourceManager::all() as $id => $sourceInfo) {
      $marker = ($id === $source) ? '*' : ' ';
      $this->logger()->notice(sprintf('%s %-9s %s%s', $marker, $id, $sourceInfo['needs_key'] ? '[key]     ' : '[no reg]  ', $sourceInfo['label']));
    }

    $this->logger()->notice('Blocking: ' . ($config->get('enabled') ? 'ENABLED' : 'disabled') . ', mode: ' . $config->get('mode'));
    if ($config->get('enabled')) {
      $this->logger()->notice('Rescue URL: /yu-ipas/rescue/' . ($config->get('rescue_token') ?: '(no token)'));
    }
  }

  /**
   * Emergency: disables yu_ipas blocking to restore lost access.
   */
  #[CLI\Command(name: 'yu-ipas:disable', aliases: ['yuipas-off'])]
  #[CLI\Help(description: 'Immediately disables IP blocking (use when you are locked out of the site).')]
  public function disable(): void {
    \Drupal::configFactory()->getEditable('yu_ipas.settings')->set('enabled', FALSE)->save();
    $this->logger()->success(dt('yu_ipas blocking has been DISABLED.'));
  }

  /**
   * Enables yu_ipas blocking again.
   */
  #[CLI\Command(name: 'yu-ipas:enable', aliases: ['yuipas-on'])]
  #[CLI\Help(description: 'Re-enables IP blocking after it was disabled by yu-ipas:disable.')]
  public function enable(): void {
    \Drupal::configFactory()->getEditable('yu_ipas.settings')->set('enabled', TRUE)->save();
    $this->logger()->success(dt('yu_ipas blocking has been ENABLED.'));
  }

}
