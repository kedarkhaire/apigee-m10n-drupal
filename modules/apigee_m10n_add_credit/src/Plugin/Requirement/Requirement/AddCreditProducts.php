<?php

/*
 * Copyright 2019 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\apigee_m10n_add_credit\Plugin\Requirement\Requirement;

use Apigee\Edge\Api\ApigeeX\Controller\SupportedCurrencyController as ApigeeXSupportedCurrencyController;
use Apigee\Edge\Api\Monetization\Controller\SupportedCurrencyController;
use Apigee\Edge\Api\Monetization\Entity\SupportedCurrencyInterface;
use CommerceGuys\Intl\Currency\CurrencyRepository;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\apigee_m10n\ApigeeEdgeSdkConnectorTrait;
use Drupal\apigee_m10n_add_credit\AddCreditConfig;
use Drupal\commerce_price\Price;
use Drupal\requirement\Plugin\RequirementBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Check that the "Add credit" product has been configured.
 *
 * @Requirement(
 *   id="add_credit_products",
 *   group="apigee_m10n_add_credit",
 *   label="Add credit products",
 *   description="Create an add credit product for each supported currency.",
 *   severity="error",
 *   action_button_label="Create products",
 *   dependencies={
 *      "apigee_edge_connection",
 *      "commerce_store",
 *      "add_credit_product_type",
 *   }
 * )
 */
class AddCreditProducts extends RequirementBase implements ContainerFactoryPluginInterface {

  use ApigeeEdgeSdkConnectorTrait;

  /**
   * An array of supported currencies.
   *
   * @var \Apigee\Edge\Api\Monetization\Entity\SupportedCurrencyInterface[]
   */
  protected $supportedCurrencies;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The library's currency repository.
   *
   * @var \CommerceGuys\Intl\Currency\CurrencyRepository
   */
  protected $currencyRepository;

  /**
   * The Add credit product manager.
   *
   * @var \Drupal\apigee_m10n_add_credit\AddCreditProductManager
   */
  protected $addCreditProductManager;

  /**
   * An array of importable currencies.
   *
   * @var array|\CommerceGuys\Intl\Currency\Currency[]
   */
  protected $importableCurrencies;

  /**
   * AddCreditProducts constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LanguageManagerInterface $language_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->currencyRepository = new CurrencyRepository();
    $this->importableCurrencies = $this->getImportableCurrencies();

    // Get organization supported currencies.
    try {
      $this->addCreditProductManager = \Drupal::service('apigee_m10n_add_credit.product_manager');
      $organization_id = $this->getApigeeEdgeSdkConnector()->getOrganization();
      $client = $this->getApigeeEdgeSdkConnector()->getClient();

      if (\Drupal::service('apigee_m10n.monetization')->isOrganizationApigeeXorHybrid()) {
        $supported_currency_controller = new ApigeeXSupportedCurrencyController($organization_id, $client);
      }
      else {
        $supported_currency_controller = new SupportedCurrencyController($organization_id, $client);
      }

      // Filter out currencies with products.
      $this->supportedCurrencies = array_filter($supported_currency_controller->getEntities(), function (SupportedCurrencyInterface $currency) {
        return !$this->addCreditProductManager->getProductForCurrency($currency->id()) && $this->isCurrencyImportable($currency);
      });
    }
    catch (\Exception $exception) {
      watchdog_exception('apigee_kickstart', $exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    if (!empty($this->supportedCurrencies)) {
      $currency_options = [];
      foreach ($this->supportedCurrencies as $currency) {
        if ($this->isCurrencyImportable($currency)) {
          $currency_options[$currency->getName()] = "{$currency->getDisplayName()} ({$currency->getName()})";
        }
      }

      $form['supported_currencies'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Supported currencies'),
        '#description' => $this->t('Create a product to add credit for the following supported currencies.'),
        '#options' => $currency_options,
        '#required' => TRUE,
        '#default_value' => array_keys($currency_options),
      ];

      /** @var \Drupal\commerce_store\Entity\StoreInterface[] $stores */
      $store_storage = $this->getEntityTypeManager()->getStorage('commerce_store');
      $stores = $store_storage->loadMultiple();
      $default_store = $store_storage->loadDefault();
      $store_options = [];
      foreach ($stores as $store) {
        $store_options[$store->id()] = $store->label();
      }
      $form['store'] = [
        '#type' => 'radios',
        '#title' => $this->t('Store'),
        '#options' => $store_options,
        '#required' => TRUE,
        '#default_value' => $default_store ? $default_store->id() : NULL,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $currencies = $form_state->getValue('supported_currencies');
    $store = $form_state->getValue('store');

    /** @var \Apigee\Edge\Api\Monetization\Entity\SupportedCurrencyInterface $currency */
    foreach ($currencies as $currency_code) {
      if (!$currency_code) {
        continue;
      }

      try {
        $currency = $this->supportedCurrencies[strtolower($currency_code)];
        $minimum_amount = (string) $currency->getMinimumTopUpAmount();
        $currency_code = $currency->getName();
        // Create a product variation for this currency.
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
        $variation = $this->getEntityTypeManager()
          ->getStorage('commerce_product_variation')
          ->create([
            'type' => 'add_credit',
            'sku' => "ADD-CREDIT-{$currency->getName()}",
            'title' => $currency->getName(),
            'status' => 1,
            'price' => new Price($minimum_amount, $currency_code),
          ]);
        $variation->set('apigee_price_range', [
          'minimum' => $minimum_amount,
          'maximum' => 999,
          'default' => $minimum_amount,
          'currency_code' => $currency_code,
        ]);
        $variation->save();

        // Create an add credit product for this currency.
        $product = $this->getEntityTypeManager()->getStorage('commerce_product')
          ->create([
            'title' => $currency->getName(),
            'type' => 'add_credit',
            'stores' => [$store],
            'variations' => [$variation],
            AddCreditConfig::ADD_CREDIT_ENABLED_FIELD_NAME => 1,
          ]);
        $product->save();

        // Save config.
        $this->getConfigFactory()
          ->getEditable(AddCreditConfig::CONFIG_NAME)
          ->set('products.' . $currency->getId(), [
            'product_id' => $product->id(),
          ])
          ->save();
      }
      catch (\Exception $exception) {
        watchdog_exception('apigee_kickstart', $exception);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(): bool {
    return $this->getModuleHandler()->moduleExists('apigee_m10n_add_credit');
  }

  /**
   * {@inheritdoc}
   */
  public function isCompleted(): bool {
    return empty($this->supportedCurrencies);
  }

  /**
   * Helper to get importable currencies.
   *
   * @return array|\CommerceGuys\Intl\Currency\Currency[]
   *   An array of importable currencies.
   */
  protected function getImportableCurrencies(): array {
    $language = $this->languageManager->getConfigOverrideLanguage() ?: $this->languageManager->getCurrentLanguage();
    return $this->currencyRepository->getAll($language->getId());
  }

  /**
   * Determines if a currency is importable.
   *
   * @param \Apigee\Edge\Api\Monetization\Entity\SupportedCurrencyInterface $currency
   *   The supported currency entity.
   *
   * @return bool
   *   TRUE is currency is importable. FALSE otherwise.
   */
  protected function isCurrencyImportable(SupportedCurrencyInterface $currency): bool {
    return $currency->getStatus() === 'ACTIVE' && isset($this->importableCurrencies[$currency->getName()]);
  }

}
