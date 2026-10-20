#!/usr/bin/env php
<?php

// Do not boot the kernel: an unwritable var directory must not prevent its own
// diagnosis, and a cold container must not create a cache just to inspect paths.
require dirname(__DIR__).'/vendor/autoload.php';

$application = new Symfony\Component\Console\Application('Upgrade preflight');
$application->add(new App\Command\UpgradePreflightCommand(
    new App\Service\UpgradePreflightService(dirname(__DIR__)),
));
$application->setDefaultCommand('app:upgrade:preflight', true);
$application->run();
