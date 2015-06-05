<?php

/*
 * This file is part of the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Generalov\AsseticToolsBundle\Command;

use Assetic\Asset\AssetCollectionInterface;
use Assetic\Asset\AssetInterface;
use Assetic\Asset\AssetReference;
use Assetic\Factory\AssetFactory;
use Assetic\Filter\DependencyExtractorInterface;
use Symfony\Bundle\AsseticBundle\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dumps assets as their source files are modified.
 *
 * @property-read \Assetic\Factory\LazyAssetManager $am
 * @author    Kris Wallsmith <kris@symfony.com>
 */
class AsseticDumpFilesCommand extends AbstractCommand
{
    private $sourceAssetMap;

    protected static function walkAsset(AssetFactory $factory, AssetInterface $asset, $callback)
    {
        if ($asset instanceof AssetCollectionInterface) {
            foreach ($asset as $leaf) {
                self::walkAsset($factory, $leaf, $callback);
            }
        } else {
            $filters = $asset->getFilters();
            if ($filters) {
                $prevFilters = [];
                foreach ($filters as $filter) {
                    $prevFilters[] = $filter;

                    if (!$filter instanceof DependencyExtractorInterface) {
                        continue;
                    }

                    // extract children from leaf after running all preceding filters
                    $clone = clone $asset;
                    $clone->clearFilters();
                    foreach (array_slice($prevFilters, 0, -1) as $prevFilter) {
                        $clone->ensureFilter($prevFilter);
                    }
                    $clone->load();

                    foreach ($filter->getChildren($factory, $clone->getContent(), $clone->getSourceDirectory()) as $child) {
                        self::walkAsset($factory, $child, $callback);
                    }
                }
            }

            $callback($asset);
        }
    }

    protected function configure()
    {
        $this
            ->setName('assetic:dump-files')
            ->setDescription('Dumps assets to the filesystem for their source files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force an initial generation of all assets')
            ->addOption('listen', null, InputOption::VALUE_REQUIRED, 'Socket path to listen at')
//            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Set the polling period in seconds', 1)
            ->addOption('write_to', null, InputOption::VALUE_REQUIRED, 'Override the configured asset root')
            ->addArgument('files', InputArgument::IS_ARRAY, 'Filenames to rebuild assets');
    }

    protected function execute(InputInterface $input, OutputInterface $stdout)
    {
        // capture error output
        $stderr = $stdout instanceof ConsoleOutputInterface
            ? $stdout->getErrorOutput()
            : $stdout;

        // print the header
        $stdout->writeln(sprintf('Dumping all <comment>%s</comment> assets.', $input->getOption('env')));
        $stdout->writeln(sprintf('Debug mode is <comment>%s</comment>.', $this->am->isDebug() ? 'on' : 'off'));
        $stdout->writeln('');

        $files = $input->getArgument('files');
        $localSocket = $input->getOption('listen');
        $force = $input->getOption('force');
        // establish a temporary status file
        $cache = sys_get_temp_dir() . '/assetic_dump_files_' . substr(sha1($this->basePath), 0, 7);

        if ($files) {
            $this->doDumpAssets($files, $force, $cache, $stderr, $stdout);
        }

        if ($localSocket) {
            $this->doRunServer("unix://{$localSocket}", $force, $cache, $stderr, $stdout);
        }
    }

    /**
     * Builds map of [source => [assetName]]
     *
     * @return array of [source => [assetName]]
     */
    protected function buildSourceToAssetMap()
    {
        $res = [];
        $assets = [];
        $factory = $this->getAsseticFactory();
        // reset the asset manager
        $this->am->clear();
        $this->am->load();
        clearstatcache();

        foreach ($this->am->getNames() as $name) {
            $visitor = function (AssetInterface $asset) use (&$res, &$assets, $name) {
                if ($asset instanceof AssetReference) {
                    $rObj = new \ReflectionObject($asset);
                    $prop = $rObj->getProperty('name');
                    $prop->setAccessible(true); // <--- you set the property to public before you read the value
                    $refName = $prop->getValue($asset);
                    $prop->setAccessible(false);
                    $assets[$refName][] = $name;
                } else {
                    $root = $asset->getSourceRoot();
                    $path = $asset->getSourcePath();
                    $fullPath = "{$root}/{$path}";
                    $res[$fullPath][$name] = $name;
                }
            };
            /** @var AssetInterface $asset */
            $asset = $this->am->get($name);
            self::walkAsset($factory, $asset, $visitor);
        }

        return [
            'files' => $res,
            'assets' => $assets
        ];
    }

    /**
     * @return AssetFactory
     */
    protected function getAsseticFactory()
    {
        // XXX: I need access to LazyAssetManager's factory to walk on asset children (ex. sass includes)
        $rObj = new \ReflectionObject($this->am);
        $prop = $rObj->getProperty('factory');
        $prop->setAccessible(true); // <--- you set the property to public before you read the value
        $factory = $prop->getValue($this->am);

        return $factory;
    }

    /**
     * @param $files
     * @param $sourceAssetMap
     *
     * @return array
     */
    protected function getAssetsToDump($files, $sourceAssetMap)
    {
        $endsWith = function ($haystack, $needle) {
            // search forward starting from end minus needle length characters
            return $needle === '' || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
        };
        // dump assets contains files
        $assets = [];
        foreach ($files as $file) {
            foreach ($sourceAssetMap['files'] as $source => $names) {
                if ($endsWith($source, $file)) {
                    $assets[] = $names;
                    $assets[] = self::getDependentAssets($names, $sourceAssetMap);
                    // append dependent assets
                }
            }
        }
        $result = $assets ? array_unique(call_user_func_array('array_merge', $assets)) : [];

        return $result;
    }

    protected function getDependentAssets($names, $sourceAssetMap)
    {
        $assets = [];
        foreach ($names as $name) {
            if (isset($sourceAssetMap['assets'][$name])) {
                $dependentAssets = $sourceAssetMap['assets'][$name];
                $assets[] = $dependentAssets;
                $assets[] = self::getDependentAssets($dependentAssets, $sourceAssetMap);
            }
        }
        $result = $assets ? array_unique(call_user_func_array('array_merge', $assets)) : [];

        return $result;
    }

    /**
     * @param $force
     * @param $cache
     *
     * @return array|mixed
     */
    protected function getSourceToAssetMap($force, $cache)
    {
        if ($force || !file_exists($cache)) {
            $sourceAssetMap = $this->buildSourceToAssetMap();
            file_put_contents($cache, serialize($sourceAssetMap));
            $this->sourceAssetMap = $sourceAssetMap;
        } elseif (!$this->sourceAssetMap) {
            $sourceAssetMap = unserialize(file_get_contents($cache));
            if (!is_array($sourceAssetMap)) {
                $sourceAssetMap = $this->buildSourceToAssetMap();
                file_put_contents($cache, serialize($sourceAssetMap));
            }
            $this->sourceAssetMap = $sourceAssetMap;
        }

        return $this->sourceAssetMap;
    }

    /**
     * @param                 $files array of file names belongs to assets
     * @param boolean         $force
     * @param                 $cache string
     * @param OutputInterface $stderr
     * @param OutputInterface $stdout
     */
    protected function doDumpAssets($files, $force, $cache, OutputInterface $stderr, OutputInterface $stdout)
    {
        $sourceAssetMap = $this->getSourceToAssetMap($force, $cache);
        try {
            $assetsToDump = $this->getAssetsToDump($files, $sourceAssetMap);
            if (!$assetsToDump) {
                // no any asset found.
                // perhaps, it's new file. give second chance by force to rebuild cache.
                $sourceAssetMap = $this->getSourceToAssetMap(true, $cache);
                $assetsToDump = $this->getAssetsToDump($files, $sourceAssetMap);
            }
            foreach ($assetsToDump as $name) {
                $this->dumpAsset($name, $stdout);
            }
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if ($msg) {
                $stderr->writeln('<error>[error]</error> ' . $msg);
            }
        }
    }

    /**
     * @param string          $localSocket unix socket path to listen to.
     * @param boolean         $force
     * @param                 $cache
     * @param OutputInterface $stderr
     * @param OutputInterface $stdout
     *
     */
    protected function doRunServer($localSocket, $force, $cache, $stderr, OutputInterface $stdout)
    {
        $server = stream_socket_server($localSocket, $errno, $errorMessage);

        if ($server === false) {
            throw new \UnexpectedValueException("Could not bind to socket: $errorMessage");
        }
        $stdout->writeln(sprintf('Listen: %s', $localSocket));

        for (; ;) {
            /** @noinspection PhpAssignmentInConditionInspection */
            while ($client = @stream_socket_accept($server)) {
                /** @noinspection PhpAssignmentInConditionInspection */
                while ($cmd = trim(fgets($client))) {
                    $stdout->writeln(sprintf('> %s', $cmd));
                    switch ($cmd) {
                        case 'refresh':
                            $this->getSourceToAssetMap(true, $cache);
                            break;
                        case 'quit':
                            fclose($client);
                            break;
                        default:
                            $files = [$cmd];
                            $this->doDumpAssets($files, $force, $cache, $stderr, $stdout);
                            $force = false;
                            break;
                    }
                    sleep(1);
                }
                fclose($client);
                $stdout->writeln(sprintf('Dismissed'));
            }
        }
    }
}

