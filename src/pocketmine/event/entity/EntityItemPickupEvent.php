<?php //support pmmp class

namespace pocketmine\event\entity;

use pocketmine\event\player\PlayerEvent;
use pocketmine\event\Cancellable;

class EntityItemPickupEvent extends PlayerEvent implements Cancellable{

 public static $handlerList = null;
 public $item;

 public function __construct($player, $item){
  $this->player = $player;
  $this->item = $item;
 }
 public function getItem(){
  return $this->item;
 }
 public function getEntity(){
  return $this->player;
 }
}