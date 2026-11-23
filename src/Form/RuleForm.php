<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\yu_ipas\RegionList;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form for a single rule (manual IP, CIDR, country or RU region).
 */
final class RuleForm extends FormBase {

  private int $rid = 0;

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
    return 'yu_ipas_rule_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $rid = NULL): array {
    $this->rid = $rid ?? 0;
    $rule = $this->rid ? $this->storage->getRule($this->rid) : NULL;

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Rule type'),
      '#options' => [
        'ip' => $this->t('Single IP address'),
        'cidr' => $this->t('CIDR range (e.g. 192.168.0.0/24)'),
        'country' => $this->t('Whole country (ISO code)'),
        'region' => $this->t('Russian region (ISO 3166-2:RU)'),
      ],
      '#default_value' => $rule->type ?? 'ip',
      '#required' => TRUE,
    ];

    $form['ip_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IP address / CIDR'),
      '#default_value' => in_array($rule->type ?? '', ['ip', 'cidr'], TRUE) ? $rule->value : '',
      '#states' => [
        'visible' => [
          ':input[name="type"]' => [
            ['value' => 'ip'],
            ['value' => 'cidr'],
          ],
        ],
        'required' => [
          ':input[name="type"]' => [
            ['value' => 'ip'],
            ['value' => 'cidr'],
          ],
        ],
      ],
    ];

    $form['country_value'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => RegionList::countries(),
      '#default_value' => ($rule->type ?? '') === 'country' ? $rule->value : '',
      '#empty_option' => $this->t('- Select country -'),
      '#states' => [
        'visible' => [':input[name="type"]' => ['value' => 'country']],
        'required' => [':input[name="type"]' => ['value' => 'country']],
      ],
    ];

    $form['region_value'] = [
      '#type' => 'select',
      '#title' => $this->t('Russian region'),
      '#options' => RegionList::ruRegions(),
      '#default_value' => ($rule->type ?? '') === 'region' ? $rule->value : '',
      '#empty_option' => $this->t('- Select region -'),
      '#states' => [
        'visible' => [':input[name="type"]' => ['value' => 'region']],
        'required' => [':input[name="type"]' => ['value' => 'region']],
      ],
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Comment'),
      '#description' => $this->t('Optional human-readable note.'),
      '#default_value' => $rule->label ?? '',
      '#maxlength' => 255,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $rule ? (bool) $rule->enabled : TRUE,
    ];

    $mode = (string) $this->config('yu_ipas.settings')->get('mode');
    $form['mode_info'] = [
      '#markup' => $mode === 'whitelist'
        ? $this->t('<em>Current mode: whitelist — this rule ALLOWS the selected value, everything not listed will be blocked.</em>')
        : $this->t('<em>Current mode: blacklist — this rule BLOCKS the selected value.</em>'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->rid ? $this->t('Save rule') : $this->t('Add rule'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $type = (string) $form_state->getValue('type');
    $value = '';

    switch ($type) {
      case 'ip':
        $value = trim((string) $form_state->getValue('ip_value'));
        if (!filter_var($value, FILTER_VALIDATE_IP)) {
          $form_state->setErrorByName('ip_value', $this->t('Invalid IP address.'));
        }
        break;

      case 'cidr':
        $value = trim((string) $form_state->getValue('ip_value'));
        if (str_contains($value, '/')) {
          [$network, $bits] = explode('/', $value, 2);
          if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($bits) || (int) $bits > 128) {
            $form_state->setErrorByName('ip_value', $this->t('Invalid CIDR notation.'));
          }
        }
        else {
          $form_state->setErrorByName('ip_value', $this->t('CIDR must include a prefix, e.g. 192.168.0.0/24.'));
        }
        break;

      case 'country':
        $value = (string) $form_state->getValue('country_value');
        if ($value === '' || !preg_match('/^[A-Z]{2}$/', $value)) {
          $form_state->setErrorByName('country_value', $this->t('Select a country.'));
        }
        break;

      case 'region':
        $value = (string) $form_state->getValue('region_value');
        if ($value === '' || !isset(RegionList::ruRegions()[$value])) {
          $form_state->setErrorByName('region_value', $this->t('Select a Russian region.'));
        }
        break;
    }

    $form_state->setValue('resolved_value', $value);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->storage->saveRule(
      (string) $form_state->getValue('type'),
      (string) $form_state->getValue('resolved_value'),
      (string) $form_state->getValue('label'),
      (bool) $form_state->getValue('enabled'),
      $this->rid ?: NULL,
    );
    $this->messenger()->addStatus($this->t('The rule has been saved.'));
    $form_state->setRedirect('yu_ipas.rules');
  }

}
