<?php

/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

namespace pocketmine\entity;

use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\Player;

class MinecartHopper extends Animal {
	const NETWORK_ID = 96;

public $height = 0.7;
    public $width = 0.98;

    public $drag = 0.1;
    public $gravity = 0.5;

	/**
	 * @return string
	 */
	public function getName() : string{
		return "Minecart Hopper";
	}
public function initEntity(){
        $this->setMaxHealth(1);
        $this->setHealth($this->getMaxHealth());
        parent::initEntity();
    }

	/**
	 * @return int
	 */
	public function getType() : int{
		return self::TYPE_HOPPER;
	}

	/**
	 * @param Player $player
	 */
	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = MinecartHopper::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = 0;
		$pk->speedY = 0;
		$pk->speedZ = 0;
		$pk->yaw = 0;
		$pk->pitch = 0;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		Entity::spawnTo($player);
	}
}
