<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\yu_ipas\GeoIp\GeoLookupService;
use Drupal\yu_ipas\RegionList;
use Drupal\yu_ipas\Rules\Decision;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Statistics page: visits, blocks and summary reports.
 */
final class StatsController extends ControllerBase {

  private const PERIODS = [
    '7' => '7 days',
    '30' => '30 days',
    '90' => '90 days',
    '0' => 'All time',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly GeoLookupService $geoLookup,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('yu_ipas.geo_lookup'),
    );
  }

  /**
   * Renders the statistics page.
   */
  public function build(Request $request): array {
    $period = (string) $request->query->get('period', '30');
    if (!isset(self::PERIODS[$period])) {
      $period = '30';
    }
    $from = $period === '0' ? 0 : \time() - ((int) $period * 86400);

    $build['period_switcher'] = $this->buildPeriodSwitcher($period);
    $build['summary'] = $this->buildSummary($from);
    $build['top_countries'] = $this->buildTopTable(
      $this->t('Top countries (blocked)'),
      'country',
      Decision::BLOCK,
      $from,
      static fn(string $code): string => RegionList::countryName($code),
    );
    $build['top_regions'] = $this->buildTopTable(
      $this->t('Top Russian regions (blocked)'),
      'region',
      Decision::BLOCK,
      $from,
      static fn(string $code): string => RegionList::regionName($code),
    );
    $build['top_ips'] = $this->buildTopIps($from);
    $build['recent'] = $this->buildRecent($from);

    if (!$this->geoLookup->hasDatabase()) {
      $build['warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            ['#markup' => $this->t('GeoLite2-City database is not available — country/region fields in the log will be empty. Run <code>drush yu-ipas:download-db</code>.')],
          ],
        ],
      ];
    }

    $build['#attached']['library'][] = 'yu_ipas/yu_ipas.admin';
    return $build;
  }

  /**
   * Period switcher as simple links.
   */
  private function buildPeriodSwitcher(string $current): array {
    $items = [];
    foreach (self::PERIODS as $key => $label) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t($label),
        '#url' => Url::fromRoute('yu_ipas.stats', [], ['query' => ['period' => $key]]),
        '#attributes' => ['class' => ['yu-ipas-period-link', $key === $current ? 'is-active' : '']],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['yu-ipas-periods']],
      '#items' => $items,
    ];
  }

  /**
   * Summary cards: totals per action and block ratio.
   */
  private function buildSummary(int $from): array {
    $q = $this->database->select(RuleStorage::TABLE_LOG, 'l')
      ->fields('l', ['action'])
      ->condition('l.created', $from, '>=')
      ->groupBy('action');
    // Drupal 11.4 addExpression() returns the alias string, not $this.
    $q->addExpression('COUNT(*)', 'cnt');
    $q->addExpression('COUNT(DISTINCT ip)', 'ips');
    $results = $q->execute()->fetchAllAssoc('action');

    $passed = (int) ($results[Decision::PASS]->cnt ?? 0);
    $blocked = (int) ($results[Decision::BLOCK]->cnt ?? 0);
    $exceptions = (int) ($results[Decision::EXCEPTION]->cnt ?? 0);
    $passedIps = (int) ($results[Decision::PASS]->ips ?? 0);
    $blockedIps = (int) ($results[Decision::BLOCK]->ips ?? 0);
    $total = $passed + $blocked + $exceptions;
    $ratio = $total > 0 ? \round($blocked / $total * 100, 1) : 0;

    $cards = [
      ['label' => $this->t('Total requests logged'), 'value' => $total],
      ['label' => $this->t('Passed visits'), 'value' => $passed, 'sub' => (string) $this->t('@count IPs', ['@count' => $passedIps])],
      ['label' => $this->t('Blocked'), 'value' => $blocked, 'sub' => (string) $this->t('@count IPs', ['@count' => $blockedIps])],
      ['label' => $this->t('Bot exceptions'), 'value' => $exceptions],
      ['label' => $this->t('Block ratio'), 'value' => $ratio . '%'],
    ];

    $build['cards'] = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['yu-ipas-summary-cards']],
      '#items' => \array_map(static fn(array $c): array => [
        '#markup' => '<span class="yu-ipas-card__value">' . $c['value'] . '</span>'
          . '<span class="yu-ipas-card__label">' . $c['label'] . '</span>'
          . (isset($c['sub']) ? '<span class="yu-ipas-card__sub">' . $c['sub'] . '</span>' : ''),
        '#wrapper_attributes' => ['class' => ['yu-ipas-card']],
      ], $cards),
    ];

    return $build;
  }

  /**
   * Top countries/regions table with CSS bars.
   */
  private function buildTopTable(string|\Drupal\Core\StringTranslation\TranslatableMarkup $title, string $column, string $action, int $from, callable $labelFn): array {
    $q = $this->database->select(RuleStorage::TABLE_LOG, 'l')
      ->fields('l', [$column])
      ->condition('l.created', $from, '>=')
      ->condition('l.action', $action)
      ->condition('l.' . $column, '', '<>')
      ->groupBy($column)
      ->orderBy('cnt', 'DESC')
      ->range(0, 15);
    $q->addExpression('COUNT(*)', 'cnt');
    $rows = $q->execute()->fetchAll();

    if ($rows === []) {
      return [
        '#type' => 'details',
        '#title' => $title,
        '#open' => TRUE,
        '#markup' => $this->t('No data for this period.'),
      ];
    }

    $max = (int) max(\array_column($rows, 'cnt'));
    $tableRows = [];
    foreach ($rows as $r) {
      $code = (string) $r->{$column};
      $width = $max > 0 ? (int) \round($r->cnt / $max * 100) : 0;
      $tableRows[] = [
        ['data' => $labelFn($code) . ' (' . $code . ')'],
        ['data' => $r->cnt],
        ['data' => [
          '#markup' => '<div class="yu-ipas-bar"><div class="yu-ipas-bar__fill" style="width:' . $width . '%"></div></div>',
        ]],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Geography'), $this->t('Blocked'), $this->t('')],
        '#rows' => $tableRows,
        '#attributes' => ['class' => ['yu-ipas-top-table']],
      ],
    ];
  }

  /**
   * Top blocked IPs.
   */
  private function buildTopIps(int $from): array {
    $q = $this->database->select(RuleStorage::TABLE_LOG, 'l')
      ->fields('l', ['ip', 'user_agent'])
      ->condition('l.created', $from, '>=')
      ->condition('l.action', Decision::BLOCK)
      ->groupBy('ip')
      ->groupBy('user_agent')
      ->orderBy('cnt', 'DESC')
      ->range(0, 15);
    $q->addExpression('COUNT(*)', 'cnt');
    $rows = $q->execute()->fetchAll();

    if ($rows === []) {
      return [
        '#type' => 'details',
        '#title' => $this->t('Top blocked IPs'),
        '#open' => FALSE,
        '#markup' => $this->t('No blocked IPs for this period.'),
      ];
    }

    $tableRows = [];
    foreach ($rows as $r) {
      $tableRows[] = [
        ['data' => $r->ip],
        ['data' => $r->cnt],
        ['data' => [
          '#markup' => '<span class="yu-ipas-ua">' . \htmlspecialchars(\mb_substr($r->user_agent, 0, 60), ENT_QUOTES) . '</span>',
        ]],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Top blocked IPs'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('IP'), $this->t('Blocked'), $this->t('User-Agent')],
        '#rows' => $tableRows,
      ],
    ];
  }

  /**
   * Recent log entries.
   */
  private function buildRecent(int $from): array {
    $q = $this->database->select(RuleStorage::TABLE_LOG, 'l')
      ->fields('l', ['created', 'ip', 'country', 'region', 'user_agent', 'action', 'is_bot'])
      ->condition('l.created', $from, '>=')
      ->orderBy('l.created', 'DESC')
      ->range(0, 25);
    $rows = $q->execute()->fetchAll();

    $actionLabels = [
      Decision::BLOCK => $this->t('Blocked'),
      Decision::PASS => $this->t('Passed'),
      Decision::EXCEPTION => $this->t('Exception'),
    ];

    $tableRows = [];
    foreach ($rows as $r) {
      $tableRows[] = [
        ['data' => \Drupal::service('date.formatter')->format((int) $r->created, 'short')],
        ['data' => $r->ip],
        ['data' => $r->country ?: '—'],
        ['data' => $r->region ? RegionList::regionName($r->region) : '—'],
        ['data' => $actionLabels[$r->action] ?? $r->action],
        ['data' => (bool) $r->is_bot ? $this->t('Yes') : $this->t('No')],
        ['data' => [
          '#markup' => '<span class="yu-ipas-ua">' . \htmlspecialchars(\mb_substr($r->user_agent, 0, 50), ENT_QUOTES) . '</span>',
        ]],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Recent events'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Time'),
          $this->t('IP'),
          $this->t('Country'),
          $this->t('Region'),
          $this->t('Action'),
          $this->t('Bot'),
          $this->t('User-Agent'),
        ],
        '#rows' => $tableRows,
        '#empty' => $this->t('Log is empty for this period.'),
      ],
    ];
  }

}
