<?php

/*
 * Copyright 2018 Google Inc.
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

namespace Drupal\Tests\apigee_m10n\Kernel\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemList;
use Drupal\Tests\apigee_m10n\Kernel\MonetizationKernelTestBase;
use Drupal\apigee_m10n\Plugin\Field\FieldFormatter\SupportedCurrencyFormatter;
use Drupal\apigee_m10n\Plugin\Field\FieldType\SupportedCurrencylFieldItem;

/**
 * Test the `apigee_currency` field formatter.
 *
 * @group apigee_m10n
 * @group apigee_m10n_kernel
 */
class SupportedCurrencyFormatterKernelTest extends MonetizationKernelTestBase {

  /**
   * The formatter manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatter_manager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $field_manager;

  /**
   * Test product bundle.
   *
   * @var \Drupal\apigee_m10n\Entity\ProductBundleInterface
   */
  protected $product_bundle;

  /**
   * Test rate plan.
   *
   * @var \Drupal\apigee_m10n\Entity\RatePlanInterface
   */
  protected $rate_plan;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUp(): void {
    parent::setUp();

    $this->formatter_manager = $this->container->get('plugin.manager.field.formatter');
    $this->field_manager = $this->container->get('entity_field.manager');

    $this->product_bundle = $this->createProductBundle();
    $this->rate_plan = $this->createRatePlan($this->product_bundle);
  }

  /**
   * Test viewing a product bundle.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function testView() {
    $item_list = $this->rate_plan->get('currency');
    static::assertInstanceOf(FieldItemList::class, $item_list);
    static::assertInstanceOf(SupportedCurrencylFieldItem::class, $item_list->get(0));
    static::assertSame($this->rate_plan->getCurrency()->id(), $item_list->get(0)->value->id());
    /** @var \Drupal\apigee_m10n\Plugin\Field\FieldFormatter\SupportedCurrencyFormatter $instance */
    $instance = $this->formatter_manager->createInstance('apigee_currency', [
      'field_definition' => $this->field_manager->getBaseFieldDefinitions('rate_plan')['currency'],
      'settings' => [],
      'label' => TRUE,
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    static::assertInstanceOf(SupportedCurrencyFormatter::class, $instance);

    // Render the field item.
    $build = $instance->view($item_list);

    static::assertSame('Currency', (string) $build['#title']);
    static::assertTrue($build['#label_display']);
    static::assertSame($this->rate_plan->getCurrency()->getName(), (string) $build[0]['#markup']);

    $this->render($build);
    $this->assertText($this->rate_plan->getCurrency()->getName());

  }

}
