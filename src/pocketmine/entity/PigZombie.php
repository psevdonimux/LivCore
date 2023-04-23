<?php

/*
 * _      _ _        _____               
 *| |    (_) |      / ____|              
 *| |     _| |_ ___| |     ___  _ __ ___ 
 *| |    | | __/ _ \ |    / _ \| '__/ _ \
 *| |____| | ||  __/ |___| (_) | | |  __/
 *|______|_|\__\___|\_____\___/|_|  \___|
 *
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author genisyspromcpe
 * @link https://github.com/LiteCoreTeam/LiteCore
 *
 *
*/

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item as ItemItem;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\level\Level;

class PigZombie extends Monster {
	const NETWORK_ID = 36;

	public $width = 0.6;
	public $length = 0.6;
	public $height = 0;

	public $drag = 0.2;
	public $gravity = 0.3;

	public $dropExp = [5, 5];
	
	private $step = 0.2;
	private $motionVector = null;
	private $farest = null;
	private $attackTicks = 0;

	/**
	 * @return string
	 */
	public function getName() : string{
		return "PigZombie";
	}
	public function initEntity(){
		$this->setMaxHealth(11);
		parent::initEntity();
	}

	/**
	 * @param Player $player
	 */
	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = PigZombie::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);

		$pk = new MobEquipmentPacket();
		$pk->eid = $this->getId();
		$pk->item = new ItemItem(283);
		$pk->slot = 0;
		$pk->selectedSlot = 0;

		$player->dataPacket($pk);
	}

	/**
	 * @return array
	 */
	public function getDrops(){
		$cause = $this->lastDamageCause;
		if($cause instanceof EntityDamageByEntityEvent){
			$damager = $cause->getDamager();
			if($damager instanceof Player){
				$lootingL = $damager->getItemInHand()->getEnchantmentLevel(Enchantment::TYPE_WEAPON_LOOTING);
				if(mt_rand(1, 200) <= (5 + 2 * $lootingL)){
					$rnd = mt_rand(0, 1);
					if($rnd == 0){
						$drops[] = ItemItem::get(ItemItem::GOLD_INGOT, 0, 1);
					}elseif($rnd == 1){
						$drops[] = ItemItem::get(ItemItem::DRAGONS_BREATH, 0, 1);
					}
				}
				$drops[] = ItemItem::get(ItemItem::GOLD_NUGGET, 0, mt_rand(0, 1 + $lootingL));
				$drops[] = ItemItem::get(ItemItem::ROTTEN_FLESH, 0, mt_rand(0, 1 + $lootingL));

				return $drops;
			}
		}
}
}

	