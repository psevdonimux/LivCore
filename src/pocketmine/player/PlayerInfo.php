<?php

namespace pocketmine\player;

use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\utils\TextFormat;

class PlayerInfo{

 public function __construct(
  private DataPacket $packet
 ){
  $this->packet = $packet;
 }
 public function getPacket() : DataPacket{
  return $this->packet;
 }
 public function getName() : string{
  return TextFormat::clean($this->packet->username);
 }
}