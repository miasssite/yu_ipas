<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\yu_ipas\GeoIp\GeoLookupService;
use Drupal\yu_ipas\RegionList;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Rules list grouped by countries and Russian regions.
 */
final class RulesListController extends ControllerBase {

  public function __construct(
    private readonly RuleStorage $storage,
    private readonly GeoLookupService $geoLookup,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yu_ipas.rule_storage'),
      $container->get('yu_ipas.geo_lookup'),
    );
  }

  /**
   * Renders the rules list grouped by rule type.
   */
  public function build(): array {
    $rules = $this->storage->getRules();
    $mode = (string) $this->config('yu_ipas.settings')->get('mode');
    $verb = $mode === 'whitelist'
      ? $this->t('These values are ALLOWED (whitelist mode)')
      : $this->t('These values are BLOCKED (blacklist mode)');

    $build['summary'] = [
      '#markup' => $verb,
      '#prefix' => '<p class="yu-ipas-mode-note">',
      '#suffix' => '</p>',
    ];

    if (!$this->geoLookup->hasDatabase()) {
      $build['warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [
            ['#markup' => $this->t('GeoLite2-City database is not available — country and region rules will have no effect. Run <code>drush yu-ipas:download-db</code>.')],
          ],
        ],
      ];
    }

    $groups = [
      'region' => $this->t('Russian regions'),
      'country' => $this->t('Countries'),
      'ip' => $this->t('Manual IP addresses'),
      'cidr' => $this->t('Manual CIDR ranges'),
    ];

    $grouped = [];
    foreach ($rules as $rule) {
      $grouped[$rule->type][] = $rule;
    }

    foreach ($groups as $type => $title) {
      $build[$type] = $this->buildGroup($title, $grouped[$type] ?? [], $type);
    }

    $build['#attached']['library'][] = 'yu_ipas/yu_ipas.admin';
    return $build;
  }

  /**
   * Builds one grouped table render array.
   */
  private function buildGroup(string|\Drupal\Core\StringTranslation\TranslatableMarkup $title, array $rules, string $type): array {
    $header = [
      $this->t('Value'),
      $this->t('Comment'),
      $this->t('Status'),
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($rules as $rule) {
      $value = $rule->type === 'region'
        ? RegionList::regionName($rule->value) . ' (' . $rule->value . ')'
        : ($rule->type === 'country'
          ? RegionList::countryName($rule->value) . ' (' . $rule->value . ')'
          : $rule->value);

      $operations = [];
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'url' => Url::fromRoute('yu_ipas.rule_edit', ['rid' => $rule->rid]),
      ];
      $operations['toggle'] = [
        'title' => (bool) $rule->enabled ? $this->t('Disable') : $this->t('Enable'),
        'url' => Url::fromRoute('yu_ipas.rule_toggle', ['rid' => $rule->rid], [
          'query' => ['token' => \Drupal::csrfToken()->get('yu_ipas:toggle:rule:' . $rule->rid)],
        ]),
      ];
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('yu_ipas.rule_delete', ['rid' => $rule->rid]),
      ];

      $rows[] = [
        ['data' => $value],
        ['data' => $rule->label],
        ['data' => (bool) $rule->enabled ? $this->t('Enabled') : $this->t('Disabled')],
        ['data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ]],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $title . ' (' . \count($rules) . ')',
      '#open' => $rules !== [],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No rules in this group. Use "Add rule" or "Bulk import".'),
        '#attributes' => ['class' => ['yu-ipas-rules-table']],
      ],
    ];
  }

  /**
   * Toggles rule state (CSRF protected link handler).
   */
  public function toggleRule(int $rid): RedirectResponse {
    $token = (string) \Drupal::request()->query->get('token');
    if (!\Drupal::csrfToken()->validate($token, 'yu_ipas:toggle:rule:' . $rid)) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $new = $this->storage->toggleRule($rid);
    if ($new === TRUE) {
      $this->messenger()->addStatus($this->t('Rule enabled.'));
    }
    elseif ($new === FALSE) {
      $this->messenger()->addStatus($this->t('Rule disabled.'));
    }
    return new RedirectResponse(Url::fromRoute('yu_ipas.rules')->toString());
  }

}
