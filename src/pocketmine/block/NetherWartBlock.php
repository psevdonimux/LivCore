<?php

namespace pocketmine\block;

class NetherWartBlock extends Solid{

	protected $id = Block::NETHER_WART_BLOCK2;

	public function __construct(int $meta = 0){
		$this->meta = $meta;
	}

	public function getName() : string{
		return "Nether Wart Block";
	}

	public function getHardness() : float{
		return 1;
	}
}