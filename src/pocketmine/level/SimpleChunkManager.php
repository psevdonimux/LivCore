<?php declare(strict_types=1);

namespace pocketmine\level;

use pocketmine\block\Block;
use pocketmine\level\format\Chunk;
use pocketmine\math\Vector3;

class SimpleChunkManager implements ChunkManager {

 protected array $chunks = [];
 protected int|string $seed;
 protected int $waterHeight = 0, $worldHeight;

 public function __construct($seed, $waterHeight = 0, int $worldHeight = Level::Y_MAX){
  $this->seed = $seed;
  $this->waterHeight = $waterHeight;
  $this->worldHeight = $worldHeight;
 }
 public function getWaterHeight() : int{
  return $this->waterHeight;
 }
	public function getBlockIdAt(int $x, int $y, int $z) : int{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			return $chunk->getBlockId($x & 0xf, $y, $z & 0xf);
		}
		return 0;
	}
	public function setBlockIdAt(int $x, int $y, int $z, int $id) : void{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			$chunk->setBlockId($x & 0xf, $y, $z & 0xf, $id);
		}
	}
	public function getBlockDataAt(int $x, int $y, int $z) : int{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			return $chunk->getBlockData($x & 0xf, $y, $z & 0xf);
		}
		return 0;
	}
	public function setBlockDataAt(int $x, int $y, int $z, int $data) : void{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			$chunk->setBlockData($x & 0xf, $y, $z & 0xf, $data);
		}
	}
	public function getBlockLightAt(int $x, int $y, int $z) : int{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			return $chunk->getBlockLight($x & 0x0f, $y & 0x7f, $z & 0x0f);
		}
		return 0;
	}
	public function setBlockLightAt(int $x, int $y, int $z, int $level) : void{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			$chunk->setBlockLight($x & 0x0f, $y & 0x7f, $z & 0x0f, $level & 0x0f);
		}
	}
	public function getBlockSkyLightAt(int $x, int $y, int $z) : int{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			return $chunk->getBlockSkyLight($x & 0xf, $y, $z & 0xf);
		}
		return 0;
	}
	public function setBlockSkyLightAt(int $x, int $y, int $z, int $level) : void{
		if($chunk = $this->getChunk($x >> 4, $z >> 4)){
			$chunk->setBlockSkyLight($x & 0xf, $y, $z & 0xf, $level);
		}
	}
	public function updateBlockLight(int $x, int $y, int $z) : void{
		$lightPropagationQueue = new \SplQueue();
		$lightRemovalQueue = new \SplQueue();
		$visited = [];
		$removalVisited = [];
		$oldLevel = $this->getBlockLightAt($x, $y, $z);
		$newLevel = (int) Block::$light[$this->getBlockIdAt($x, $y, $z)];
		if($oldLevel !== $newLevel){
			$this->setBlockLightAt($x, $y, $z, $newLevel);
			if($newLevel < $oldLevel){
				$removalVisited[Level::blockHash($x, $y, $z)] = true;
				$lightRemovalQueue->enqueue([new Vector3($x, $y, $z), $oldLevel]);
			}else{
				$visited[Level::blockHash($x, $y, $z)] = true;
				$lightPropagationQueue->enqueue(new Vector3($x, $y, $z));
			}
		}
		while(!$lightRemovalQueue->isEmpty()){
			$val = $lightRemovalQueue->dequeue();
			$node = $val[0];
			$lightLevel = $val[1];
			$this->computeRemoveBlockLight($node->x - 1, $node->y, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x + 1, $node->y, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y - 1, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y + 1, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y, $node->z - 1, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y, $node->z + 1, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
		}
		while(!$lightPropagationQueue->isEmpty()){
			$node = $lightPropagationQueue->dequeue();
			$lightLevel = $this->getBlockLightAt($node->x, $node->y, $node->z) - (int) Block::$lightFilter[$this->getBlockIdAt($node->x, $node->y, $node->z)];
			if($lightLevel >= 1){
				$this->computeSpreadBlockLight($node->x - 1, $node->y, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x + 1, $node->y, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y - 1, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y + 1, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y, $node->z - 1, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y, $node->z + 1, $lightLevel, $lightPropagationQueue, $visited);
			}
		}
	}
	private function computeRemoveBlockLight($x, $y, $z, $currentLight, \SplQueue $queue, \SplQueue $spreadQueue, array &$visited, array &$spreadVisited) : void{
		$current = $this->getBlockLightAt($x, $y, $z);
		if($current !== 0 and $current < $currentLight){
			$this->setBlockLightAt($x, $y, $z, 0);
			if(!isset($visited[$index = Level::blockHash($x, $y, $z)])){
				$visited[$index] = true;
				if($current > 1){
					$queue->enqueue([new Vector3($x, $y, $z), $current]);
				}
			}
		}elseif($current >= $currentLight){
			if(!isset($spreadVisited[$index = Level::blockHash($x, $y, $z)])){
				$spreadVisited[$index] = true;
				$spreadQueue->enqueue(new Vector3($x, $y, $z));
			}
		}
	}
	private function computeSpreadBlockLight($x, $y, $z, $currentLight, \SplQueue $queue, array &$visited) : void{
		$current = $this->getBlockLightAt($x, $y, $z);
		if($current < $currentLight){
			$this->setBlockLightAt($x, $y, $z, $currentLight);
			if(!isset($visited[$index = Level::blockHash($x, $y, $z)])){
				$visited[$index] = true;
				if($currentLight > 1){
					$queue->enqueue(new Vector3($x, $y, $z));
				}
			}
		}
	}
	public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
		return isset($this->chunks[$index = Level::chunkHash($chunkX, $chunkZ)]) ? $this->chunks[$index] : null;
	}
	public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk = null) : void{
		if($chunk === null){
			unset($this->chunks[Level::chunkHash($chunkX, $chunkZ)]);
			return;
		}
		$this->chunks[Level::chunkHash($chunkX, $chunkZ)] = $chunk;
	}
	public function cleanChunks() : void{
		$this->chunks = [];
	}
	public function getSeed() : int|string{
		return $this->seed;
	}
	public function getMaxY() {
		return $this->worldHeight;
	}
	public function getWorldHeight() : int{
		return $this->worldHeight;
	}
	public function isInWorld(int $x, int $y, int $z) : bool{
		return (
			$x <= INT32_MAX and $x >= INT32_MIN and
			$y < $this->worldHeight and $y >= 0 and
			$z <= INT32_MAX and $z >= INT32_MIN
		);
	}
}
