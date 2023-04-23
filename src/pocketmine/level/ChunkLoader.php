<?php

namespace pocketmine\level;

use pocketmine\block\Block;
use pocketmine\level\format\Chunk;
use pocketmine\math\Vector3;

interface ChunkLoader{

 public function getLoaderId() : ?int;
 public function isLoaderActive() : bool;
 public function getPosition() : Position;
 public function getX() : float;
 public function getZ() : float;
 public function getLevel() : ?Level;
 public function onChunkChanged(Chunk $chunk) : void;
 public function onChunkLoaded(Chunk $chunk) : void;
 public function onChunkUnloaded(Chunk $chunk) : void;
 public function onChunkPopulated(Chunk $chunk) : void;
 public function onBlockChanged(Vector3 $block) : void;
}