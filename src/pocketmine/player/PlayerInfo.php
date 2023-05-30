<?php

namespace pocketmine\player;

use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\utils\{TextFormat, UUID};

class PlayerInfo{

 public function __construct(
  private DataPacket $packet
 ){
  $this->packet = $packet;
 }
 public function getPacket() : DataPacket{
  return $this->packet;
 }
 public function getUsername() : string{
  return TextFormat::clean($this->getPacket()->username);
 }
 public function getLowerCaseName() : string{
  return strtolower($this->getName());
 }
 public function getProtocol() : int{
  return $this->getPacket()->protocol;
 }
 public function getDeviceModel() : string{
  return $this->getPacket()->deviceModel;
 } 
 public function getDeviceOs() : int{
  return $this->getPacket()->deviceOS;
 }
 public function getClientId() : int{
  return $this->getPacket()->clientId;
 }
 public function getLanguageCode() : string{
  return $this->getPacket()->languageCode;
 } 
 public function getXuid() : ?string{
  return $this->getPacket()->identityPublicKey;
 }
 public function getUniqueId() : string{
  return UUID::fromString($this->getPacket()->clientUUID);
 }
 public function getRawUniqueId() : string{
  return $this->getUuid()->toBinary();
 }
 public function getSkinData() : string{
  return $this->getPacket()->skin;
 }
 public function getSkinId() : string{
  return $this->getPacket()->skinId;
 }
}