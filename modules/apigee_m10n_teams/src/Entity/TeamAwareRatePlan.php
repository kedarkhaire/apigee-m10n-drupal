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

namespace Drupal\apigee_m10n_teams\Entity;

use Drupal\apigee_edge_teams\Entity\TeamInterface;
use Drupal\apigee_m10n\Entity\RatePlan;
use Drupal\apigee_m10n_teams\Entity\Traits\TeamRouteAwarePropertyTrait;

/**
 * Overridden team aware class for the `rate_plan` entity.
 */
class TeamAwareRatePlan extends RatePlan {

  use TeamRouteAwarePropertyTrait;

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = 'canonical', array $options = []) {
    // The string formatter assumes entities are revisionable.
    $rel = ($rel === 'revision') ? 'canonical' : $rel;

    // Check for team context.
    $rel = (($team_id = $this->getTeamId()) && $rel === 'canonical') ? 'team' : $rel;

    $url = parent::toUrl($rel, $options);

    // Add the team if this is a team URL.
    if ($rel == 'team' &&  !empty($team_id)) {
      $url->setRouteParameter('team', $team_id);
    }

    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubscribe():? array {
    // Return a team array if this is a team route.
    return (($team = $this->getTeam()) && $team instanceof TeamInterface)
      ? ['team' => $team]
      : parent::getSubscribe();
  }

}
