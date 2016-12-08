<?php

// @codingStandardsIgnoreStart
use Cheppers\LintReport\Reporter\BaseReporter;
use Cheppers\LintReport\Reporter\CheckstyleReporter;
use League\Container\ContainerInterface;
use Robo\Collection\CollectionBuilder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;

/**
 * Class RoboFile.
 */
class RoboFile extends \Robo\Tasks
    // @codingStandardsIgnoreEnd
{
    use \Cheppers\Robo\Git\GitTaskLoader;
    use \Cheppers\Robo\Phpcs\LoadPhpcsTasks;

    /**
     * @var array
     */
    protected $composerInfo = [];

    /**
     * @var array
     */
    protected $codeceptionInfo = [];

    /**
     * @var string
     */
    protected $packageVendor = '';

    /**
     * @var string
     */
    protected $packageName = '';

    /**
     * @var string
     */
    protected $binDir = 'vendor/bin';

    /**
     * @var string
     */
    protected $envNamePrefix = '';

    /**
     * Allowed values: dev, git-hook, jenkins.
     *
     * @var string
     */
    protected $environment = '';

    /**
     * RoboFile constructor.
     */
    public function __construct()
    {
        putenv('COMPOSER_DISABLE_XDEBUG_WARN=1');
        $this
            ->initComposerInfo()
            ->initEnvNamePrefix();
    }

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container)
    {
        BaseReporter::lintReportConfigureContainer($container);

        return parent::setContainer($container);
    }

    /**
     * Git "pre-commit" hook callback.
     */
    public function githookPreCommit(): CollectionBuilder
    {
        $this->environment = 'git-hook';

        return $this
            ->collectionBuilder()
            ->addTaskList([
                'lint.composer.lock' => $this->taskComposerValidate(),
                'lint.phpcs.psr2' => $this->getTaskPhpcsLint(),
                'codecept' => $this->getTaskCodecept(),
            ]);
    }

    /**
     * Run the Robo unit tests.
     */
    public function test(): CollectionBuilder
    {
        return $this->getTaskCodecept();
    }

    /**
     * Run code style checkers.
     */
    public function lint(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTaskList([
                'lint.composer.lock' => $this->taskComposerValidate(),
                'lint.phpcs.psr2' => $this->getTaskPhpcsLint(),
            ]);
    }

    /**
     * @return $this
     */
    protected function initEnvNamePrefix()
    {
        $this->envNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    protected function getEnvName(string $name): string
    {
        return "{$this->envNamePrefix}_" . strtoupper($name);
    }

    protected function getEnvironment(): string
    {
        if ($this->environment) {
            return $this->environment;
        }

        return getenv($this->getEnvName('environment')) ?: 'dev';
    }

    protected function getPhpExecutable(): string
    {
        return getenv($this->getEnvName('php_executable')) ?: PHP_BINARY;
    }

    protected function getPhpdbgExecutable(): string
    {
        return getenv($this->getEnvName('phpdbg_executable')) ?: Path::join(PHP_BINDIR, 'phpdbg');
    }

    /**
     * @return $this
     */
    protected function initComposerInfo()
    {
        if ($this->composerInfo || !is_readable('composer.json')) {
            return $this;
        }

        $this->composerInfo = json_decode(file_get_contents('composer.json'), true);
        list($this->packageVendor, $this->packageName) = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function initCodeceptionInfo()
    {
        if ($this->codeceptionInfo) {
            return $this;
        }

        if (is_readable('codeception.yml')) {
            $this->codeceptionInfo = Yaml::parse(file_get_contents('codeception.yml'));
        } else {
            $this->codeceptionInfo = [
                'paths' => [
                    'log' => 'tests/_output',
                ],
            ];
        }

        return $this;
    }

    /**
     * @return CollectionBuilder
     */
    protected function getTaskCodecept(): CollectionBuilder
    {
        $environment = $this->getEnvironment();

        $withCoverageHtml = in_array($environment, ['dev', 'git-hook']);
        $withCoverageSerialized = in_array($environment, ['jenkins', 'travis']);
        $withCoverageXml = in_array($environment, ['dev', 'jenkins', 'travis']);
        $withCoverageAny = $withCoverageSerialized || $withCoverageXml || $withCoverageHtml;

        $withUnitReportHtml = in_array($environment, ['dev', 'git-hook']);
        $withUnitReportXml = in_array($environment, ['travis', 'jenkins']);

        $logDir = $this->getLogDir();

        $cmdArgs = [];
        if ($this->isPhpDbgAvailable()) {
            $cmdPattern = '%s -qrr';
            $cmdArgs[] = escapeshellcmd($this->getPhpdbgExecutable());
        } else {
            $cmdPattern = '%s';
            $cmdArgs[] = escapeshellcmd($this->getPhpExecutable());
        }

        $cmdPattern .= ' %s';
        $cmdArgs[] = escapeshellcmd("{$this->binDir}/codecept");

        $cmdPattern .= ' --ansi';
        $cmdPattern .= ' --verbose';

        $tasks = [];
        if ($withCoverageHtml) {
            $cmdPattern .= ' --coverage-html=%s';
            $cmdArgs[] = escapeshellarg('coverage/html');
        }

        if ($withCoverageXml) {
            $cmdPattern .= ' --coverage-xml=%s';
            $cmdArgs[] = escapeshellarg('coverage/coverage.xml');
        }

        if ($withCoverageAny) {
            $cmdPattern .= ' --coverage=%s';
            $cmdArgs[] = escapeshellarg('coverage/coverage.serialized');

            $tasks['prepareCoverageDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/coverage");
        }

        if ($withUnitReportHtml) {
            $cmdPattern .= ' --html=%s';
            $cmdArgs[] = escapeshellarg('junit/junit.html');
        }

        if ($withUnitReportXml) {
            $cmdPattern .= ' --xml=%s';
            $cmdArgs[] = escapeshellarg('junit/junit.xml');
        }

        if ($withUnitReportXml || $withUnitReportHtml) {
            $tasks['prepareJUnitDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/junit");
        }

        $cmdPattern .= ' run';

        if ($environment === 'jenkins') {
            // Jenkins has to use a post-build action to mark the build "unstable".
            $cmdPattern .= ' || [[ "${?}" == "1" ]]';
        }

        $tasks['runCodeception'] = $this->taskExec(vsprintf($cmdPattern, $cmdArgs));

        return $this
            ->collectionBuilder()
            ->addTaskList($tasks);
    }

    /**
     * @return \Cheppers\Robo\Phpcs\Task\PhpcsLintFiles|CollectionBuilder
     */
    protected function getTaskPhpcsLint()
    {
        $env = $this->getEnvironment();

        $files = [
            'src/',
            'tests/_data/RoboFile.php',
            'tests/_support/Helper/',
            'tests/acceptance/',
            'tests/unit/',
            'RoboFile.php',
        ];

        $options = [
            'failOn' => 'warning',
            'standard' => 'PSR2',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
        ];

        if ($env === 'jenkins') {
            $logDir = $this->getLogDir();

            $options['failOn'] = 'never';
            $options['lintReporters']['lintCheckstyleReporter'] = (new CheckstyleReporter())
                ->setDestination("$logDir/checkstyle/phpcs.psr2.xml");
        }

        if ($env !== 'git-hook') {
            return $this->taskPhpcsLintFiles($options + ['files' => $files]);
        }

        $assetJar = new Cheppers\AssetJar\AssetJar();

        return $this
            ->collectionBuilder()
            ->addTaskList([
                'git.readStagedFiles' => $this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->setAssetJar($assetJar)
                    ->setAssetJarMap('files', ['files'])
                    ->setPaths($files),
                'lint.phpcs.psr2' => $this
                    ->taskPhpcsLintInput($options)
                    ->setAssetJar($assetJar)
                    ->setAssetJarMap('files', ['files']),
            ]);
    }

    protected function isPhpExtensionAvailable(string $extension): bool
    {
        $command = sprintf('%s -m', escapeshellcmd($this->getPhpExecutable()));

        $process = new Process($command);
        $exitCode = $process->run();
        if ($exitCode !== 0) {
            throw new \RuntimeException('@todo');
        }

        return in_array($extension, explode("\n", $process->getOutput()));
    }

    protected function isPhpDbgAvailable(): bool
    {
        $command = sprintf('%s -qrr', escapeshellcmd($this->getPhpdbgExecutable()));

        return (new Process($command))->run() === 0;
    }

    protected function getLogDir(): string
    {
        $this->initCodeceptionInfo();

        return !empty($this->codeceptionInfo['paths']['log']) ?
            $this->codeceptionInfo['paths']['log']
            : 'tests/_output';
    }
}
