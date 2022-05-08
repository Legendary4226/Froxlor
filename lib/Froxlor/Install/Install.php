<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     Froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

namespace Froxlor\Install;

use Exception;
use Froxlor\Install\Install\Core;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\Config\ConfigParser;
use Froxlor\Validate\Validate;

class Install
{
	public $currentStep;
	public $maxSteps;
	public $phpVersion;
	public $formfield;
	public string $requiredVersion = '7.4.0';
	public array $requiredExtensions = ['session', 'ctype', 'xml', 'filter', 'posix', 'mbstring', 'curl', 'gmp', 'json'];
	public array $suggestedExtensions = ['bcmath', 'zip'];
	public array $suggestions = [];
	public array $criticals = [];
	public array $loadedExtensions;
	public array $supportedOS = [];
	public array $webserverBackend = [
		'php-fpm' => 'PHP-FPM',
		'fcgid' => 'FCGID',
		'mod_php' => 'mod_php (not recommended)',
	];

	public function __construct()
	{
		// get all supported OS
		// show list of available distro's
		$distros = glob(dirname(__DIR__, 3) . '/lib/configfiles/*.xml');
		$distributions_select[''] = '-';
		// read in all the distros
		foreach ($distros as $distribution) {
			// get configparser object
			$dist = new ConfigParser($distribution);
			// store in tmp array
			$this->supportedOS[str_replace(".xml", "", strtolower(basename($distribution)))] = $dist->getCompleteDistroName();
		}
		// sort by distribution name
		asort($this->supportedOS);

		// guess distribution and webserver to preselect in formfield
		$guessedDistribution = $this->guessDistribution();
		$guessedWebserver = $this->guessWebserver();

		// set formfield, so we can get the fields and steps etc.
		$this->formfield = require dirname(__DIR__, 3) . '/lib/formfields/install/formfield.install.php';

		// set actual step
		$this->currentStep = Request::get('step', 0);
		$this->maxSteps = count($this->formfield['install']['sections']);

		// set actual php version and extensions
		$this->phpVersion = phpversion();
		$this->loadedExtensions = get_loaded_extensions();

		// set global variables
		UI::twig()->addGlobal('install_mode', true);
		UI::twig()->addGlobal('basehref', '../');

		// unset session if user goes back to step 0
		if (isset($_SESSION['installation']) && $this->currentStep == 0) {
			unset($_SESSION['installation']);
		}
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	public function handle(): void
	{
		// handle form data
		if (!is_null(Request::get('submit')) && $this->currentStep) {
			try {
				$this->handleFormData($this->formfield['install']);
			} catch (Exception $e) {
				$error = $e->getMessage();
			}
		}

		// load template
		UI::twigBuffer('/install/index.html.twig', [
			'setup' => [
				'step' => $this->currentStep,
			],
			'preflight' => $this->checkExtensions(),
			'page' => [
				'title' => 'Database',
				'description' => 'Test',
			],
			'section' => $this->formfield['install']['sections']['step' . $this->currentStep] ?? [],
			'error' => $error ?? null,
		]);

		// output view
		UI::twigOutputBuffer();
	}

	/**
	 * @throws Exception
	 */
	private function handleFormData(array $formfield): void
	{
		// Validate user data
		$validatedData = $this->validateRequest($formfield['sections']['step' . $this->currentStep]['fields']);

		// handle current step
		if ($this->currentStep <= $this->maxSteps) {
			// Check database connection (
			if ($this->currentStep == 1) {
				$this->checkDatabase($validatedData);
			}
			// Check validity of admin user data
			elseif ($this->currentStep == 2) {
				$this->checkAdminUser($validatedData);
			}
			// Check validity of system data
			elseif ($this->currentStep == 3) {
				$this->checkSystem($validatedData);
			}
			// Store validated data for later use
			$_SESSION['installation'] = array_merge($_SESSION['installation'] ?? [], $validatedData);
		}

		// also handle completion of installation if it's the step before the last step
		if ($this->currentStep == ($this->maxSteps - 1)) {
			$core = new Core($_SESSION['installation']);
			$core->doInstall();
			// @todo no going back after this point!
		}

		// redirect user to home if the installation is done
		if ($this->currentStep == $this->maxSteps) {
			header('Location: ../');
			return;
		}

		// redirect to next step
		header('Location: ?step=' . ($this->currentStep + 1));
	}

	/**
	 * @return array
	 */
	private function checkExtensions(): array
	{
		// check for required extensions
		foreach ($this->requiredExtensions as $requiredExtension) {
			if (in_array($requiredExtension, $this->loadedExtensions)) {
				continue;
			}
			$this->criticals['missing_extensions'][] = $requiredExtension;
		}

		// check for suggested extensions
		foreach ($this->suggestedExtensions as $suggestedExtension) {
			if (in_array($suggestedExtension, $this->loadedExtensions)) {
				continue;
			}
			$this->suggestions['missing_extensions'][] = $suggestedExtension;
		}

		return [
			'text' => $this->getInformationText(),
			'suggestions' => $this->suggestions,
			'criticals' => $this->criticals,
		];
	}

	/**
	 * @return string
	 */
	private function getInformationText(): string
	{
		if (version_compare($this->requiredVersion, PHP_VERSION, "<")) {
			$text = lng('install.phpinfosuccess', [$this->phpVersion]);
		} else {
			$text = lng('install.phpinfowarn', [$this->requiredVersion]);
			$this->criticals[] = lng('install.phpinfoupdate', [$this->phpVersion, $this->requiredVersion]);
		}
		return $text;
	}

	/**
	 * @throws Exception
	 */
	private function validateRequest(array $fields): array
	{
		$attributes = [];
		foreach ($fields as $name => $field) {
			$attributes[$name] = $this->validateAttribute(Request::get($name), $field);
			if (isset($field['next_to'])) {
				$attributes = array_merge($attributes, $this->validateRequest($field['next_to']));
			}
		}
		return $attributes;
	}

	/**
	 * @return mixed
	 * @throws Exception
	 */
	private function validateAttribute($attribute, array $field)
	{
		// TODO: do validations
		if (isset($field['mandatory']) && $field['mandatory'] && empty($attribute)) {
			throw new Exception('Mandatory field is not set!');
		}
		return $attribute;
	}

	/**
	 * @throws Exception
	 */
	private function checkSystem(array $validatedData): void
	{
		$serverip = $validatedData['serverip'] ?? '';
		$servername = $validatedData['servername'] ?? '';
		$httpuser = $validatedData['httpuser'] ?? 'www-data';
		$httpgroup = $validatedData['httpgroup'] ?? 'www-data';

		if (!Validate::validate_ip2($serverip, true, '', false, true)) {
			throw new Exception(lng('error.invalidip', [$serverip]));
		} elseif (!Validate::validateDomain($servername) && !Validate::validateLocalHostname($servername)) {
			throw new Exception(lng('install.errors.servernameneedstobevalid'));
		} elseif (posix_getpwnam($httpuser) === false) {
			throw new Exception(lng('install.errors.websrvuserdoesnotexist'));
		} elseif (posix_getgrnam($httpgroup) === false) {
			throw new Exception(lng('install.errors.websrvgrpdoesnotexist'));
		}
	}

	/**
	 * @throws Exception
	 */
	private function checkAdminUser(array $validatedData): void
	{
		$name = $validatedData['admin_name'] ?? 'Administrator';
		$loginname = $validatedData['admin_user'] ?? '';
		$email = $validatedData['admin_email'] ?? '';
		$password = $validatedData['admin_pass'] ?? '';
		$password_confirm = $validatedData['admin_pass_confirm'] ?? '';

		if (!preg_match('/^[^\r\n\t\f\0]*$/D', $name)) {
			throw new Exception(lng('error.stringformaterror', ['admin_name']));
		} elseif (empty(trim($loginname)) || !preg_match('/^[a-z][a-z0-9]+$/', $loginname)) {
			throw new Exception(lng('error.loginnameiswrong', [$loginname]));
		} elseif (empty(trim($email)) || !Validate::validateEmail($email)) {
			throw new Exception(lng('error.emailiswrong', [$email]));
		} elseif (empty($password) || $password != $password_confirm) {
			throw new Exception(lng('error.newpasswordconfirmerror'));
		} elseif (!empty($password) && $password == $loginname) {
			throw new Exception(lng('error.passwordshouldnotbeusername'));
		}
	}

	/**
	 * @throws Exception
	 */
	private function checkDatabase(array $validatedData): void
	{
		$dsn = sprintf('mysql:host=%s;charset=utf8', $validatedData['mysql_host']);
		$pdo = new \PDO($dsn, $validatedData['mysql_root_user'], $validatedData['mysql_root_pass']);

		// check if the database already exist
		$stmt = $pdo->prepare('SHOW DATABASES LIKE ?');
		$stmt->execute([
			$validatedData['mysql_database']
		]);
		$hasDatabase = $stmt->fetch();
		if ($hasDatabase && !$validatedData['mysql_force_create']) {
			throw new Exception(lng('install.errors.databaseexists'));
		}

		// check if we can create a new database
		$testDatabase = uniqid('froxlor_tmp_');
		if ($pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $testDatabase . ';') === false) {
			throw new Exception(lng('install.errors.unabletocreatedb'));
		}
		if ($pdo->exec('DROP DATABASE IF EXISTS ' . $testDatabase . ';') === false) {
			throw new Exception(lng('install.errors.unabletodropdb'));
		}

		// check if the user already exist
		$stmt = $pdo->prepare("SELECT `User` FROM `mysql`.`user` WHERE `User` = ?");
		$stmt->execute([$validatedData['mysql_unprivileged_user']]);
		if ($stmt->rowCount() && !$validatedData['mysql_force_create']) {
			throw new Exception(lng('install.errors.mysqlusernameexists'));
		}

		// check if we can create a new user
		$testUser = uniqid('froxlor_tmp_');
		$stmt = $pdo->prepare('CREATE USER ?@? IDENTIFIED BY ?');
		if ($stmt->execute([$testUser, $validatedData['mysql_host'], uniqid()]) === false) {
			throw new Exception(lng('install.errors.unabletocreateuser'));
		}
		$stmt = $pdo->prepare('DROP USER ?@?');
		if ($stmt->execute([$testUser, $validatedData['mysql_host']]) === false) {
			throw new Exception(lng('install.errors.unabletodropuser'));
		}
		if ($pdo->prepare('FLUSH PRIVILEGES')->execute() === false) {
			throw new Exception(lng('install.errors.unabletoflushprivs'));
		}

		// @todo build and set $validatedData['mysql_access_host']
	}

	private function guessWebserver(): ?string
	{
		if (strtoupper(@php_sapi_name()) == "APACHE2HANDLER" || stristr($_SERVER['SERVER_SOFTWARE'], "apache/2")) {
			return 'apache24';
		} elseif (substr(strtoupper(@php_sapi_name()), 0, 8) == "LIGHTTPD" || stristr($_SERVER['SERVER_SOFTWARE'], "lighttpd")) {
			return 'lighttpd';
		} elseif (substr(strtoupper(@php_sapi_name()), 0, 8) == "NGINX" || stristr($_SERVER['SERVER_SOFTWARE'], "nginx")) {
			return 'nginx';
		}
		return null;
	}

	private function guessDistribution(): ?string
	{
		// set default os.
		$os_dist = array(
			'VERSION_CODENAME' => 'bullseye'
		);
		// read os-release
		if (@file_exists('/etc/os-release')) {
			$os_dist = parse_ini_file('/etc/os-release', false);
			return strtolower($os_dist['VERSION_CODENAME'] ?? ($os_dist['ID'] ?? null));
		}
		return null;
	}
}