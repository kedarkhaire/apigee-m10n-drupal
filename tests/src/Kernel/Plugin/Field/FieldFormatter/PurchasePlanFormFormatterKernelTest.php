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
use Drupal\apigee_m10n\Plugin\Field\FieldFormatter\PurchasePlanFormFormatter;
use Drupal\apigee_m10n\Plugin\Field\FieldType\PurchaseFieldItem;

/**
 * Test the `apigee_purchase_plan_link` field formatter.
 *
 * @group apigee_m10n
 * @group apigee_m10n_kernel
 */
class PurchasePlanFormFormatterKernelTest extends MonetizationKernelTestBase {

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
   * Drupal user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $developer;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('date_format');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'user',
      'system',
    ]);

    $this->developer = $this->createAccount([
      'view own purchased_plan',
    ]);
    $this->setCurrentUser($this->developer);

    $this->formatter_manager = $this->container->get('plugin.manager.field.formatter');
    $this->field_manager = $this->container->get('entity_field.manager');

    $this->product_bundle = $this->createProductBundle();
    $this->rate_plan = $this->createRatePlan($this->product_bundle);
  }

  /**
   * Test viewing a purchase form formatter.
   *
   * @throws \Exception
   */
  public function testView() {
    $this->stack->queueMockResponse([
      'get_terms_conditions',
      'get_developer_terms_conditions',
    ]);

    $item_list = $this->rate_plan->get('purchase');
    static::assertInstanceOf(FieldItemList::class, $item_list);
    static::assertInstanceOf(PurchaseFieldItem::class, $item_list->get(0));
    static::assertSame(\Drupal::currentUser()->id(), $item_list->get(0)->user->id());
    /** @var \Drupal\apigee_m10n\Plugin\Field\FieldFormatter\PurchasePlanFormFormatter $instance */
    $instance = $this->formatter_manager->createInstance('apigee_purchase_plan_form', [
      'field_definition' => $this->field_manager->getBaseFieldDefinitions('rate_plan')['purchase'],
      'settings' => [
        'label' => 'Purchase',
      ],
      'label' => TRUE,
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    static::assertInstanceOf(PurchasePlanFormFormatter::class, $instance);

    // Render the field item.
    $build = $instance->view($item_list);

    static::assertSame('Purchase', (string) $build['#title']);
    static::assertTrue($build['#label_display']);

    $this->render($build);

  }

}
