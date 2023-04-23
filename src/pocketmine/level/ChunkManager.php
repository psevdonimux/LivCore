<?php declare(strict_types=1);

namespace pocketmine\level;

use pocketmine\level\format\Chunk;

interface ChunkManager {

 public function getBlockIdAt(int $x, int $y, int $z) : int;
 public function setBlockIdAt(int $x, int $y, int $z, int $id) : void;
 public function getBlockDataAt(int $x, int $y, int $z) : int;
 public function setBlockDataAt(int $x, int $y, int $z, int $data) : void;
 public function getBlockLightAt(int $x, int $y, int $z) : int;
 public function updateBlockLight(int $x, int $y, int $z) : void;
 public function setBlockLightAt(int $x, int $y, int $z, int $level) : void;
 public function getBlockSkyLightAt(int $x, int $y, int $z) : int;
 public function setBlockSkyLightAt(int $x, int $y, int $z, int $level) : void;
 public function getChunk(int $chunkX, int $chunkZ) : ?Chunk;
 public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk = null) : void;
 public function getSeed() : int|string;
 public function getWorldHeight() : int;
 public function isInWorld(int $x, int $y, int $z) : bool;
}