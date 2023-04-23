<?php

namespace pocketmine\entity;

use pocketmine\Player;
use pocketmine\level\Level;
use pocketmine\network\mcpe\protocol\AddEntityPacket;
use pocketmine\Server;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\level\Position;
use pocketmine\event\Listener;
use pocketmine\nbt\tag\ListTag;
use pocketmine\level\Explosion;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\plugin\PluginBase;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\level\sound\ExplodeSound;
use pocketmine\level\sound\AnvilFallSound;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\particle\HugeExplodeSeedParticle;
use pocketmine\event\entity\EntityDamageByEntityEvent;

class EnderCrystal extends Vehicle{
	
   const NETWORK_ID = 71;

   public $height = 0.7;
   public $width = 1.6;
   public $gravity = 0.5;
   public $drag = 0.1;

   public function __construct(Level $level, CompoundTag $nbt){
    parent::__construct($level, $nbt);
   }

   public function spawnTo(Player $p){
    $packet = new AddEntityPacket();
	$packet->eid = $this->getId();
	$packet->type = EnderCrystal::NETWORK_ID;
	$packet->x = $this->x;
	$packet->y = $this->y;
	$packet->z = $this->z;
	$packet->speedX = 0;
	$packet->speedY = 0;
	$packet->speedZ = 0;
	$packet->yaw = 0;
	$packet->pitch = 0;
	$packet->metadata = $this->dataProperties;
	$p->dataPacket($packet);
	parent::spawnTo($p);
   }
}
