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

namespace Drupal\Tests\apigee_m10n\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\apigee_m10n\Traits\ApigeeMonetizationTestTrait;

/**
 * A base class for functional tests.
 */
class MonetizationFunctionalTestBase extends BrowserTestBase {

  use ApigeeMonetizationTestTrait {
    setUp as baseSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'apigee_m10n_test',
    'apigee_mock_api_client',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create new Apigee Edge basic auth key.
    $this->baseSetUp();
  }

}
