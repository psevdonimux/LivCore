<?php

namespace pocketmine\command;

use pocketmine\event\TextContainer;
use pocketmine\permission\{PermissibleBase, PermissionAttachment, Permission};
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\utils\MainLogger;

class ConsoleCommandSender implements CommandSender {

 private PermissibleBase $perm;

 public function __construct(){
  $this->perm = new PermissibleBase($this);
 }
 public function isPermissionSet(Permission|string $name) : bool{
  return $this->perm->isPermissionSet($name);
 }
 public function hasPermission(Permission|string $name) : bool{
  return $this->perm->hasPermission($name);
 }
 public function addAttachment(Plugin $plugin, $name = null, $value = null) : ?PermissionAttachment{
  return $this->perm->addAttachment($plugin, $name, $value);
 }
 public function removeAttachment(PermissionAttachment $attachment) : void{
  $this->perm->removeAttachment($attachment);
 }
 public function recalculatePermissions() : void{
  $this->perm->recalculatePermissions();
 }
 public function getEffectivePermissions() : array{
  return $this->perm->getEffectivePermissions();
 }
 public function getServer() : Server{
  return Server::getInstance();
 }
 public function sendMessage(mixed $message) : void{
  if($message instanceof TextContainer){
   $message = $this->getServer()->getLanguage()->translate($message);
  }
  else{
   $message = $this->getServer()->getLanguage()->translateString($message);
  }
  foreach(explode("\n", trim($message)) as $line){
   MainLogger::getLogger()->info($line);
  }
 }
 public function getName() : string{
  return 'CONSOLE';
 }
 public function isOp() : bool{
  return true;
 }
 public function setOp(bool $value) : void{}
}