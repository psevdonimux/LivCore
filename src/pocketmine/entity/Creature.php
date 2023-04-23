<?php

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\level\Level;
use pocketmine\math\Vector3;

abstract class Creature extends Living{

public int $attackingTick = 0;

public function onUpdate($tick){
if(!$this instanceof Human){
if($this->attackingTick > 0){
$this->attackingTick--;
}
if(!$this->isAlive() and $this->hasSpawned){
++$this->deadTicks;
if($this->deadTicks >= 20){
$this->despawnFromAll();
}
return true;
}
if($this->isAlive()){
$this->motionY -= $this->gravity;
$this->move($this->motionX, $this->motionY, $this->motionZ);
$friction = 1 - $this->drag;
if($this->onGround and (abs($this->motionX) > 0.00001 or abs($this->motionZ) > 0.00001)){
$friction = $this->getLevel()->getBlock($this->temporalVector->setComponents((int) floor($this->x), (int) floor($this->y - 1), (int) floor($this->z) - 1))->getFrictionFactor() * $friction;
}
$this->motionX *= $friction;
$this->motionY *= 1 - $this->drag;
$this->motionZ *= $friction;
if($this->onGround){
$this->motionY *= -0.5;
}
$this->updateMovement();
}
}
parent::entityBaseTick();
return parent::onUpdate($tick);
}
public function willMove($distance = 36){
foreach($this->getViewers() as $viewer){
if($this->distance($viewer->getLocation()) <= $distance) return true;
}
return false;
}
public function attack($damage, EntityDamageEvent $source){
parent::attack($damage, $source);
if(!$source->isCancelled() and $source->getCause() == EntityDamageEvent::CAUSE_ENTITY_ATTACK){
$this->attackingTick = 20;
}
}
}