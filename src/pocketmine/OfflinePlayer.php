<?php declare(strict_types=1);

namespace pocketmine;


use pocketmine\metadata\{Metadatable, MetadataValue};
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\Plugin;

class OfflinePlayer implements IPlayer, Metadatable {

 private string $name;
 private Server $server;
 private ?CompoundTag $namedtag = null;

	public function __construct(Server $server, string $name){
		$this->server = $server;
		$this->name = $name;
		if($this->server->hasOfflinePlayerData($this->name)){
			$this->namedtag = $this->server->getOfflinePlayerData($this->name);
		}
	}
	public function isOnline() : bool{
		return $this->getPlayer() !== null;
	}
	public function getName() : string{
		return $this->name;
	}
	public function getServer() : Server{
		return $this->server;
	}
	public function isOp() : bool{
		return $this->server->isOp(strtolower($this->getName()));
	}
	public function setOp(bool $value) : void{
		if($value === $this->isOp()){
			return;
		}

		if($value){
			$this->server->addOp(strtolower($this->getName()));
		}else{
			$this->server->removeOp(strtolower($this->getName()));
		}
	}
	public function isBanned() : bool{
		return $this->server->getNameBans()->isBanned(strtolower($this->getName()));
	}
	public function setBanned(bool $value) : void{
		if($value){
			$this->server->getNameBans()->addBan($this->getName(), null, null, null);
		}else{
			$this->server->getNameBans()->remove($this->getName());
		}
	}
	public function isWhitelisted() : bool{
		return $this->server->isWhitelisted(strtolower($this->getName()));
	}
	public function setWhitelisted(bool $value) : void{
		if($value){
			$this->server->addWhitelist(strtolower($this->getName()));
		}else{
			$this->server->removeWhitelist(strtolower($this->getName()));
		}
	}
	public function getPlayer() : Player{
		return $this->server->getPlayerExact($this->getName());
	}
	public function getFirstPlayed() : ?float{
		return $this->namedtag instanceof CompoundTag ? $this->namedtag["firstPlayed"] : null;
	}
	public function getLastPlayed() : ?float{
		return $this->namedtag instanceof CompoundTag ? $this->namedtag["lastPlayed"] : null;
	}
	public function hasPlayedBefore() : bool{
		return $this->namedtag instanceof CompoundTag;
	}
	public function setMetadata(string $metadataKey, MetadataValue $newMetadataValue) : void{
		$this->server->getPlayerMetadata()->setMetadata($this, $metadataKey, $newMetadataValue);
	}
	public function getMetadata(string $metadataKey) : array{
		return $this->server->getPlayerMetadata()->getMetadata($this, $metadataKey);
	}
	public function hasMetadata(string $metadataKey) : bool{
		return $this->server->getPlayerMetadata()->hasMetadata($this, $metadataKey);
	}
	public function removeMetadata(string $metadataKey, Plugin $owningPlugin) : void{
		$this->server->getPlayerMetadata()->removeMetadata($this, $metadataKey, $owningPlugin);
	}
}