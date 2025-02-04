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

namespace Drupal\apigee_m10n\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface;
use Drupal\apigee_m10n\Entity\XRatePlan;
use Drupal\apigee_m10n\Form\RatePlanXConfigForm;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Generates the Buy api page.
 */
class BuyApiController extends ControllerBase {

  /**
   * Service for instantiating SDK controllers.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface
   */
  protected $controller_factory;

  /**
   * BuyApiController constructor.
   *
   * @param \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface $sdk_controller_factory
   *   The SDK controller factory.
   */
  public function __construct(ApigeeSdkControllerFactoryInterface $sdk_controller_factory) {
    $this->controller_factory = $sdk_controller_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('apigee_m10n.sdk_controller_factory'));
  }

  /**
   * Checks route access.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Grants access to the route if passed permissions are present.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account) {
    $user = $route_match->getParameter('user');
    return AccessResult::allowedIf(
      $account->hasPermission('view rate_plan as anyone') ||
      ($account->hasPermission('view rate_plan') && $account->id() === $user->id())
    );
  }

  /**
   * Gets a list of available xproduct for this user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The drupal user/developer.
   *
   * @return array
   *   The pager render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function xcatalogPage(UserInterface $user) {
    // A developer email is required.
    if (empty($user->getEmail())) {
      throw new NotFoundHttpException((string) $this->t("The user (@uid) doesn't have an email address.", ['@uid' => $user->id()]));
    }

    $rate_plans = [];
    $subscription_handler = \Drupal::entityTypeManager()->getHandler('xrate_plan', 'subscription_access');

    // Get the active rate plans of the organization.
    $all_ratePlans = XRatePlan::loadAll();

    foreach ($all_ratePlans as $rate_plan) {
      if ($subscription_handler->access($rate_plan, $user) == AccessResult::allowed()) {
        $rate_plans["{$rate_plan->id()}"] = $rate_plan;
      }
    }

    return $this->buildPage($rate_plans);
  }

  /**
   * Builds the page for a rate plan listing.
   *
   * @param \Drupal\apigee_m10n\Entity\XRatePlanInterface[] $plans
   *   A list of rate plans.
   *
   * @return array
   *   A render array for plans.
   */
  protected function buildPage($plans) {
    $build = [
      '#theme' => 'container__pricing_and_plans',
      '#children' => [],
      '#attributes' => ['class' => ['pricing-and-plans']],
      '#cache' => [
        'contexts' => [
          'user',
          'url.developer',
        ],
        'tags' => [],
        'max-age' => 300,
      ],
      '#attached' => ['library' => ['apigee_m10n/rate_plan.entity_list']],
    ];

    // Get the view mode from product bundle config.
    $view_mode = ($view_mode = $this->config(RatePlanXConfigForm::CONFIG_NAME)->get('catalog_view_mode')) ? $view_mode : 'default';
    $view_builder = $this->entityTypeManager()->getViewBuilder('xrate_plan');

    foreach ($plans as $id => $plan) {
      // @todo Add a test for render cache.
      $build['#cache']['tags'] = Cache::mergeTags($build['#cache']['tags'], $plan->getCacheTags());
      // Generate a build array using the view builder.
      $build['#children'][$id] = $view_builder->view($plan, $view_mode);
      $build['#children'][$id]['#theme_wrappers'] = ['container__pricing_and_plans__item' => ['#attributes' => ['class' => ['pricing-and-plans__item']]]];

    }

    return $build;
  }

}
