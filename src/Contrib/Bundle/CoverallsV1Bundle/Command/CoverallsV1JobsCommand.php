<?php
namespace Contrib\Bundle\CoverallsV1Bundle\Command;

use Psr\Log\NullLogger;
use Contrib\Component\Log\ConsoleLogger;
use Contrib\Bundle\CoverallsV1Bundle\Api\Jobs;
use Contrib\Bundle\CoverallsV1Bundle\Config\Configurator;
use Contrib\Bundle\CoverallsV1Bundle\Config\Configuration;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Http\Exception\ServerErrorResponseException;
use Guzzle\Http\Exception\CurlException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coveralls Jobs API v1 command.
 *
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CoverallsV1JobsCommand extends Command
{
    /**
     * Path to project root directory.
     *
     * @var string
     */
    protected $rootDir;

    /**
     * Coveralls Jobs API.
     *
     * @var \Contrib\Bundle\CoverallsV1Bundle\Api\Jobs
     */
    protected $api;

    /**
     * Logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    // internal method

    /**
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::configure()
     */
    protected function configure()
    {
        $this
        ->setName('coveralls:v1:jobs')
        ->setDescription('Coveralls Jobs API v1')
        ->addOption(
            'config',
            '-c',
            InputOption::VALUE_OPTIONAL,
            '.coveralls.yml path',
            '.coveralls.yml'
        )
        ->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Do not send json_file to Jobs API'
        )
        ->addOption(
            'env',
            '-e',
            InputOption::VALUE_OPTIONAL,
            'Runtime environment name: test, dev, prod',
            'prod'
        );
    }

    /**
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->loadConfiguration($input, $this->rootDir);
        $this->logger = $config->isVerbose() && !$config->isTestEnv() ? new ConsoleLogger($output) : new NullLogger();

        $this->runApi($config);

        return 0;
    }

    // for Jobs API

    /**
     * Load configuration.
     *
     * @param  InputInterface                                         $input   Input arguments.
     * @param  string                                                 $rootDir Path to project root directory.
     * @return \Contrib\Bundle\CoverallsV1Bundle\Config\Configuration
     */
    protected function loadConfiguration(InputInterface $input, $rootDir)
    {
        $coverallsYmlPath = $input->getOption('config');
        $isDryRun         = $input->getOption('dry-run');
        $verbose          = $input->getOption('verbose');
        $env              = $input->getOption('env');

        $ymlPath      = $this->rootDir . DIRECTORY_SEPARATOR . $coverallsYmlPath;
        $configurator = new Configurator();

        return $configurator
        ->load($ymlPath, $rootDir)
        ->setDryRun($isDryRun)
        ->setVerbose($verbose)
        ->setEnv($env);
    }

    /**
     * Run Jobs API.
     *
     * @param  Configuration $config Configuration.
     * @return void
     */
    protected function runApi(Configuration $config)
    {
        $client    = new Client();
        $this->api = new Jobs($config, $client);

        $this
        ->collectCloverXml($config)
        ->collectGitInfo()
        ->collectEnvVars()
        ->dumpJsonFile($config)
        ->send();
    }

    /**
     * Collect clover XML into json_file.
     *
     * @param  Configuration                                                    $config Configuration.
     * @return \Contrib\Bundle\CoverallsV1Bundle\Command\CoverallsV1JobsCommand
     */
    protected function collectCloverXml(Configuration $config)
    {
        $this->logger->info(sprintf('Load coverage clover log:'));

        foreach ($config->getCloverXmlPaths() as $path) {
            $this->logger->info(sprintf('  - %s', $path));
        }

        $this->api->collectCloverXml();

        $jsonFile = $this->api->getJsonFile();

        if ($jsonFile->hasSourceFiles()) {
            $this->logger->info('Found source file: ');

            foreach ($jsonFile->getSourceFiles() as $sourceFile) {
                $this->logger->info(sprintf('  - %s', $sourceFile->getName()));
            }
        }

        return $this;
    }

    /**
     * Collect git repository info into json_file.
     *
     * @return \Contrib\Bundle\CoverallsV1Bundle\Command\CoverallsV1JobsCommand
     */
    protected function collectGitInfo()
    {
        $this->logger->info('Collect git info');

        $this->api->collectGitInfo();

        return $this;
    }

    /**
     * Collect environment variables.
     *
     * @return \Contrib\Bundle\CoverallsV1Bundle\Command\CoverallsV1JobsCommand
     */
    protected function collectEnvVars()
    {
        $this->logger->info('Read environment variables');

        $this->api->collectEnvVars($_SERVER);

        return $this;
    }

    /**
     * Dump uploading json file.
     *
     * @param  Configuration                                                    $config Configuration.
     * @return \Contrib\Bundle\CoverallsV1Bundle\Command\CoverallsV1JobsCommand
     */
    protected function dumpJsonFile(Configuration $config)
    {
        $this->logger->info(sprintf('Dump uploading json file: %s', $config->getJsonPath()));

        $this->api->dumpJsonFile();

        return $this;
    }

    /**
     * Send json_file to jobs API.
     *
     * @return void
     */
    protected function send()
    {
        $this->logger->info(sprintf('Submitting to %s', Jobs::URL));

        try {
            $response = $this->api->send();

            $message = $response
                ? sprintf('Finish submitting. status: %s %s', $response->getStatusCode(), $response->getReasonPhrase())
                : 'Finish dry run';

            $this->logger->info($message);

            return;
            // @codeCoverageIgnoreStart
        } catch (CurlException $e) {
            // connection error
            // tested with network disconnected and got message:
            //   Connection error occurred.
            //   [curl] 6: Could not resolve host:
            //   (nil); nodename nor servname provided, or not known [url] https://coveralls.io/api/v1/jobs
            $message  = sprintf("Connection error occurred. %s\n\n%s", $e->getMessage(), $e->getTraceAsString());
        } catch (ClientErrorResponseException $e) {
            // 422 Unprocessable Entity
            $response = $e->getResponse();
            $message  = sprintf('Client error occurred. status: %s %s', $response->getStatusCode(), $response->getReasonPhrase());
        } catch (ServerErrorResponseException $e) {
            // 503 Service Unavailable
            $response = $e->getResponse();
            $message  = sprintf('Server error occurred. status: %s %s', $response->getStatusCode(), $response->getReasonPhrase());
        } catch (\Exception $e) {
            $message  = sprintf("%s\n\n%s", $e->getMessage(), $e->getTraceAsString());
        }

        $this->logger->error($message);
    } // @codeCoverageIgnoreEnd

    // accessor

    /**
     * Set root directory.
     *
     * @param string $rootDir Path to project root directory.
     */
    public function setRootDir($rootDir)
    {
        $this->rootDir = $rootDir;
    }
}