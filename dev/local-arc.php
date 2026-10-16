<?php

/**
 * Points DDEV at a local Arc portal and API.
 *
 * The browser still uses localhost. PHP inside the container reaches the
 * host through host.docker.internal. This file is not part of the packaged
 * module.
 */

declare(strict_types=1);

$settings['pendulum_arc_portal_url'] = 'http://localhost:3001';
$settings['pendulum_arc_api_url'] = 'http://localhost:8090';
$settings['pendulum_arc_allow_http_local'] = TRUE;
$settings['pendulum_arc_rewrite_localhost'] = TRUE;
