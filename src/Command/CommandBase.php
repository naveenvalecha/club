<?php

namespace Acquia\Club\Command;

use Acquia\Cloud\Api\Response\Site;
use Acquia\Club\Loader\JsonFileLoader;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Acquia\Cloud\Api\CloudApiClient;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class CommandBase
 *
 * @package Acquia\Club\Command
 */
abstract class CommandBase extends Command
{
    /**
     * @var string
     */
    protected $drushAliasDir;
    /** @var CloudApiClient  */
    protected $cloudApiClient;
    /**
     * @var string
     */
    protected $cloudConfDir;
    /**
     * @var string
     */
    protected $cloudConfFileName;
    /**
     * @var string
     */
    protected $cloudConfFilePath;
    /**
     * @var array
     */
    protected $cloudApiConfig;
    /** @var Filesystem */
    protected $fs;
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;
    /** @var QuestionHelper */
    protected $questionHelper;
    /**
     * @var FormatterHelper
     */
    protected $formatter;
    /**
     * @var LocalEnvironmentFacade
     */
    protected $localEnvironment;

    /**
     * CommandBase constructor.
     *
     * @param \Acquia\Club\Command\LocalEnvironmentFacade $localEnvironmentFacade
     */
    public function __construct(LocalEnvironmentFacade $localEnvironmentFacade)
    {
        $name = self::class;
        parent::__construct($name);

        $this->localEnvironment = $localEnvironmentFacade;
    }

    /**
   * Initializes the command just after the input has been validated.
   *
   * This is mainly useful when a lot of commands extends one main command
   * where some things need to be initialized based on the input arguments and options.
   *
   * @param InputInterface  $input  An InputInterface instance
   * @param OutputInterface $output An OutputInterface instance
   */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->questionHelper = $this->getHelper('question');
        $this->formatter = $this->getHelper('formatter');
        $this->fs = new Filesystem();
        $this->cloudConfDir = $_SERVER['HOME'] . '/.acquia';
        $this->drushAliasDir = $_SERVER['HOME'] . '/.drush';
        $this->cloudConfFileName = 'cloudapi.conf';
        $this->cloudConfFilePath = $this->cloudConfDir . '/' . $this->cloudConfFileName;
    }

    /**
     * Checks whether xDebug is enabled. If so, prompts user to continue.
     *
     * @return bool
     *   Returns TRUE if user chose not to continue, FALSE if xDebug is not
     *   enabled.
     */
    public function checkXdebug()
    {
        // if ($this->localEnvironment->isPhpExtensionLoaded('xdebug') && !defined('PHPUNIT_CLUB')) {
        if (!$this->localEnvironment->isPhpExtensionLoaded('xdebug')) {
            return false;
        }

        $this->output->writeln(
            "<comment>You have xDebug enabled. This will make everything very slow. You should really disable it.</comment>"
        );

        if (!($this->askContinue())) {
            return 1;
        }
    }

    /**
     * @return bool
     */
    protected function askContinue()
    {
        $question = new ConfirmationQuestion(
            '<comment>Do you want to continue?</comment> ',
            true
        );
        $continue = $this->questionHelper->ask(
            $this->input,
            $this->output,
            $question
        );

        return $continue;
    }

    /**
     * @return int
     */
    protected function checkCwd()
    {
        if ($this->fs->exists('.git')) {
            $errorMessages = [
                "It looks like you're currently inside of a git repository.",
                "You can't create a new project inside of a repository.",
                'Please change directories and try again.',
            ];
            $formattedBlock = $this->formatter->formatBlock($errorMessages, 'error');
            $this->output->writeln($formattedBlock);

            return 1;
        }
    }

  /**
   * @return array
   */
    protected function loadCloudApiConfig()
    {
        if (!$config = $this->loadCloudApiConfigFile()) {
            $config = $this->askForCloudApiCredentials();
        }

        return $config;
    }

  /**
   * @return array
   */
    protected function loadCloudApiConfigFile()
    {
        $config_dirs = [
        $_SERVER['HOME'] . $this->cloudConfDir,
        ];
        $locator = new FileLocator($config_dirs);

        try {
            $file = $locator->locate($this->cloudConfFileName, null, true);
            $loaderResolver = new LoaderResolver(array(new JsonFileLoader($locator)));
            $delegatingLoader = new DelegatingLoader($loaderResolver);
            $config = $delegatingLoader->load($file);
            return $config;
        } catch (\Exception $e) {
            return [];
        }
    }

  /**
   *
   */
    protected function askForCloudApiCredentials()
    {
        $usernameQuestion = new Question('<question>Please enter your Acquia cloud email address:</question> ', '');
        $privateKeyQuestion = new Question('<question>Please enter your Acquia cloud private key:</question> ', '');
        $privateKeyQuestion->setHidden(true);

        do {
            $email = $this->questionHelper->ask($this->input, $this->output, $usernameQuestion);
            $key = $this->questionHelper->ask($this->input, $this->output, $privateKeyQuestion);
            $this->setCloudApiClient($email, $key);
            $cloud_api_client = $this->getCloudApiClient();
        } while (!$cloud_api_client);

        $config = array(
        'email' => $email,
        'key' => $key,
        );

        $this->writeCloudApiConfig($config);
    }

    /**
     * @param $cloud_api_client
     *
     * @return string
     */
    protected function askWhichCloudSite($cloud_api_client)
    {
        $question = new ChoiceQuestion(
            '<question>Which site?</question>',
            $this->getSitesList($cloud_api_client)
        );
        $site_name = $this->questionHelper->ask($this->input, $this->output, $question);

        return $site_name;
    }

    /**
     * @param CloudApiClient $cloud_api_client
     * @param Site $site
     */
    protected function askWhichCloudEnvironment($cloud_api_client, $site)
    {
        $environments = $this->getEnvironmentsList($cloud_api_client, $site);
        $question = new ChoiceQuestion(
            '<question>Which environment?</question>',
            (array) $environments
        );
        $env = $this->questionHelper->ask($this->input, $this->output, $question);

        return $env;
    }

  /**
   * @param $config
   */
    protected function writeCloudApiConfig($config)
    {
        file_put_contents($this->cloudConfFilePath, json_encode($config));
        $this->output->writeln("<info>Credentials were written to {$this->cloudConfFilePath}.</info>");
    }

  /**
   * @return mixed
   */
    protected function getCloudApiConfig()
    {
        return $this->cloudApiConfig;
    }

    protected function setCloudApiClient($username, $password)
    {
        try {
            $cloudapi = CloudApiClient::factory(array(
                'username' => $username,
                'password' => $password,
            ));

            // We must call some method on the client to test authentication.
            $cloudapi->sites();

            $this->cloudApiClient = $cloudapi;

            return $cloudapi;
        } catch (\Exception $e) {
            // @todo this is being thrown after first auth. still works? check out.
            $this->output->writeln("<error>Failed to authenticate with Acquia Cloud API.</error>");
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->output->writeln('Exception was thrown: ' . $e->getMessage());
            }
            return null;
        }
    }

  /**
   * @param $username
   * @param $password
   *
   * @return \Acquia\Cloud\Api\CloudApiClient|null
   */
    protected function getCloudApiClient()
    {
        return $this->cloudApiClient;
    }

    protected function checkDestinationDir($dir_name)
    {
        $destination_dir = getcwd() . '/' . $dir_name;
        if ($this->fs->exists($destination_dir)) {
            $this->output->writeln("<comment>Uh oh. The destination directory already exists.</comment>");
            $question = new ConfirmationQuestion("<comment>Delete $destination_dir?</comment> ", false);
            $delete_dir = $this->questionHelper->ask($this->input, $this->output, $question);
            if ($delete_dir) {
                if ($this->fs->exists($destination_dir . '/.vagrant')) {
                    $this->output->writeln('');
                    $this->output->writeln("<comment>One more thing, it looks like there's a vagrant machine in the destination directory.</comment>");
                    $question = new ConfirmationQuestion("<comment>Destroy the vagrant machine in $destination_dir?</comment> ", false);
                    $vagrant_destroy  = $this->questionHelper->ask($this->input, $this->output, $question);

                    if ($vagrant_destroy) {
                        $this->executeCommand('vagrant destroy --force', $destination_dir);
                    }
                }

                // @todo recursively chmod all files in docroot/sites/default.
                $this->fs->chmod($destination_dir . '/docroot/sites/default/default.settings.php', 777);
                $this->fs->remove($destination_dir);
            } else {
                $this->output->writeln(
                    "<comment>Please choose a different machine name for your project, or change directories.</comment>"
                );
                return 1;
            }
        }
    }

  /**
   * @param \Acquia\Cloud\Api\CloudApiClient $cloud_api_client
   * @param $site_id
   *
   * @return \Acquia\Cloud\Api\Response\Site
   */
    protected function getSite(CloudApiClient $cloud_api_client, $site_id)
    {
        return $cloud_api_client->site($site_id);
    }

  /**
   * @param \Acquia\Cloud\Api\CloudApiClient $cloud_api_client
   *
   * @return array
   */
    protected function getSites(CloudApiClient $cloud_api_client)
    {
        $sites = $cloud_api_client->sites();
        $sites_filtered = [];

        foreach ($sites as $key => $site) {
            $label = $this->getSiteLabel($site);
            if ($label !== '*') {
                $sites_filtered[(string) $site] = $site;
            }
        }

        return $sites_filtered;
    }

  /**
   * @param $site
   *
   * @return mixed
   */
    protected function getSiteLabel($site)
    {
        $site_slug = (string) $site;
        $site_split = explode(':', $site_slug);

        return $site_split[1];
    }

  /**
   * @param \Acquia\Cloud\Api\CloudApiClient $cloud_api_client
   *
   * @return array
   */
    protected function getSitesList(CloudApiClient $cloud_api_client)
    {
        $site_list = [];
        $sites = $this->getSites($cloud_api_client);
        foreach ($sites as $site) {
            $site_list[] = $this->getSiteLabel($site);
        }
        sort($site_list, SORT_NATURAL | SORT_FLAG_CASE);

        return $site_list;
    }

  /**
   * @param \Acquia\Cloud\Api\CloudApiClient $cloud_api_client
   * @param $label
   *
   * @return \Acquia\Cloud\Api\Response\Site|null
   */
    protected function getSiteByLabel(CloudApiClient $cloud_api_client, $label)
    {
        $sites = $this->getSites($cloud_api_client);
        foreach ($sites as $site_id) {
            if ($this->getSiteLabel($site_id) == $label) {
                $site = $this->getSite($cloud_api_client, $site_id);
                return $site;
            }
        }

        return null;
    }

  /**
   * @param \Acquia\Cloud\Api\CloudApiClient $cloud_api_client
   * @param $site
   *
   * @return array
   */
    protected function getEnvironmentsList(CloudApiClient $cloud_api_client, $site)
    {
        $environments = $cloud_api_client->environments($site);
        $environments_list = [];
        foreach ($environments as $environment) {
            $environments_list[] = $environment->name();
        }

        return $environments_list;
    }

  /**
   * @param string $command
   *
   * @return bool
   */
    protected function executeCommand($command, $cwd = null, $display_output = true, $mustRun = true)
    {
        $timeout = 10800;
        $env = [
        'COMPOSER_PROCESS_TIMEOUT' => $timeout
        ] + $_ENV;
        $process = new Process($command, $cwd, $env, null, $timeout);
        $process->setTty(true);
        $method = $mustRun ? 'mustRun' : 'run';

        if ($display_output) {
            $process->$method(function ($type, $buffer) {
                print $buffer;
            });
            return $process->isSuccessful();
        } else {
            $process->$method();
            return $process->getOutput();
        }
    }
  /**
   * @param $command
   *
   * @return bool
   */
    protected function executeCommands($commands = [], $cwd = null)
    {
        foreach ($commands as $command) {
            $this->executeCommand($command, $cwd);
        }
    }
}
