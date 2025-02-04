<?php

/**
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

namespace Drupal\Tests\apigee_m10n\Functional\ApigeeX;

use Drupal\Core\Url;

/**
 * Class PurchasedProductListTest.
 *
 * @group apigee_m10n
 * @group apigee_m10n_functional
 */
class PurchasedProductListTest extends MonetizationFunctionalTestBase {

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
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   */
  protected function setUp(): void {
    parent::setUp();

    // Warm the ApigeeX organization.
    $this->warmApigeexOrganizationCache();

    // If the user doesn't have the "view own purchased_product" permission, they
    // should get access denied.
    $this->developer = $this->createAccount([]);

    $this->drupalLogin($this->developer);
  }

  /**
   * Tests for `My Plans` page.
   *
   * @throws \Exception
   */
  public function testPurchasedPlanListView() {
    // Warm the ApigeeX organization.
    $this->warmApigeexOrganizationCache();

    $xproduct = $this->createApigeexProduct();
    $xrate_plan = $this->createRatePlan($xproduct);
    $purchased_product = $this->createPurchasedProduct($this->developer, $xrate_plan);

    $this->stack->queueMockResponse([
      'get_developer_purchased_products' => [
        'purchased_products' => [$purchased_product],
      ],
    ])->queueMockResponse([
      'get_monetization_apigeex_plans' => [
        'plans' => [$xrate_plan],
      ],
    ]);

    $this->drupalGet(Url::fromRoute('entity.purchased_product.developer_product_collection', [
      'user' => $this->developer->id(),
    ]));

    // Make sure user has access to the page.
    $this->assertSession()->responseNotContains('Access denied');
    $this->assertSession()->responseNotContains('Connection error');

    $default_timezone = new \DateTimeZone('UTC');
    $datetimeImmutable = new \DateTimeImmutable();
    $start_time = $datetimeImmutable->setTimezone($default_timezone)->setTimestamp((int) ($purchased_product->getStartTime() / 1000))->format('m/d/Y');

    // Checking my purchased plans table columns.
    $this->assertCssElementText('.purchased-plan-row:nth-child(1) td.purchased-plan-status', 'Active');
    $this->assertCssElementText('.purchased-plan-row:nth-child(1) td.purchased-subscription', $purchased_product->getName());
    $this->assertCssElementText('.purchased-plan-row:nth-child(1) td.purchased-plan-rate-plan', $purchased_product->getApiProduct());
    $this->assertCssElementText('.purchased-plan-row:nth-child(1) td.purchased-plan-start-date', $start_time);

  }

}
