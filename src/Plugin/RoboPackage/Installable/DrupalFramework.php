<?php

declare(strict_types=1);

namespace RoboPackage\Drupal\Plugin\RoboPackage\Installable;

use Psr\Container\ContainerInterface;
use Robo\Contract\ConfigAwareInterface;
use RoboPackage\Core\QuestionValidators;
use RoboPackage\Core\Contract\PluginInterface;
use RoboPackage\Core\Traits\DatabaseCommandTrait;
use RoboPackage\Core\Plugin\InstallablePluginBase;
use RoboPackage\Core\Traits\EnvironmentCommandTrait;
use RoboPackage\Core\Plugin\Manager\ExecutableManager;
use RoboPackage\Drupal\Plugin\RoboPackage\Executable\Drush;
use RoboPackage\Core\Exception\RoboPackageRuntimeException;
use RoboCollection\Core\Attributes\InstallablePluginMetadata;
use RoboPackage\Core\Contract\PluginContainerInjectionInterface;

/**
 * The Drupal framework installation.
 */
#[InstallablePluginMetadata(
    id: 'drupal',
    label: 'Drupal',
    group: 'framework'
)]
class DrupalFramework extends InstallablePluginBase implements ConfigAwareInterface, PluginContainerInjectionInterface
{
    use DatabaseCommandTrait;
    use EnvironmentCommandTrait;

    /**
     * Define the class constructor.
     *
     * @param array $configuration
     *   The plugin configuration.
     * @param array $pluginDefinition
     *   The plugin metadata definition.
     * @param \RoboPackage\Core\Plugin\Manager\ExecutableManager $executableManager
     *   The executable manager instance.
     */
    public function __construct(
        protected array $configuration,
        protected array $pluginDefinition,
        protected ExecutableManager $executableManager
    )
    {
        parent::__construct($configuration, $pluginDefinition);
    }

    /**
     * @inheritDoc
     */
    public static function create(
        array $configuration,
        array $pluginDefinition,
        ContainerInterface $container
    ): PluginInterface
    {
        return new static(
            $configuration,
            $pluginDefinition,
            $container->get('executableManager')
        );
    }

    /**
     * @inheritDoc
     */
    protected function mainInstallation(): void
    {
        $io = $this->io();

        try {
            $drupalProfiles = ['standard', 'minimal'];
            $drushExecutable = $this->drushExecutable();
            $drushExecutable->setCommand('site:install');

            $installDefaults = $this->getInstallationDefaults();

            if ($profile = $io->choice(
                question: 'Select the Drupal profile?',
                choices: $drupalProfiles,
                default: $installDefaults['site_profile'] ?: array_key_first(
                    $drupalProfiles
                )
            )) {
                $drushExecutable->setArgument($profile);
            }

            if ($siteName = $io->ask(
                question: 'Input the Drupal site name?',
                default: $installDefaults['site_name'],
                validator: QuestionValidators::requiredValue()
            )) {
                $drushExecutable->setOption('site-name', $siteName);
            }

            if ($siteEmail = $io->ask(
                question: 'Input the Drupal site email?',
                default: $installDefaults['site_email'],
                validator: QuestionValidators::requiredValue()
            )) {
                $drushExecutable->setOption('site-mail', $siteEmail);
            }

            if ($accountName = $io->ask(
                question: 'Input the Drupal account username?',
                default: $installDefaults['account_user'],
                validator: QuestionValidators::requiredValue()
            )) {
                $drushExecutable->setOption('account-name', $accountName);
            }

            if ($accountPass = $io->ask(
                question: 'Input the Drupal account password?',
                default: $installDefaults['account_password'],
                validator: QuestionValidators::requiredValue()
            )) {
                $drushExecutable->setOption('account-pass', $accountPass);
            }

            if ($accountEmail = $io->ask(
                question: 'Input the Drupal account email?',
                default: $installDefaults['account_email'],
                validator: QuestionValidators::requiredValue()
            )) {
                $drushExecutable->setOption('account-mail', $accountEmail);
            }

            if ($command = $drushExecutable->build()) {
                $result = $this->runEnvironmentCommand(
                    'execute',
                    [$command]
                );

                if ($result->wasSuccessful()) {
                    $io->success(
                        'The Drupal installation has been successfully completed!'
                    );
                }
            }
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    protected function preInstallation(): void
    {
        $io = $this->io();

        try {
            if ($database = $this->getDatabase('primary', 'internal')) {
                $filePath = $io->ask(
                    question: 'Input the Drupal site directory path.',
                    default: 'web/sites/default',
                    validator: QuestionValidators::requiredValue()
                );
                $settingsFilePath = "$filePath/settings.local.php";

                if (!file_exists($settingsFilePath)) {
                    throw new RoboPackageRuntimeException(
                        'Unable to locate the Drupal settings.local.php file.'
                    );
                }

                $dbType = $database->getType();
                $snippet = <<<PHPTEMP
                \$databases['default']['default'] = [
                  'database' => '{$database->getDatabase()}',
                  'username' => '{$database->getUsername()}',
                  'password' => '{$database->getPassword()}',
                  'host' => '{$database->getHost()}',
                  'port' => '{$database->getPort()}',
                  'driver' => '$dbType',
                  'namespace' => 'Drupal\\$dbType\\Driver\\Database\\$dbType',
                ];
                PHPTEMP;

                $result = $this->taskWriteToFile($settingsFilePath)
                    ->append()
                    ->appendUnlessMatches(
                        '/\$databases/',
                        "\r\n$snippet"
                    )->run();

                if ($result->wasSuccessful()) {
                    $this->io()->success([
                        'Successfully set up the database connection in the ' .
                        'Drupal local.settings.php',
                    ]);
                } else {
                    throw new RoboPackageRuntimeException(
                        'Error occurred when saving database connection to ' .
                        'the Drupal local.settings.php.'
                    );
                }
            }
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * Get the Drupal installation configurations.
     *
     * @return string[]
     *   An array of Drupal installation configurations.
     */
    protected function getInstallationDefaults(): array
    {
        return [
            'account_user' => 'admin',
            'account_password' => 'admin',
            'site_name' => 'Drupal Demo',
            'site_email' => 'site@example.com',
            'site_profile' => 'standard',
            'account_email' => 'admin@example.com',
        ];
    }

    /**
     * @return \RoboPackage\Drupal\Plugin\RoboPackage\Executable\Drush|null
     */
    protected function drushExecutable(): ?Drush
    {
        return $this->executableManager->createInstance('drush');
    }
}
