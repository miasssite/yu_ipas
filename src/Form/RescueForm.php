<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Emergency unblock form.
 *
 * Reachable only with the secret rescue token. The middleware never blocks
 * this endpoint, so it works even when the admin's own IP is blocked.
 *
 * Actions:
 * 1. Disables blocking entirely (immediately restores access).
 * 2. Adds the current IP to the trusted exceptions list, so access stays
 *    intact even after blocking is enabled again.
 */
final class RescueForm extends FormBase {

  public function __construct(
    private readonly RuleStorage $storage,
    private readonly Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yu_ipas.rule_storage'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yu_ipas_rescue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $token = NULL): array {
    $configToken = (string) ($this->config('yu_ipas.settings')->get('rescue_token') ?? '');

    if ($configToken === '' || !hash_equals($configToken, (string) $token)) {
      $form['error'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'error' => [
            ['#markup' => $this->t('Invalid or missing rescue token. Ask the site administrator for the emergency URL (Admin → IP Anti Spam → Settings → Emergency access).')],
          ],
        ],
      ];
      return $form;
    }

    $enabled = (bool) $this->config('yu_ipas.settings')->get('enabled');
    $status = $enabled
      ? $this->t('Blocking is currently ENABLED. Submitting the form below will restore your access.')
      : $this->t('Blocking is currently disabled. You can still add your current IP to the trusted exceptions.');

    $form['status'] = [
      '#markup' => $status,
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $form['token'] = [
      '#type' => 'hidden',
      '#value' => $configToken,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Restore my access'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ip = (string) ($this->request->getClientIp() ?? '');

    // 1. Add the current IP to the trusted exceptions (idempotent).
    if ($ip !== '') {
      $label = $this->t('Emergency unblock (@date)', ['@date' => \date('d.m.Y H:i')])->render();
      $this->storage->saveException('ip', $ip, $label, FALSE, TRUE);
    }

    // 2. Disable blocking entirely so access is restored right now.
    $editable = $this->configFactory()->getEditable('yu_ipas.settings');
    if ((bool) $editable->get('enabled')) {
      $editable->set('enabled', FALSE)->save();
    }

    $this->messenger()->addStatus($this->t('Access restored. Blocking is disabled and your IP %ip has been added to the trusted exceptions. Review your rules at @link before enabling blocking again.', [
      '%ip' => $ip ?: '?',
      '@link' => Url::fromRoute('yu_ipas.rules')->toString(),
    ]));
  }

}
