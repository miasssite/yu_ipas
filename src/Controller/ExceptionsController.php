<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exceptions list: trusted IPs, CIDR ranges and bot User-Agents.
 */
final class ExceptionsController extends ControllerBase {

  public function __construct(
    private readonly RuleStorage $storage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yu_ipas.rule_storage'),
    );
  }

  /**
   * Renders the exceptions list.
   */
  public function build(): array {
    $exceptions = $this->storage->getExceptions();

    $typeLabels = [
      'ip' => $this->t('IP'),
      'cidr' => $this->t('CIDR'),
      'ua' => $this->t('User-Agent'),
    ];

    $header = [
      $this->t('Type'),
      $this->t('Value'),
      $this->t('Comment'),
      $this->t('rDNS verify'),
      $this->t('Status'),
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($exceptions as $e) {
      $operations = [
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('yu_ipas.exception_edit', ['eid' => $e->eid]),
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('yu_ipas.exception_delete', ['eid' => $e->eid]),
        ],
      ];

      $rows[] = [
        ['data' => $typeLabels[$e->type] ?? $e->type],
        ['data' => $e->value],
        ['data' => $e->label],
        ['data' => (bool) $e->verify_rdns ? $this->t('Yes') : $this->t('—')],
        ['data' => (bool) $e->enabled ? $this->t('Enabled') : $this->t('Disabled')],
        ['data' => ['#type' => 'operations', '#links' => $operations]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No exceptions. Trusted bots (YandexBot, Googlebot) and SEO services can be added via "Add exception".'),
      '#attributes' => ['class' => ['yu-ipas-exceptions-table']],
    ];

    $build['help'] = [
      '#markup' => $this->t('Recommended defaults: YandexBot (verify rDNS), Googlebot (verify rDNS), YandexDirect/Google AdWords verifiers, webmasters tools. SEO analytics bots (Ahrefs, Semrush, MJ12) can be exempted without rDNS verification or blocked together with everything else.'),
    ];

    $build['#attached']['library'][] = 'yu_ipas/yu_ipas.admin';
    return $build;
  }

}
