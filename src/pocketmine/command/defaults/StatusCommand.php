<?php declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\utils\{TextFormat, Utils};

class StatusCommand extends VanillaCommand{

 public function __construct(string $name){
  parent::__construct($name, '%pocketmine.command.status.description', '%pocketmine.command.status.usage');
  $this->setPermission('pocketmine.command.status');
 }
 public function execute(CommandSender $sender, $currentAlias, array $args){
  if(!$this->testPermission($sender)) return true;
  $rUsage = Utils::getRealMemoryUsage();
  $mUsage = Utils::getMemoryUsage(true);
  $server = $sender->getServer(); 
  $tpsColor = TextFormat::GREEN;
  if($server->getTicksPerSecond() < 17){
  $tpsColor = TextFormat::GOLD;
  }
  elseif($server->getTicksPerSecond() < 12){
  $tpsColor = TextFormat::RED;
  }
  $sender->sendMessage(TextFormat::GREEN. '---- '. TextFormat::WHITE . '%pocketmine.command.status.title'. TextFormat::GREEN . ' ----'. "\n".
  TextFormat::GOLD . '%pocketmine.command.status.uptime ' . TextFormat::RED . $server->getUptime(). "\n".
  TextFormat::GOLD . '%pocketmine.command.status.CurrentTPS ' . $tpsColor . $server->getTicksPerSecond() . ' (' . $server->getTickUsage() . '%)'. "\n".
  TextFormat::GOLD . '%pocketmine.command.status.AverageTPS ' . $tpsColor . $server->getTicksPerSecondAverage() . ' (' . $server->getTickUsageAverage() . '%)'. "\n".
  TextFormat::GOLD . '%pocketmine.command.status.player'. TextFormat::GREEN . ' ' . count($server->getOnlinePlayers()) . '/' . $sender->getServer()->getMaxPlayers(). "\n".
  TextFormat::GOLD . '%pocketmine.command.status.Networkupload '. TextFormat::RED . round($server->getNetwork()->getUpload() / 1024, 2) . ' kB/s'. "\n". 
  TextFormat::GOLD . '%pocketmine.command.status.Networkdownload '. TextFormat::RED . round($server->getNetwork()->getDownload() / 1024, 2) . ' kB/s'. "\n".
  TextFormat::GOLD . '%pocketmine.command.status.Threadcount ' . TextFormat::RED . Utils::getThreadCount(). "\n". 
  TextFormat::GOLD . '%pocketmine.command.status.Mainmemory '. TextFormat::RED . number_format(round(($mUsage[0] / 1024) / 1024, 2), 2) . ' MB.'. "\n". 
  TextFormat::GOLD . '%pocketmine.command.status.Totalmemory '. TextFormat::RED . number_format(round(($mUsage[1] / 1024) / 1024, 2), 2) . ' MB.'. "\n". 
  TextFormat::GOLD . '%pocketmine.command.status.Totalvirtualmemory ' . TextFormat::RED . number_format(round(($mUsage[2] / 1024) / 1024, 2), 2) . ' MB.'. "\n".
  TextFormat::GOLD . '%pocketmine.command.status.Heapmemory '. TextFormat::RED . number_format(round(($rUsage[0] / 1024) / 1024, 2), 2) . ' MB.');
  if($server->getProperty('memory.global-limit') > 0){
   $sender->sendMessage(TextFormat::GOLD . '%pocketmine.command.status.Maxmemorymanager ' . TextFormat::RED . number_format(round($server->getProperty('memory.global-limit'), 2), 2) . ' MB.');
  }
  foreach($server->getLevels() as $level){
   $levelName = $level->getFolderName() !== $level->getName() ? ' (' . $level->getName() . ')' : '';
   $timeColor = $level->getTickRateTime() > 40 ? TextFormat::RED : TextFormat::YELLOW;
   $sender->sendMessage(TextFormat::GOLD . "Мир \"{$level->getFolderName()}\"$levelName: " .
   TextFormat::RED . number_format(count($level->getChunks())) . TextFormat::GREEN . ' %pocketmine.command.status.chunks '.
   TextFormat::RED . number_format(count($level->getEntities())) . TextFormat::GREEN . ' %pocketmine.command.status.entities '.
   TextFormat::RED . number_format(count($level->getTiles())) . TextFormat::GREEN . ' %pocketmine.command.status.tiles ' .
   '%pocketmine.command.status.Time ' . round($level->getTickRateTime(), 2) . 'ms'
	);
  }
  return true;
 }
}
