<?php

namespace Friendica\App;

use Friendica\Core\Config\Cache\ConfigCache;
use Friendica\Database\Database;
use Friendica\Util\BasePath;

/**
 * Mode of the current Friendica Node
 *
 * @package Friendica\App
 */
class Mode
{
	const LOCALCONFIGPRESENT  = 1;
	const DBAVAILABLE         = 2;
	const DBCONFIGAVAILABLE   = 4;
	const MAINTENANCEDISABLED = 8;

	/***
	 * @var int The mode of this Application
	 *
	 */
	private $mode;

	/**
	 * @var bool True, if the call is a backend call
	 */
	private $isBackend;

	public function __construct(int $mode = 0, bool $isBackend = false)
	{
		$this->mode      = $mode;
		$this->isBackend = $isBackend;
	}

	/**
	 * Sets the App mode
	 *
	 * - App::MODE_INSTALL    : Either the database connection can't be established or the config table doesn't exist
	 * - App::MODE_MAINTENANCE: The maintenance mode has been set
	 * - App::MODE_NORMAL     : Normal run with all features enabled
	 *
	 * @return Mode returns the determined mode
	 *
	 * @throws \Exception
	 */
	public function determine(BasePath $basepath, Database $database, ConfigCache $configCache)
	{
		$mode = 0;

		$basepathName = $basepath->getPath();

		if (!file_exists($basepathName . '/config/local.config.php')
		    && !file_exists($basepathName . '/config/local.ini.php')
		    && !file_exists($basepathName . '/.htconfig.php')) {
			return new Mode($mode);
		}

		$mode |= Mode::LOCALCONFIGPRESENT;

		if (!$database->connected()) {
			return new Mode($mode);
		}

		$mode |= Mode::DBAVAILABLE;

		if ($database->fetchFirst("SHOW TABLES LIKE 'config'") === false) {
			return new Mode($mode);
		}

		$mode |= Mode::DBCONFIGAVAILABLE;

		if (!empty($configCache->get('system', 'maintenance')) ||
		    // Don't use Config or Configuration here because we're possibly BEFORE initializing the Configuration,
		    // so this could lead to a dependency circle
		    !empty($database->selectFirst('config', ['v'], ['cat' => 'system', 'k' => 'maintenance'])['v'])) {
			return new Mode($mode);
		}

		$mode |= Mode::MAINTENANCEDISABLED;

		return new Mode($mode, $this->isBackend);
	}

	/**
	 * Checks if the site is called via a backend process
	 *
	 * @param Module $module The pre-loaded module (just name, not class!)
	 * @param array $server The $_SERVER variable
	 *
	 * @return Mode returns the determined mode
	 */
	public function determineBackend(Module $module, array $server)
	{
		$isBackend = basename(($server['PHP_SELF'] ?? ''), '.php') !== 'index' ||
		             $module->isBackend();

		return new Mode($this->mode, $isBackend);
	}

	/**
	 * Checks, if the Friendica Node has the given mode
	 *
	 * @param int $mode A mode to test
	 *
	 * @return bool returns true, if the mode is set
	 */
	public function has($mode)
	{
		return ($this->mode & $mode) > 0;
	}


	/**
	 * Install mode is when the local config file is missing or the DB schema hasn't been installed yet.
	 *
	 * @return bool
	 */
	public function isInstall()
	{
		return !$this->has(Mode::LOCALCONFIGPRESENT) ||
		       !$this->has(MODE::DBCONFIGAVAILABLE);
	}

	/**
	 * Normal mode is when the local config file is set, the DB schema is installed and the maintenance mode is off.
	 *
	 * @return bool
	 */
	public function isNormal()
	{
		return $this->has(Mode::LOCALCONFIGPRESENT) &&
		       $this->has(Mode::DBAVAILABLE) &&
		       $this->has(Mode::DBCONFIGAVAILABLE) &&
		       $this->has(Mode::MAINTENANCEDISABLED);
	}

	/**
	 * Returns true, if the call is from a backend node (f.e. from a worker)
	 *
	 * @return bool Is it a backend call
	 */
	public function isBackend()
	{
		return $this->isBackend;
	}
}
