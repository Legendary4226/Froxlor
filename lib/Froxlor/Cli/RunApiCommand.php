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

namespace Froxlor\Cli;

use Exception;
use PDO;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Froxlor\Database\Database;

final class RunApiCommand extends CliCommand
{

	protected function configure()
	{
		$this->setName('froxlor:api-call');
		$this->setDescription('Run an API command as given user');
		$this->addArgument('user', InputArgument::REQUIRED, 'Loginname of the user you want to run the command as')
			->addArgument('api-command', InputArgument::REQUIRED, 'The command to execute in the form "Module.function"')
			->addArgument('parameters', InputArgument::OPTIONAL, 'Paramaters to pass to the command as JSON array');
		$this->addOption('show-params', 's', InputOption::VALUE_NONE, 'Show possible parameters for given api-command (given command will *not* be called)');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$result = self::SUCCESS;

		$result = $this->validateRequirements($input, $output);

		if ($result == self::SUCCESS) {
			try {
				$loginname = $input->getArgument('user');
				$userinfo = $this->getUserByName($loginname);
				$command = $input->getArgument('api-command');
				$apicmd = $this->validateCommand($command);
				$module = "\\Froxlor\\Api\\Commands\\" . $apicmd['class'];
				$function = $apicmd['function'];
				if ($input->getOption('show-params') !== false) {
					$json_result = \Froxlor\Api\Commands\Froxlor::getLocal($userinfo, ['module' => $apicmd['class'], 'function' => $function])->listFunctions();
					$io = new SymfonyStyle($input, $output);
					$result = $this->outputParamsList($json_result, $io);
				} else {
					$params_json = $input->getArgument('parameters');
					$params = json_decode($params_json ?? '', true);
					$json_result = $module::getLocal($userinfo, $params)->{$function}();
					$output->write($json_result);
					$result = self::SUCCESS;
				}
			} catch (Exception $e) {
				$output->writeln('<error>' . $e->getMessage() . '</>');
				$result = self::FAILURE;
			}
		}

		return $result;
	}

	private function outputParamsList(string $json, SymfonyStyle $io): int
	{
		$docs = json_decode($json, true);
		$docs = array_shift($docs['data']);
		if (!isset($docs['params'])) {
			$io->warning(($docs['head'] ?? "unknown return"));
			return self::INVALID;
		}
		if (empty($docs['params'])) {
			$io->success("No parameters required");
		} else {
			$rows = [];
			foreach ($docs['params'] as $param) {
				$rows[] = [$param['type'], '<options=bold>' . $param['parameter'] . '</>', $param['desc'] ?? ""];
			}
			$io->table(['Type', 'Name', 'Description'], $rows);
		}
		return self::SUCCESS;
	}

	private function validateCommand(string $command): array
	{
		$command = explode(".", $command);

		if (count($command) != 2) {
			throw new Exception("The given command is invalid.");
		}
		// simply check for file-existance, as we do not want to use our autoloader because this way
		// it will recognize non-api classes+methods as valid commands
		$apiclass = '\\Froxlor\\Api\\Commands\\' . $command[0];
		if (!class_exists($apiclass) || !@method_exists($apiclass, $command[1])) {
			throw new Exception("Unknown command");
		}
		return ['class' => $command[0], 'function' => $command[1]];
	}

	private function getUserByName(?string $loginname): array
	{
		if (empty($loginname)) {
			throw new Exception("Empty username");
		}

		$stmt = Database::prepare("
			SELECT `loginname` AS `customer`
			FROM `" . TABLE_PANEL_CUSTOMERS . "`
			WHERE `loginname`= :loginname
		");
		Database::pexecute($stmt, [
			"loginname" => $loginname
		]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($row && $row['customer'] == $loginname) {
			$table = "`" . TABLE_PANEL_CUSTOMERS . "`";
			$adminsession = '0';
		} else {
			$stmt = Database::prepare("
				SELECT `loginname` AS `admin` FROM `" . TABLE_PANEL_ADMINS . "`
				WHERE `loginname`= :loginname
			");
			Database::pexecute($stmt, [
				"loginname" => $loginname
			]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($row && $row['admin'] == $loginname) {
				$table = "`" . TABLE_PANEL_ADMINS . "`";
				$adminsession = '1';
			} else {
				throw new Exception("Unknown user '" . $loginname . "'");
			}
		}

		$userinfo_stmt = Database::prepare("
			SELECT * FROM $table
			WHERE `loginname`= :loginname
		");
		Database::pexecute($userinfo_stmt, [
			"loginname" => $loginname
		]);
		$userinfo = $userinfo_stmt->fetch(PDO::FETCH_ASSOC);
		$userinfo['adminsession'] = $adminsession;

		if ($userinfo['deactivated']) {
			throw new Exception("User '" . $loginname . "' is currently deactivated");
		}

		return $userinfo;
	}
}