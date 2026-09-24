<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The two OAuth endpoints, declared rather than imported.
 *
 * The bundle's own config/routes.php mounts three routes AT THE ROOT — /authorize, /token and
 * /device-code. Two problems with importing it as the recipe does:
 *
 *   1. At the root, no `access_control` rule and no firewall pattern written for `^/oauth/`
 *      would cover them, and `security.php`'s catch-all `^/` would be the only thing that did.
 *   2. /device-code would be a live route in front of a grant this server disables, answering
 *      an error at best.
 *
 * So the two we serve are declared here, pointing at the bundle's controllers.
 */
return static function (RoutingConfigurator $routes): void {
    $routes
        ->add('oauth2_authorize', '/oauth/authorize')
        ->controller(['league.oauth2_server.controller.authorization', 'indexAction'])
        ->methods(['GET'])

        ->add('oauth2_token', '/oauth/token')
        ->controller(['league.oauth2_server.controller.token', 'indexAction'])
        ->methods(['POST'])
    ;
};
