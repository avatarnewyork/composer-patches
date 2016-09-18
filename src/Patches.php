<?php

/**
 * @file
 * Contains cweagans\Composer\Patches.
 *
 * This plugin allows Composer users to apply patches to installed dependencies
 * through a variety of methods, including a list of patches in the root
 * composer.json, a separate patches file, and patches aggregated from dependencies
 * installed by Composer.
 */

namespace cweagans\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Patches implements PluginInterface, EventSubscriberInterface
{

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var ProcessExecutor
     */
    protected $executor;

    /**
     * @var PatchCollection
     */
    protected $patchCollection;

    /**
     * @var array
     */
    protected $config;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // Store a bunch of stuff we'll need later.
        $this->composer = $composer;
        $this->io = $io;
        $this->eventDispatcher = $composer->getEventDispatcher();
        $this->executor = new ProcessExecutor($this->io);
        $this->patchCollection = new PatchCollection();

        // Set up the plugin configuration.
        $this->configure();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => '',
            ScriptEvents::PRE_UPDATE_CMD => '',
            PackageEvents::PRE_PACKAGE_INSTALL => '',
            PackageEvents::PRE_PACKAGE_UPDATE => '',
            PackageEvents::POST_PACKAGE_INSTALL => '',
            PackageEvents::POST_PACKAGE_UPDATE => '',
        ];
    }

    /**
     * Configure the patches plugin.
     *
     * Configuration depends on (in order of increasing priority):
     *   - Default values (which are defined in this method)
     *   - Values in composer.json
     *   - Values of environment variables (if set)
     *
     * As an example, environment variables will override whatever you've set
     * in composer.json.
     */
    protected function configure()
    {
        // Set default values for each of the configuration options.
        $config = [
            'patching-enabled' => TRUE,
            'dependency-patching-enabled' => TRUE,
            'stop-on-patch-failure' => TRUE,
            'ignore-packages' => [],
        ];

        // Grab the configuration from composer.json and override defaults as
        // appropriate & tell the user if they've done something unexpected.
        $extra = $this->composer->getPackage()->getExtra();
        if (isset($extra['patches-config'])) {
            foreach ($extra['patches-config'] as $option => $value) {
                if (array_key_exists($option, $config)) {
                    $config[$option] = $value;
                } else {
                    throw new \InvalidArgumentException("Option $option is not a valid composer-patches option.");
                }
            }
        }

        // Environment variables have to be handled manually because everything
        // in a shell is a string, and we don't necessarily want string values
        // for everything.
        if (getenv('COMPOSER_PATCHES_PATCHING_ENABLED') !== FALSE) {
            $config['patching-enabled'] = $this->castEnvvarToBool(getenv('COMPOSER_PATCHES_PATCHING_ENABLED'), $config['patching-enabled']);
        }
        if (getenv('COMPOSER_PATCHES_DEPENDENCY_PATCHING_ENABLED') !== FALSE) {
            $config['dependency-patching-enabled'] = $this->castEnvvarToBool(getenv('COMPOSER_PATCHES_DEPENDENCY_PATCHING_ENABLED'), $config['dependency-patching-enabled']);
        }
        if (getenv('COMPOSER_PATCHES_STOP_ON_PATCH_FAILURE') !== FALSE) {
            $config['stop-on-patch-failure'] = $this->castEnvvarToBool(getenv('COMPOSER_PATCHES_STOP_ON_PATCH_FAILURE'), $config['stop-on-patch-failure']);
        }
        if (getenv('COMPOSER_PATCHES_IGNORE_PACKAGES') !== FALSE) {
            $config['ignore-packages'] = $this->castEnvvarToArray(getenv('COMPOSER_PATCHES_IGNORE_PACKAGES'), $config['ignore-packages']);
        }

        // Finally, save the config values.
        $this->config = $config;
    }

    /**
     * Get a boolean value from the environment.
     *
     * @param string $value
     *   The value retrieved from the environment.
     * @param bool $default
     *   The default value to use if we can't figure out what the user wants.
     *
     * @return bool
     */
    public function castEnvvarToBool($value, $default)
    {
        // Everything is strtolower()'d because that cuts the number of cases
        // to look for in half.
        $value = trim(strtolower($value));

        // If it looks false-y, return FALSE.
        if ($value == 'false' || $value == '0' || $value == 'no') {
            return FALSE;
        }

        // If it looks truth-y, return TRUE.
        if ($value == 'true' || $value == '1' || $value == 'yes') {
            return TRUE;
        }

        // Otherwise, just return the default value that we were given. Ain't
        // nobody got time to look for a million different ways of saying yes
        // or no.
        return $default;
    }

    /**
     * Get an array from the environment.
     *
     * @param string $value
     *   The value retrieved from the environment.
     * @param array $default
     *   The default value to use if we can't figure out what the user wants.
     *
     * @return array
     */
    public function castEnvvarToArray($value, $default)
    {
        // Trim any extra whitespace and then split the string on commas.
        $value = explode(',', trim($value));

        // Strip any empty values.
        $value = array_filter($value);

        // If we didn't get anything from the supplied value, better to just use the default.
        if (empty($value)) {
            return $default;
        }

        // Return the array.
        return $value;
    }

    /**
     * Retrieve the value of a specific configured value.
     *
     * @param $name
     *   The name of the configuration key to retrieve.
     *
     * @return mixed
     */
    public function getConfigValue($name)
    {
        if (isset($this->config[$name])) {
            return $this->config[$name];
        }

        throw new \InvalidArgumentException("Config key $name does not exist.");
    }
}
