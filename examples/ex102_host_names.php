<?php

/**
 * Running a server on an IP address without a name (e.g. "127.0.0.1") is useful for development
 * and one-off testing environments, but real-world apps need host names. App configuration objects
 * use the `\Aerys\Framework\App::setName()` method to specify host names.
 *
 * This example serves two separate domains (http://aerys and http://subdomain.aerys). Note that for
 * this code to work as expected you'll need to have the two domains pointing at your local machine.
 * In *nix this is done in the `/etc/hosts` file. In windows you'll instead want to set the same
 * line in your `%systemroot%\system32\drivers\etc` file:
 *
 *     127.0.0.1     localhost aerys subdomain.aerys
 *
 * Your Aerys servers may specify as many virtual host names as needed. The code below serves simple
 * dynamic responses from the primary host (http://aerys) and static files for all requests to the
 * secondary (http://subdomain.aerys) host.
 *
 * To run this example:
 *
 *     $ bin/aerys -c examples/ex102_host_names.php
 *
 * Once started, load http://aerys/ or http://subdomain.aerys/ in your browser.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dynamicAppResponder = function() {
    return '<html><body><h1>Hello from http://aerys</h1></body></html>';
};

$mainHost = (new Aerys\Framework\App)
    ->setPort(80)
    ->setName('aerys')
    ->addResponder($dynamicAppResponder)
;

$subdomainHost = (new Aerys\Framework\App)
    ->setPort(80)
    ->setName('subdomain.aerys')
    ->setDocumentRoot(__DIR__ . '/support/docroot')
;