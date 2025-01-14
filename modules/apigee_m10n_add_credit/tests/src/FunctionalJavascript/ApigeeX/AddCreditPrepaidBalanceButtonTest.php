<?php

/*
 * Copyright 2021 Google Inc.
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

namespace Drupal\Tests\apigee_m10n_add_credit\FunctionalJavascript\ApigeeX;

use Drupal\Core\Url;
use Drupal\apigee_m10n_add_credit\AddCreditConfig;
use Drupal\commerce_product\Entity\ProductType;

/**
 * Tests the add credit button on the prepaid balance page.
 *
 * This is tested as a FunctionalJavascript test to test the modal.
 *
 * @group apigee_m10n
 * @group apigee_m10n_functional
 * @group apigee_m10n_add_credit
 * @group apigee_m10n_add_credit_functional
 */
class AddCreditPrepaidBalanceButtonTest extends AddCreditFunctionalJavascriptTestBase {

  /**
   * A developer user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $developer;

  /**
   * A test product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->warmApigeexOrganizationCache();
    $this->stack->queueMockResponse(['post-apigeex-billing-type']);

    $this->developer = $this->signIn([
      'view own prepaid balance',
      'add credit to own developer prepaid balance',
    ]);

    // Enable add credit for the product type.
    $product_type = ProductType::load('default');
    $product_type->setThirdPartySetting('apigee_m10n_add_credit', 'apigee_m10n_enable_add_credit', 1);
    $product_type->save();

    // Create a product.
    $variation = $this->createCommerceProductVariation();
    $this->product = $this->createCommerceProduct($this->createCommerceStore(), $variation);

    // Enable the add credit target field on the add to cart form.
    $this->container->get('entity_type.manager')
      ->getStorage('entity_form_display')
      ->load('commerce_order_item.default.add_to_cart')
      ->setComponent(AddCreditConfig::TARGET_FIELD_NAME, [
        'region' => 'content',
        'type' => 'add_credit_target_entity',
      ])
      ->save();
  }

  /**
   * Tests the add credit button on prepaid balance.
   */
  public function testAddCreditButton() {
    $this->setAddCreditProductForCurrencyId($this->product, 'usd');

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->stack->queueMockResponse(['get-apigeex-billing-type']);

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->stack->queueMockResponse([
      'get-apigeex-prepaid-balances',
    ]);

    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));
    $this->click('.add-credit--usd.dropbutton a');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertCssElementContains('.ui-dialog-title', $this->product->label());
    $this->assertCssElementContains('select[name="' . AddCreditConfig::TARGET_FIELD_NAME . '"]', "{$this->developer->get('first_name')->value} {$this->developer->get('last_name')->value}");
  }

}
