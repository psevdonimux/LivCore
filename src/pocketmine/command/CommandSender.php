<?php

namespace pocketmine\command;

use pocketmine\permission\Permissible;
use pocketmine\Server;

interface CommandSender extends Permissible {

 public function sendMessage(mixed $message) : void;
 public function getServer() : Server;
 public function getName() : string;
}