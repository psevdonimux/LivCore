<?php

/*
 *
 *  _____            _               _____           
 * / ____|          (_)             |  __ \          
 *| |  __  ___ _ __  _ ___ _   _ ___| |__) | __ ___  
 *| | |_ |/ _ \ '_ \| / __| | | / __|  ___/ '__/ _ \ 
 *| |__| |  __/ | | | \__ \ |_| \__ \ |   | | | (_) |
 * \_____|\___|_| |_|_|___/\__, |___/_|   |_|  \___/ 
 *                         __/ |                    
 *                        |___/                     
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author GenisysPro
 * @link https://github.com/GenisysPro/GenisysPro
 *
 *
*/

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\Server;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

class MakeServerCommand extends VanillaCommand {

	/**
	 * MakeServerCommand constructor.
	 *
	 * @param $name
	 */
	public function __construct($name){
		parent::__construct(
			$name,
			"Creates a PocketMine Phar",
			"/makeserver"
		);
		$this->setPermission("pocketmine.command.makeserver");
	}

	/**
	 * @param CommandSender $sender
	 * @param string        $commandLabel
	 * @param array         $args
	 *
	 * @return bool
	 */
	public function execute(CommandSender $sender, $commandLabel, array $args){
		if(!$this->testPermission($sender)){
			return false;
		}
		$server = $sender->getServer();
		$pharPath = Server::getInstance()->getPluginPath() . 'PocketMine-MP.phar';
		if(file_exists($pharPath)){
			$sender->sendMessage('Phar file already exists, overwriting...');
			@unlink($pharPath);
		}
		$phar = new \Phar($pharPath);
		$phar->setStub("<?php require_once('phar://'. __FILE__ .'/src/pocketmine/PocketMine.php');  __HALT_COMPILER();");
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		$phar->startBuffering();
		$filePath = substr(\pocketmine\PATH, 0, 7) === 'phar://' ? \pocketmine\PATH : realpath(\pocketmine\PATH) . '/';
		$filePath = rtrim(str_replace('\\', '/', $filePath), '/') . '/';
		foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath . 'src')) as $file){
			$path = ltrim(str_replace(['\\', $filePath], ['/', ''], $file), '/');
			if($path[0] === '.' or strpos($path, '/.') !== false or substr($path, 0, 4) !== 'src/'){
				continue;
			}
			$phar->addFile($file, $path);
		}
		$phar->stopBuffering();
		$sender->sendMessage('Phar file has been created on '. $pharPath);
		return true;
	}
}
