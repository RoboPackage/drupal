<?php

declare(strict_types=1);

namespace RoboPackage\Drupal\Plugin\RoboPackage\Executable;

use RoboPackage\Core\Plugin\ExecutablePluginBase;
use RoboPackage\Core\Attributes\ExecutablePluginMetadata;

#[ExecutablePluginMetadata(
    id: 'drush',
    label: 'Drush',
    binary: 'drush'
)]
class Drush extends ExecutablePluginBase
{
}
