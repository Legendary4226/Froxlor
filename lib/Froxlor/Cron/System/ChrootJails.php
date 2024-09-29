<?php

namespace Froxlor\Cron\System;

use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\FroxlorLogger;

class ChrootJails
{
	public static function run(): void
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'ChrootCron: started - creating customer chroot jail');

		$sql = "SELECT customerid FROM " . TABLE_PANEL_CUSTOMERS . " WHERE chroo_enabled = 1;";
		$stmt = Database::query($sql);
		if (!$stmt->execute()) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, 'ChrootCron: err - retrieve customer id');
			return;
		}

		foreach ($stmt->fetchAll() as $customerId) {
			self::runCustomer($customerId);
		}
	}

	private static function runCustomer(int $customerId): void
	{
		$sql = "SELECT loginname, documentroot, guid, password?, diskspace, allowed_phpconfigs FROM panel_customers WHERE customerid = :customerid;";
		$stmt = Database::prepare($sql);
		try {
			Database::pexecute($stmt, [
				'customerid' => $customerId,
			]);
		} catch (\Exception $e) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, 'ChrootCron: err - retrieve customer info ' . $e->getMessage());
			return;
		}
		$infos = $stmt->fetchObject();

		// I assume $infos['username'] is a unique string
		$chrootFolder = self::getChrootDirectory($infos->loginname);

		// TODO How to know if I need to create/update/remove a jail ?
		if ($infos['createJail'] === true) {
			self::jailCreate($chrootFolder, $infos->loginname);
		} elseif ($infos['removeJail'] === true) {
			self::jailDelete($chrootFolder, $infos->loginname);
		} elseif ($infos['updateJail'] === true) {
			self::jailUpdate($chrootFolder, $infos->loginname);
		}
	}

	private static function jailCreate(string $chrootFolder, string $username): void
	{
		FileDir::safe_exec("sudo bash ./JailBin/make_jail.sh $chrootFolder $username");
	}

	private static function jailDelete(string $chrootFolder, string $username): void
	{
		// TODO Unbind user data mounts before deleting the entire jail
		FileDir::safe_exec("umount $chrootFolder/home/$username");
		FileDir::safe_exec("sudo rm -r $chrootFolder");
	}

	private static function jailUpdate(string $chrootFolder, string $username): void
	{
		self::jailDelete($chrootFolder, $username);
		self::jailCreate($chrootFolder, $username);
	}

	private static function getChrootDirectory(string $username): string
	{
		return "/var/jails/$username";
	}
}
