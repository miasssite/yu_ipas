<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\yu_ipas\GeoIp\DbSourceManager;
use Drupal\yu_ipas\GeoIp\GeoDbDownloader;
use Drupal\yu_ipas\GeoIp\GeoLookupService;
use Drupal\yu_ipas\Rules\RuleEngine;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the yu_ipas module.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly GeoLookupService $geoLookup,
    private readonly GeoDbDownloader $dbDownloader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yu_ipas.geo_lookup'),
      $container->get('yu_ipas.db_downloader'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yu_ipas_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['yu_ipas.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('yu_ipas.settings');

    // GeoIP database status banner.
    if ($this->geoLookup->hasDatabase()) {
      $form['geo_status'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'status' => [
            [
              '#markup' => $this->t('GeoLite2-City database is available at @path.', [
                '@path' => $config->get('mmdb_path'),
              ]),
            ],
          ],
        ],
      ];
    }
    else {
      $form['geo_status'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            [
              '#markup' => $this->t('GeoLite2-City database not found or the maxmind-db/reader library is missing. Run <code>drush yu-ipas:download-db</code> or press "Download database now" below. Until then only manual IP/CIDR rules will work; country/region rules cannot be evaluated.'),
            ],
          ],
        ],
      ];
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable blocking'),
      '#description' => $this->t('When disabled the module only collects statistics and never blocks requests.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Rule mode'),
      '#description' => $this->t('<strong>Blacklist:</strong> listed countries and Russian regions are blocked, everything else is allowed.<br><strong>Whitelist:</strong> only listed Russian regions / countries (and manual IPs) are allowed, everything else is blocked. Trusted bot exceptions apply in both modes.'),
      '#options' => [
        RuleEngine::MODE_BLACKLIST => $this->t('Blacklist — block the selected countries/regions'),
        RuleEngine::MODE_WHITELIST => $this->t('Whitelist — allow only the selected Russian regions'),
      ],
      '#default_value' => $config->get('mode') ?: RuleEngine::MODE_BLACKLIST,
      '#required' => TRUE,
    ];

    $form['geoip'] = [
      '#type' => 'details',
      '#title' => $this->t('GeoIP database (MaxMind GeoLite2-City)'),
      '#open' => TRUE,
    ];

    $form['geoip']['mmdb_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to GeoLite2-City.mmdb'),
      '#description' => $this->t('Stream wrapper path, e.g. private://yu_ipas/GeoLite2-City.mmdb.'),
      '#default_value' => $config->get('mmdb_path'),
      '#required' => TRUE,
    ];

    $form['geoip']['db_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Download source'),
      '#description' => $this->t('The official MaxMind download requires a free license key. Mirrors (P3TERX, jsDelivr, DB-IP Lite) serve the same GeoLite2/compatible City database in .mmdb format and need no registration — use them when the key is not available or download.maxmind.com is unreachable.'),
      '#options' => DbSourceManager::options(),
      '#default_value' => $config->get('db_source') ?: DbSourceManager::MAXMIND,
    ];

    $form['geoip']['custom_db_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Custom download URL'),
      '#description' => $this->t('Direct http(s) URL to a .mmdb or gzipped .mmdb.gz file (own mirror, self-hosted copy). Used only when the source is "Custom URL".'),
      '#default_value' => $config->get('custom_db_url'),
      '#states' => [
        'visible' => [
          ':input[name="db_source"]' => ['value' => DbSourceManager::CUSTOM],
        ],
      ],
    ];

    $form['geoip']['maxmind_key'] = [
      '#type' => 'password',
      '#title' => $this->t('MaxMind license key'),
      '#description' => $this->t('Free key from maxmind.com. Only needed for the official "MaxMind" source; mirrors work without registration. Prefer the MAXMIND_LICENSE_KEY environment variable on production.'),
      '#default_value' => $config->get('maxmind_key'),
    ];

    $form['geoip']['download_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download database now'),
      '#description' => $this->t('Downloads the City database from the selected source, validates it and installs it to the path above. Takes up to a few minutes — the Drush command <code>drush yu-ipas:download-db</code> is the preferred way on production.'),
      '#submit' => ['::downloadNow'],
    ];

    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging and statistics'),
      '#open' => TRUE,
    ];

    $form['logging']['log_passed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log every passed visit'),
      '#description' => $this->t('When disabled, only the first visit of each IP per day is logged. Enable only for small/low-traffic sites.'),
      '#default_value' => (bool) $config->get('log_passed'),
    ];

    $form['logging']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Log retention period (days)'),
      '#description' => $this->t('Older records are removed on every cron run. 0 disables cleanup.'),
      '#min' => 0,
      '#max' => 3650,
      '#default_value' => $config->get('log_retention_days') ?: 30,
    ];

    $form['bots'] = [
      '#type' => 'details',
      '#title' => $this->t('Trusted bots'),
      '#open' => TRUE,
    ];

    $form['bots']['bot_rdns_verify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify bots by reverse DNS'),
      '#description' => $this->t('User-Agent exceptions marked as "verify" are only trusted when the client IP resolves through forward-confirmed reverse DNS to a known bot network (yandex.ru, googlebot.com, bing.com, etc.). Protects against User-Agent spoofing.'),
      '#default_value' => (bool) $config->get('bot_rdns_verify'),
    ];

    $form['safety'] = [
      '#type' => 'details',
      '#title' => $this->t('Emergency access'),
      '#description' => $this->t('Protection against accidental self-lockout (for example, when whitelist mode blocks your own IP or region).'),
      '#open' => TRUE,
    ];

    $form['safety']['admins_safe'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Never block administrators'),
      '#description' => $this->t('Logged-in user #1, users with the "administrator" role and holders of the "Bypass IP Anti Spam blocking" permission are never blocked by any rule. Recommended: keep enabled.'),
      '#default_value' => $config->get('admins_safe') === NULL ? TRUE : (bool) $config->get('admins_safe'),
    ];

    $rescueToken = (string) ($config->get('rescue_token') ?? '');
    if ($rescueToken === '') {
      $rescueToken = bin2hex(random_bytes(21));
      // Persist immediately so the URL shown below is valid right away.
      $this->config('yu_ipas.settings')->set('rescue_token', $rescueToken)->save();
    }
    $form['safety']['rescue_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Emergency URL'),
      '#markup' => $this->t('<a href=":url"><code>:url</code></a> works from ANY IP — even a blocked one. It disables blocking and adds the visitor IP to the trusted exceptions. Save this URL somewhere safe (password manager).', [
        ':url' => '/yu-ipas/rescue/' . $rescueToken,
      ]),
    ];

    $form['safety']['regenerate_token'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Regenerate rescue token'),
      '#description' => $this->t('Use if the emergency URL was compromised. The old URL will stop working.'),
      '#default_value' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $path = trim((string) $form_state->getValue('mmdb_path'));
    if (!preg_match('#^[a-z]+://#i', $path)) {
      $form_state->setErrorByName('mmdb_path', $this->t('Use a stream wrapper path like private://yu_ipas/GeoLite2-City.mmdb.'));
    }
    $mode = (string) $form_state->getValue('mode');
    if (!in_array($mode, [RuleEngine::MODE_BLACKLIST, RuleEngine::MODE_WHITELIST], TRUE)) {
      $form_state->setErrorByName('mode', $this->t('Invalid mode.'));
    }
    $source = (string) $form_state->getValue('db_source');
    if (!isset(DbSourceManager::all()[$source])) {
      $form_state->setErrorByName('db_source', $this->t('Invalid download source.'));
    }
    if ($source === DbSourceManager::CUSTOM) {
      $url = trim((string) $form_state->getValue('custom_db_url'));
      if ($url === '' || !preg_match('#^https?://#i', $url)) {
        $form_state->setErrorByName('custom_db_url', $this->t('Provide a direct http(s) URL to a .mmdb or .mmdb.gz file.'));
      }
    }
  }

  /**
   * Submit handler: saves the settings without running the download.
   */
  private function saveSettings(FormStateInterface $form_state): void {
    $token = (string) ($this->config('yu_ipas.settings')->get('rescue_token') ?? '');

    if ((bool) $form_state->getValue('regenerate_token')) {
      $token = bin2hex(random_bytes(21));
      $this->messenger()->addStatus($this->t('The rescue token has been changed. The new emergency URL is <a href=":url">:url</a> — save it somewhere safe.', [
        ':url' => '/yu-ipas/rescue/' . $token,
        '@url' => '/yu-ipas/rescue/' . $token,
      ]));
    }

    $this->config('yu_ipas.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('mode', (string) $form_state->getValue('mode'))
      ->set('mmdb_path', trim((string) $form_state->getValue('mmdb_path')))
      ->set('db_source', (string) $form_state->getValue('db_source'))
      ->set('custom_db_url', trim((string) $form_state->getValue('custom_db_url')))
      ->set('maxmind_key', (string) $form_state->getValue('maxmind_key'))
      ->set('log_passed', (bool) $form_state->getValue('log_passed'))
      ->set('log_retention_days', (int) $form_state->getValue('log_retention_days'))
      ->set('bot_rdns_verify', (bool) $form_state->getValue('bot_rdns_verify'))
      ->set('admins_safe', (bool) $form_state->getValue('admins_safe'))
      ->set('rescue_token', $token)
      ->save();
  }

  /**
   * Submit handler for the "Download database now" button.
   */
  public function downloadNow(array &$form, FormStateInterface $form_state): void {
    $this->saveSettings($form_state);

    try {
      $result = $this->dbDownloader->download();
      $this->messenger()->addStatus($this->t('Geo database from @label installed at @path.', [
        '@label' => $result['label'],
        '@path' => $result['path'],
      ]));
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
    }

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveSettings($form_state);
    parent::submitForm($form, $form_state);
  }

}
