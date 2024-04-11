<?php

declare(strict_types=1);

namespace RoboPackage\Drupal\Robo\Plugin\Commands;

use Robo\Tasks;
use Robo\Result;
use Robo\Task\Base\Exec;
use Robo\Symfony\ConsoleIO;
use RoboPackage\Core\RoboPackage;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\ConfigAwareInterface;
use RoboPackage\Core\Datastore\JsonDatastore;
use Robo\Contract\VerbosityThresholdInterface;
use RoboPackage\Core\Traits\ConfigCommandTrait;
use RoboPackage\Core\Traits\DatabaseCommandTrait;
use Symfony\Component\Console\Question\Question;
use RoboPackage\Core\Plugin\Manager\ExecutableManager;
use RoboPackage\Core\Exception\RoboPackageRuntimeException;
use RoboPackage\Drupal\Plugin\RoboPackage\Executable\Drush;

/**
 * Define the Drupal Robo package commands
 */
class DrupalCommands extends Tasks implements ConfigAwareInterface
{
    use ConfigCommandTrait;
    use DatabaseCommandTrait;

    /**
     * The project root path.
     *
     * @var string
     */
    protected string $rootPath;

    /**
     * The project composer.json data.
     *
     * @var array
     */
    protected array $composer;

    /**
     * The executable manager.
     *
     * @var \RoboPackage\Core\Plugin\Manager\ExecutableManager
     */
    protected ExecutableManager $executableManager;


    /**
     * Define the class constructor.
     */
    public function __construct()
    {
        $container = RoboPackage::getContainer();
        $this->rootPath = RoboPackage::rootPath();
        $this->composer = RoboPackage::getComposer();
        $this->executableManager = $container->get('executableManager');
    }

    /**
     * Execute an arbitrary Drush command.
     *
     * @aliases drush
     */
    public function drupalDrush(
        ConsoleIO $io,
        array $drushCommand,
    ): void {
        try {
            if ($drushExecutable = $this->drushExecutable()) {
                $this->executeEnvironmentCommand(
                    $drushExecutable
                        ->setArguments($drushCommand)
                        ->build()
                );
            }
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * Create a Drupal account with role.
     *
     * @param string $username
     *   The drupal username.
     * @param array $options
     *   The command options.
     *
     * @option $role
     *   The account user role name
     * @option $email
     *   The account user email address.
     * @option $password
     *   The account user password.
     */
    public function drupalCreateAccount(
        ConsoleIO $io,
        string $username,
        array $options = [
            'role' => 'administrator',
            'email' => 'admin@example.com',
            'password' => 'admin',
        ]
    ): void {
        try {
            $userEmail = $options['email'];
            $collectionBuilder = $this->collectionBuilder();

            $userInfo = $this->drushUserInformation(
                $username,
                ['mail' => $userEmail]
            );

            if (count($userInfo) === 0) {
                $collectionBuilder->addTask($this->drushExecTask(
                    'user:create',
                    [$username],
                    [
                        'mail' => $userEmail,
                        'password' => $options['password'],
                    ],
                ));
            }

            $collectionBuilder->addTask($this->drushExecTask(
                'user:role:add',
                [
                    $options['role'],
                    $username
                ],
                ['mail' => $userEmail]
            ));
            $result = $collectionBuilder->run();

            if (!$result->wasSuccessful()) {
                throw new RoboPackageRuntimeException(
                    'Error was thrown when running command.'
                );
            }
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * Login to the Drupal application.
     *
     * @option string|int $lookupValue
     *   The user lookup value.
     * @option string $lookupType
     *   The user lookup type.
     */
    public function drupalLogin(
        ConsoleIO $io,
        array $options = [
            'lookup-value' => '1',
            'lookup-type' => 'id',
            'no-browser' => false,
        ]
    ): void {
        try {
            $lookupType = $options['lookup-type'];
            $lookupTypeMap = [
                'id' => 'uid',
                'name' => 'name',
                'mail' => 'mail',
            ];

            if (!isset($lookupTypeMap[$lookupType])) {
                throw new RoboPackageRuntimeException(sprintf(
                    'The %s user lookup type is invalid.',
                    $lookupType
                ));
            }

            $result = $this->drushExecTask(
                'user:login',
                options: [
                    $lookupTypeMap[$lookupType] => $options['lookup-value']
                ],
                silentOutput: !$options['no-browser']
            )->run();

            if (!$options['no-browser'] && $result->wasSuccessful()) {
                $this->taskOpenBrowser($result->getMessage())->run();
            }
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * Patch a Drupal module.
     */
    public function drupalPatch(ConsoleIO $io): void
    {
        try {
            do {
                $issueNumber = (int) $this->doAsk((new Question(
                    $this->formatQuestion('Input the Drupal issue URL or ID')
                ))->setNormalizer(static function ($value) {
                    if (isset($value)) {
                        $value = trim($value);

                        $matches = [];
                        $urlPattern = '/^https?:\/\/(?:www.)?drupal.org\/project\/.+\/issues\/(\d+)$/';

                        if (preg_match($urlPattern, $value, $matches)) {
                            return $matches[1];
                        }
                    }

                    return $value;
                })->setValidator(static function ($value) {
                    if (!isset($value) || !is_numeric($value)) {
                        throw new RoboPackageRuntimeException(
                            'The Drupal issue number is required!'
                        );
                    }

                    return $value;
                }));
                $patches = $this->selectDrupalComposerPatch(
                    $io,
                    $this->fetchDrupalIssueDefinition(
                        $issueNumber
                    )
                );
                $this->setComposerPatches($patches);
            } while ($this->confirm('Patch another Drupal package?'));
            $this->taskComposerUpdate()->option('lock')->run();
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
        }
    }

    /**
     * Fetch the Drupal API resource.
     *
     * @param string $resource
     *   The Drupal resource.
     * @param array $query
     *   The Drupal resource query.
     *
     * @return array|bool
     *   Return array; otherwise false if HTTP request failed.
     *
     * @throws \JsonException
     */
    protected function fetchDrupalApiResource(
        string $resource,
        array $query = [],
    ): array|bool {
        $apiQuery = http_build_query($query);
        $apiBaseUrl = 'https://www.drupal.org/api-d7';
        $apiResourceUrl = "$apiBaseUrl/$resource.json?$apiQuery";

        if ($apiContents = file_get_contents($apiResourceUrl)) {
            return json_decode(
                $apiContents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        return false;
    }

    /**
     * Fetch the Drupal issue definition.
     *
     * @param int $nodeIssueId
     *   The Drupal node issue ID.
     *
     * @return array
     *   An array of Drupal issue information.
     *
     * @throws \JsonException
     */
    protected function fetchDrupalIssueDefinition(int $nodeIssueId): array
    {
        $issueDefinition = [
            'issue' => $nodeIssueId,
            'title' => null,
            'patches' => []
        ];
        $apiResourceQuery = ['nid' => $nodeIssueId, 'type' => 'project_issue'];

        if ($issuesData = $this->fetchDrupalApiResource('node', $apiResourceQuery)) {
            $issuesContent = reset($issuesData['list']);

            if (!isset($issuesContent['field_issue_files'])) {
                throw new RoboPackageRuntimeException(sprintf(
                    'Drupal issue %d does not contain any patches!',
                    $nodeIssueId
                ));
            }
            $issueDefinition['title'] = $issuesContent['title'];
            $issueDefinition['patches'] = $this->parseDrupalIssuePatches(
                array_filter(
                    $issuesContent['field_issue_files'],
                    static fn ($file) => (int) $file['display'] === 1
                )
            );
            if (
                $nodeData = $this->fetchDrupalApiResource(
                    "node/$nodeIssueId",
                    ['related_mrs' => true]
                )
            ) {
                foreach ($nodeData['related_mrs'] ?? [] as $mergeRequestUrl) {
                    array_unshift(
                        $issueDefinition['patches'],
                        "$mergeRequestUrl.patch"
                    );
                }
            }
        } else {
            throw new RoboPackageRuntimeException(sprintf(
                'Unable to fetch the Drupal issue for %d!',
                $nodeIssueId
            ));
        }

        return $issueDefinition;
    }

    /**
     * Parse the Drupal patches from the files.
     *
     * @param array $files
     *   An array of Drupal issue files.
     *
     * @return array
     *   An array of Drupal issue patches.
     *
     * @throws \JsonException
     */
    protected function parseDrupalIssuePatches(
        array $files,
        int $limit = 10
    ): array {
        $patches = [];

        foreach (array_slice(array_reverse($files), 0, $limit) as $file) {
            if (!isset($file['file']['uri'])) {
                continue;
            }
            if ($contents = file_get_contents("{$file['file']['uri']}.json")) {
                $content = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (
                    !isset($content['name'], $content['url'])
                    || pathinfo($content['name'], PATHINFO_EXTENSION) !== 'patch'
                ) {
                    continue;
                }
                $patches[] = $content['url'];
            }
        }

        return $patches;
    }

    /**
     * Select the Drupal composer patch.
     *
     * @param \Robo\Symfony\ConsoleIO $io
     *   The console IO.
     * @param array $definition
     *   An array of Drupal issue information.
     *
     * @return array[]
     *   An array of the Drupal composer patch structure.
     *
     * @throws \JsonException
     */
    protected function selectDrupalComposerPatch(
        ConsoleIO $io,
        array $definition
    ): array {
        if (isset($definition['title'], $definition['issue'], $definition['patches'])) {
            $issueTitle = $definition['title'];
            $issueNumber = $definition['issue'];
            $issuePatches = $definition['patches'];

            $issuePackage = $io->choice(
                'Select Drupal Package',
                $this->getComposerDrupalPackages()
            );
            $issuePatchName = $io->choice(
                'Select Drupal Patch',
                $issuePatches,
                '0'
            );

            return [
                $issuePackage => [
                    "#$issueNumber: $issueTitle" => $issuePatchName
                ]
            ];
        }

        return [];
    }

    /**
     * Set the composer patches.
     *
     * @throws \JsonException
     */
    protected function setComposerPatches(
        array $patches
    ): void {
        $composer = $this->composer;

        if (isset($composer['extra']['patches-file'])) {
            $patchesFile = $composer['extra']['patches-file'];
            $patchesFilePath = "$this->rootPath/$patchesFile";
            (new JsonDatastore($patchesFilePath))->merge()->write(
                ['patches' => $patches]
            );
        } else {
            foreach ($patches as $vendor => $patch) {
                if (!is_array($patch)) {
                    continue;
                }
                $this->taskComposerConfig()
                    ->option('json')
                    ->option('merge')
                    ->rawArg("extra.patches.$vendor")
                    ->arg(json_encode($patch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
                    ->run();
            }
        }
    }

    /**
     * Get composer Drupal packages.
     *
     * @return array
     *   An array of Drupal composer packages.
     */
    protected function getComposerDrupalPackages(): array
    {
        $composerPackages = array_merge(
            $this->composer['require'],
            $this->composer['require-dev']
        );

        $drupalPackages = array_filter(
            array_keys($composerPackages),
            static fn ($package) => str_starts_with($package, 'drupal/')
        );

        if (in_array('drupal/core-recommended', $drupalPackages, true)) {
            $drupalPackages[] = 'drupal/core';
        }

        return array_values($drupalPackages);
    }

    /**
     * Create the Drush executable task.
     *
     * @param string $command
     *   The Drush command.
     * @param array $arguments
     *   An array of Drush command arguments.
     * @param array $options
     *   An array of Drush command options.
     * @param bool $silentOutput
     *   Set true to silent the command output.
     *
     * @return CollectionBuilder|Exec|null
     */
    protected function drushExecTask(
        string $command,
        array $arguments = [],
        array $options = [],
        bool $silentOutput = false
    ): CollectionBuilder|Exec|null {
        if ($drushExecutable = $this->drushExecutable()) {
            $drushCommand = $drushExecutable
                ->setCommand($command)
                ->setOptions($options)
                ->setArguments($arguments);

            $drushTask = $this->buildCommandTask(
                'execute',
                'environment',
                [$drushCommand->build()]
            );

            if ($silentOutput) {
                $drushTask
                    ->printOutput(false)
                    ->setVerbosityThreshold(
                        VerbosityThresholdInterface::VERBOSITY_DEBUG
                    );
            }

            return $drushTask;
        }

        return null;
    }

    /**
     * Get Drupal user information.
     *
     * @param string|array $usernames
     *   A string or array of usernames.
     * @param array $options
     *   An array of drush command options.
     *
     * @return array
     *   An array of Drupal user information.
     */
    protected function drushUserInformation(
        string|array $usernames,
        array $options = []
    ): array {
        try {
            $options['format'] = 'json';
            $result = $this->drushExecTask(
                'user:information',
                [implode(',', (array) $usernames)],
                $options,
                true
            )->run();

            return $result->wasSuccessful()
                ? json_decode($result->getMessage(), true, 512, JSON_THROW_ON_ERROR)
                : [];
        } catch (\Exception $exception) {
            throw new RoboPackageRuntimeException(
                $exception->getMessage()
            );
        }
    }

    /**
     * Execute the environment command.
     *
     * @param string $command
     *
     * @return bool|\Robo\Result
     */
    protected function executeEnvironmentCommand(
        string $command
    ): bool|Result {
        return $this->runConfigCommand(
            'execute',
            'environment',
            [$command]
        );
    }

    /**
     * @return \RoboPackage\Drupal\Plugin\RoboPackage\Executable\Drush|null
     */
    protected function drushExecutable(): ?Drush
    {
        return $this->executableManager->createInstance('drush');
    }
}
