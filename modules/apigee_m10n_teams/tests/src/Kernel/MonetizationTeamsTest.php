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

namespace Drupal\Tests\apigee_m10n_teams\Kernel;

use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\apigee_m10n\Traits\AccountProphecyTrait;
use Drupal\Tests\apigee_m10n_teams\Traits\TeamProphecyTrait;
use Drupal\apigee_edge_teams\Entity\Team;
use Drupal\apigee_edge_teams\TeamPermissionHandlerInterface;
use Drupal\apigee_m10n\Entity\ProductBundle;
use Drupal\apigee_m10n_teams\Entity\Storage\TeamProductBundleStorageInterface;
use Drupal\apigee_m10n_teams\Entity\Storage\TeamPurchasedPlanStorageInterface;
use Drupal\apigee_m10n_teams\Entity\TeamProductBundleInterface;
use Drupal\apigee_m10n_teams\Entity\TeamsPurchasedPlanInterface;
use Drupal\apigee_m10n_teams\Entity\TeamsRatePlan;
use Drupal\apigee_m10n_teams\Plugin\Field\FieldFormatter\TeamPurchasePlanFormFormatter;
use Drupal\apigee_m10n_teams\Plugin\Field\FieldFormatter\TeamPurchasePlanLinkFormatter;
use Drupal\apigee_m10n_teams\Plugin\Field\FieldWidget\CompanyTermsAndConditionsWidget;

/**
 * Tests the module affected overrides are overridden properly.
 *
 * @group apigee_m10n
 * @group apigee_m10n_kernel
 * @group apigee_m10n_teams
 * @group apigee_m10n_teams_kernel
 */
class MonetizationTeamsTest extends KernelTestBase {

  use AccountProphecyTrait;
  use TeamProphecyTrait;

  /**
   * A test team.
   *
   * @var \Drupal\apigee_edge_teams\Entity\TeamInterface
   */
  protected $team;

  /**
   * A test product bundle.
   *
   * @var \Drupal\apigee_m10n\Entity\ProductBundleInterface
   */
  protected $product_bundle;

  /**
   * A test rate plan.
   *
   * @var \Drupal\apigee_m10n\Entity\RatePlanInterface
   */
  protected $rate_plan;

  /**
   * A test purchase plan.
   *
   * @var \Drupal\apigee_m10n\Entity\PurchasedPlanInterface
   */
  protected $purchased_plan;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_type_manager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'key',
    'user',
    'apigee_edge',
    'apigee_edge_teams',
    'apigee_m10n',
    'apigee_m10n_teams',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Setting teams cache to 900 to make sure null value is not returned in getCacheMaxAge().
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('apigee_edge_teams.team_settings');

    if (NULL === $config->get('cache_expiration')) {
      $config->set('cache_expiration', 900);
      $config->save(TRUE);
    }
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $this->entity_type_manager = $this->container->get('entity_type.manager');

    // Create a team Entity.
    $this->team = Team::create(['name' => strtolower($this->randomMachineName(8) . '-' . $this->randomMachineName(4))]);

    $this->product_bundle = $this->entity_type_manager->getStorage('product_bundle')->create([
      'id' => $this->randomMachineName(),
    ]);
    $this->rate_plan = $this->entity_type_manager->getStorage('rate_plan')->create([
      'id' => $this->randomMachineName(),
      'package' => $this->product_bundle->decorated(),
    ]);
    $this->purchased_plan = $this->entity_type_manager->getStorage('purchased_plan')->create([
      'id' => $this->randomMachineName(),
      'rate_plan' => $this->rate_plan,
    ]);
  }

  /**
   * Runs all of the assertions in this test suite.
   */
  public function testAll() {
    $this->assertCurrentTeam();
    $this->assertEntityAlter();
    $this->assertEntityAccess();
    $this->assertEntityLinks();
    $this->assertFieldOverrides();
  }

  /**
   * Make sure the monetization service returns the current team.
   */
  public function assertCurrentTeam() {
    $this->setCurrentTeamRoute($this->team);
    static::assertSame($this->team, \Drupal::service('apigee_m10n.teams')->currentTeam());
  }

  /**
   * Tests entity classes are overridden.
   */
  public function assertEntityAlter() {
    // Check class overrides.
    static::assertInstanceOf(TeamProductBundleInterface::class, $this->product_bundle);
    static::assertInstanceOf(TeamsRatePlan::class, $this->rate_plan);
    static::assertInstanceOf(TeamsPurchasedPlanInterface::class, $this->purchased_plan);

    // Check storage overrides.
    static::assertInstanceOf(TeamProductBundleStorageInterface::class, $this->entity_type_manager->getStorage('product_bundle'));
    static::assertInstanceOf(TeamPurchasedPlanStorageInterface::class, $this->entity_type_manager->getStorage('purchased_plan'));
  }

  /**
   * Tests team entity access.
   */
  public function assertEntityAccess() {
    // Mock an account.
    $account = $this->prophesizeAccount();
    $non_member = $this->prophesizeAccount();

    // Prophesize the `apigee_edge_teams.team_permissions` service.
    $team_handler = $this->prophesize(TeamPermissionHandlerInterface::class);
    $team_handler->getDeveloperPermissionsByTeam($this->team, $account)->willReturn([
      'view product_bundle',
      'view rate_plan',
      'purchase rate_plan',
    ]);
    $team_handler->getDeveloperPermissionsByTeam($this->team, $non_member)->willReturn([]);
    $this->container->set('apigee_edge_teams.team_permissions', $team_handler->reveal());

    // Create an entity we can test against `entityAccess`.
    $entity_id = strtolower($this->randomMachineName(8) . '-' . $this->randomMachineName(4));
    // We are only using product bundle here because it's easy.
    $product_bundle = ProductBundle::create(['id' => $entity_id]);

    // Test view product bundle for a team member.
    static::assertTrue($product_bundle->access('view', $account));
    // Test view product bundle for a non team member.
    static::assertFalse($product_bundle->access('view', $non_member));

    // Populate the entity cache with the rate plan's API product bundle because
    // it will be loaded when the rate plan cache tags are loaded.
    \Drupal::service('entity.memory_cache')->set("values:product_bundle:{$this->product_bundle->id()}", $this->product_bundle);

    // Test view rate plan for a team member.
    static::assertTrue($this->rate_plan->access('view', $account));
    // Test view rate plan for a non team member.
    static::assertFalse($this->rate_plan->access('view', $non_member));

    // Test view rate plan for a team member.
    static::assertTrue($this->rate_plan->access('purchase', $account));
    // Test view rate plan for a non team member.
    static::assertFalse($this->rate_plan->access('purchase', $non_member));
  }

  /**
   * Make sure the monetization service returns the current team.
   */
  public function assertEntityLinks() {
    // Product bundle team url.
    static::assertSame("/teams/{$this->team->id()}/monetization/product-bundle/{$this->product_bundle->id()}", $this->product_bundle->toUrl('team')->toString());
    // Rate plan team url.
    static::assertSame("/teams/{$this->team->id()}/monetization/product-bundle/{$this->product_bundle->id()}/plan/{$this->rate_plan->id()}", $this->rate_plan->toUrl('team')->toString());
    static::assertSame("/teams/{$this->team->id()}/monetization/product-bundle/{$this->product_bundle->id()}/plan/{$this->rate_plan->id()}/purchase", $this->rate_plan->toUrl('team-purchase')->toString());
    // Team purchased plan URLs.
    static::assertSame("/teams/{$this->team->id()}/monetization/purchased-plans", Url::fromRoute('entity.purchased_plan.team_collection', ['team' => $this->team->id()])->toString());
    static::assertSame("/teams/{$this->team->id()}/monetization/purchased-plan/{$this->purchased_plan->id()}/cancel", Url::fromRoute('entity.purchased_plan.team_cancel_form', [
      'team' => $this->team->id(),
      'purchased_plan' => $this->purchased_plan->id(),
    ])->toString());
    static::assertSame("/teams/{$this->team->id()}/monetization/purchased-plans", Url::fromRoute('entity.purchased_plan.team_collection', [
      'team' => $this->team->id(),
    ])->toString());
  }

  /**
   * Make sure fields were overridden.
   */
  public function assertFieldOverrides() {
    /** @var \Drupal\Core\Field\FormatterPluginManager $formatter_manager */
    $formatter_manager = \Drupal::service('plugin.manager.field.formatter');
    /** @var \Drupal\Core\Field\WidgetPluginManager $widget_manager */
    $widget_manager = \Drupal::service('plugin.manager.field.widget');

    // Confirm formatter overrides.
    static::assertSame(TeamPurchasePlanFormFormatter::class, $formatter_manager->getDefinition('apigee_purchase_plan_form')['class']);
    static::assertSame(TeamPurchasePlanLinkFormatter::class, $formatter_manager->getDefinition('apigee_purchase_plan_link')['class']);

    // Confirm widget overrides.
    static::assertSame(CompanyTermsAndConditionsWidget::class, $widget_manager->getDefinition('apigee_tnc_widget')['class']);
  }

}
