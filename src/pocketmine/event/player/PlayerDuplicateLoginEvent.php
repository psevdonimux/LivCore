<?php

namespace pocketmine\event\player;

use pocketmine\event\Cancellable;
use pocketmine\event\player\PlayerEvent;
use pocketmine\Player;

class PlayerDuplicateLoginEvent extends PlayerEvent implements Cancellable
{
	public static $handlerList = null;
	private $connectingPlayer;
	private $existingPlayer;
	private $disconnectMessage = "Logged in from another location";

	public function __construct(Player $connectingPlayer, Player $existingPlayer){
		$this->connectingPlayer = $connectingPlayer;
		$this->existingPlayer = $existingPlayer;
	}

	public function getConnectingPlayer()
	{
		return $this->connectingPlayer;
	}

	public function getExistingPlayer()
	{
		return $this->existingPlayer;
	}

	public function getDisconnectMessage()
	{
		return $this->disconnectMessage;
	}

	public function setDisconnectMessage(string $message)
	{
		$this->disconnectMessage = $message;
	}
}
