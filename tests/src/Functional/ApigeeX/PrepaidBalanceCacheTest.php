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

namespace Drupal\Tests\apigee_m10n\Functional\ApigeeX;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\apigee_m10n\Controller\PrepaidBalanceXController;
use Drupal\apigee_m10n\Form\PrepaidBalanceConfigForm;
use Drupal\apigee_m10n\Form\PrepaidBalanceRefreshForm;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Functional test for prepaid balance cache.
 *
 * @group apigee_m10n
 * @group apigee_m10n_functional
 */
class PrepaidBalanceCacheTest extends MonetizationFunctionalTestBase {

  use AssertPageCacheContextsAndTagsTrait;

  /**
   * The max age for the prepaid balance cache.
   */
  const CACHE_MAX_AGE = 60;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * {@inheritdoc}
   */
  protected $developer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->developer = $this->createAccount([
      'view own prepaid balance',
    ]);
    $this->drupalLogin($this->developer);

    // Enable prepaid balance cache.
    $this->config(PrepaidBalanceConfigForm::CONFIG_NAME)
      ->set('cache.max_age', static::CACHE_MAX_AGE)
      ->save();

    $this->cacheBackend = $this->container->get('cache.default');
  }

  /**
   * Test user with no refresh prepaid balance permission..
   *
   * @throws \Exception
   */
  public function testNoPermission() {
    $authenticated_role = Role::load(AccountInterface::AUTHENTICATED_ROLE);
    $authenticated_role->revokePermission('refresh own prepaid balance');
    $authenticated_role->save();

    // User cannot refresh prepaid balance.
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->stack->queueMockResponse([
      'get-apigeex-prepaid-balances',
    ]);
    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));

    $this->assertSession()->responseContains('Prepaid balance');
    $this->assertSession()->responseNotContains('Refresh');
  }

  /**
   * Test user with "refresh own prepaid balance" permission.
   */
  public function testRefreshOwnPrepaidBalancePermission() {
    // Given a user with the 'refresh own prepaid balance permission'.
    $user_roles = $this->developer->getRoles();
    $this->grantPermissions(Role::load(reset($user_roles)), ['refresh own prepaid balance']);

    // User can refresh own account.
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);

    $this->queueResponses($this->developer);

    $this->assertRefreshPrepaidBalanceForUser($this->developer);
  }

  /**
   * Test user with "refresh any prepaid balance" permission.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testRefreshAnyPrepaidBalancePermission() {
    // Given a user with "refresh any prepaid balance" permission.
    $user_roles = $this->developer->getRoles();
    $this->grantPermissions(Role::load(reset($user_roles)), ['refresh any prepaid balance', 'view any prepaid balance']);

    // User can refresh another user account.
    $other_user = $this->createAccount();
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($other_user);
    $this->setBillingType($other_user);

    $this->queueResponses($this->developer);

    $this->assertRefreshPrepaidBalanceForUser($other_user);
  }

  /**
   * Test if the prepaid balances Ids are properly cached.
   */
  public function testPrepaidBalanceCacheIds() {
    $user_roles = $this->developer->getRoles();
    $this->grantPermissions(Role::load(reset($user_roles)), ['refresh own prepaid balance']);

    // Visit the prepaid balance page.
    $expected_expiration_time = time() + static::CACHE_MAX_AGE;
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);

    $this->queueResponses($this->developer);

    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));
    $this->assertCacheIdsExist($this->getCacheIds());

    // Check if max age is properly set.
    $this->assertCacheIdsExpire($this->getCacheIds(), $expected_expiration_time);
  }

  /**
   * Test if the prepaid balances Ids are properly rebuilt.
   */
  public function testPrepaidBalanceCacheIdsRebuild() {
    // Visit the prepaid balance page to set the caches.
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);

    $this->queueResponses($this->developer);
    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));

    $cache_before = [];
    foreach ($this->getCacheIds() as $cid) {
      $cache_before[$cid] = $this->cacheBackend->get($cid);
      $this->cacheBackend->delete($cid);
    }

    // Visit prepaid balance to rebuild caches.
    $this->queueResponses($this->developer);
    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));

    foreach ($this->getCacheIds() as $cid) {
      $cache_after = $this->cacheBackend->get($cid);
      $this->assertGreaterThan($cache_before[$cid]->created, $cache_after->created);
    }
  }

  /**
   * Tests the response cache tags.
   */
  public function testPrepaidBalanceCacheTags() {
    $this->checkDriverHeaderSupport();

    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);
    $this->queueResponses($this->developer);
    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));
    $expected_tags = Cache::mergeTags(PrepaidBalanceXController::getCacheTags($this->developer), ['rendered']);
    $this->assertCacheTags($expected_tags);
  }

  /**
   * Test if cache can be disabled.
   */
  public function testPrepaidBalanceCacheDisable() {
    // Disable the cache.
    $this->config(PrepaidBalanceConfigForm::CONFIG_NAME)
      ->set('cache.max_age', 0)
      ->save();

    // Visit the prepaid balance page.
    $this->warmApigeexOrganizationCache();

    $this->queueApigeexDeveloperResponse($this->developer);
    $this->setBillingType($this->developer);
    $this->queueResponses($this->developer);
    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $this->developer->id(),
    ]));
    $this->assertCacheIdsNotExist($this->getCacheIds());
  }

  /**
   * Helper to queue mock responses.
   */
  protected function queueResponses(UserInterface $user) {
    $this->queueApigeexDeveloperResponse($user);
    $this->stack->queueMockResponse([
      'get-apigeex-prepaid-balances',
    ]);
  }

  /**
   * Returns an array of cache ids.
   *
   * @return array
   *   An array of cache ids.
   */
  protected function getCacheIds() {
    return [
      PrepaidBalanceXController::getCacheId($this->developer, 'prepaid_balances'),
    ];
  }

  /**
   * Asserts current user can refresh account for given user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  protected function assertRefreshPrepaidBalanceForUser(UserInterface $user) {

    $this->drupalGet(Url::fromRoute('apigee_monetization.xbilling', [
      'user' => $user->id(),
    ]));
    $this->assertSession()->responseContains('Prepaid balance');
    $this->submitForm([], 'Refresh');

    $this->assertSession()
      ->responseContains(PrepaidBalanceRefreshForm::SUCCESS_MESSAGE);
  }

  /**
   * Check if cache with given IDs exist.
   *
   * @param array $cids
   *   An array of cache IDs.
   */
  protected function assertCacheIdsExist(array $cids) {
    foreach ($cids as $cid) {
      $this->assertNotEmpty($this->cacheBackend->get($cid));
    }
  }

  /**
   * Check if cache with given IDs do not exist.
   *
   * @param array $cids
   *   An array of cache IDs.
   */
  protected function assertCacheIdsNotExist(array $cids) {
    foreach ($cids as $cid) {
      $this->assertFalse($this->cacheBackend->get($cid));
    }
  }

  /**
   * Check the expiration time for cache with given IDs.
   *
   * @param array $cids
   *   An array of cache IDs.
   * @param int $expected
   *   The expected cache expiration time.
   */
  protected function assertCacheIdsExpire(array $cids, int $expected) {
    foreach ($cids as $cid) {
      if ($cache = $this->cacheBackend->get($cid)) {
        // The cache expiration must be greater or equal to the expected time.
        $this->assertGreaterThanOrEqual($expected, $cache->expire);
      }
    }
  }

}
