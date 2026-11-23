<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form for exceptions (trusted IP, CIDR or bot User-Agent).
 */
final class ExceptionForm extends FormBase {

  private int $eid = 0;

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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yu_ipas_exception_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $eid = NULL): array {
    $this->eid = $eid ?? 0;
    $exception = $this->eid ? $this->storage->getException($this->eid) : NULL;

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Exception type'),
      '#options' => [
        'ip' => $this->t('Single IP address'),
        'cidr' => $this->t('CIDR range'),
        'ua' => $this->t('User-Agent substring (bots, SEO services)'),
      ],
      '#default_value' => $exception->type ?? 'ip',
      '#required' => TRUE,
    ];

    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#description' => $this->t('IP, CIDR or a case-insensitive User-Agent substring, e.g. "YandexBot".'),
      '#default_value' => $exception->value ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Comment'),
      '#default_value' => $exception->label ?? '',
      '#maxlength' => 255,
    ];

    $form['verify_rdns'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify by reverse DNS'),
      '#description' => $this->t('Only for User-Agent exceptions: the client IP must resolve through forward-confirmed reverse DNS to a known bot network (yandex.ru, googlebot.com, bing.com, ...). Recommended for Yandex/Google bots to prevent spoofing.'),
      '#default_value' => $exception ? (bool) $exception->verify_rdns : FALSE,
      '#states' => [
        'visible' => [':input[name="type"]' => ['value' => 'ua']],
      ],
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $exception ? (bool) $exception->enabled : TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->eid ? $this->t('Save exception') : $this->t('Add exception'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $type = (string) $form_state->getValue('type');
    $value = trim((string) $form_state->getValue('value'));

    if ($type === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
      $form_state->setErrorByName('value', $this->t('Invalid IP address.'));
    }
    elseif ($type === 'cidr' && !str_contains($value, '/')) {
      $form_state->setErrorByName('value', $this->t('CIDR must include a prefix, e.g. 192.168.0.0/24.'));
    }
    elseif ($type === 'ua' && mb_strlen($value) < 3) {
      $form_state->setErrorByName('value', $this->t('User-Agent substring is too short.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->storage->saveException(
      (string) $form_state->getValue('type'),
      trim((string) $form_state->getValue('value')),
      (string) $form_state->getValue('label'),
      (bool) $form_state->getValue('verify_rdns'),
      (bool) $form_state->getValue('enabled'),
      $this->eid ?: NULL,
    );
    $this->messenger()->addStatus($this->t('The exception has been saved.'));
    $form_state->setRedirect('yu_ipas.exceptions');
  }

}
