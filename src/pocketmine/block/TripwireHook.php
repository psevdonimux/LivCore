<?php

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\Tool;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\Player;

class TripwireHook extends Transparent {

	protected $id = self::TRIPWIRE_HOOK;

	public function __construct($meta = 0){
		$this->meta = $meta;
	}

	public function getName() : string{
		return "Tripwire Hook";
	}

	public function hasEntityCollision(){
		return true;
	}

	public function isSolid(){
		return false;
	}

	public function getHardness(){
		return 0;
	}
	
	public function place(Item $item, Block $block, Block $target, $face, $fx, $fy, $fz, Player $player = null){
		if($target->isTransparent() === false){
			$faces = [
				4 => 1,
				3 => 0,
				5 => 3,
				2 => 2,
			];
			if(isset($faces[$face])){
				$this->meta = $faces[$face];
				$this->getLevel()->setBlock($block, $this, true, true);

				return true;
			}
		}

		return false;
	}
	
	public function onUpdate($type){
		$faces = [
			0 => 2,
			2 => 3,
			1 => 5,
			3 => 4,
		];
		if($type === Level::BLOCK_UPDATE_NORMAL){
			if(isset($faces[$this->meta])){
				if($this->getSide($faces[$this->meta])->getId() === self::AIR){
					$this->getLevel()->useBreakOn($this);
				}
				return Level::BLOCK_UPDATE_NORMAL;
			}
		}
		return false;
	}
	
	
	public function getDrops(Item $item) : array{
		return [
			[$this->id, 0, 1],
		];
	}
}
