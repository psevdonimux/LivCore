<?php declare(strict_types=1);

namespace pocketmine\level\generator;

use pocketmine\level\format\Chunk;
use pocketmine\level\{Level, SimpleChunkManager};
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class PopulationTask extends AsyncTask{

 public bool $state;
 public int $levelId;
 public string $chunk, $chunk0, $chunk1, $chunk2, $chunk3, $chunk5, $chunk6, $chunk7, $chunk8;

 public function __construct(Level $level, Chunk $chunk){
  $this->state = true;
  $this->levelId = $level->getId();
  $this->chunk = $chunk->fastSerialize();
 }
 public function onRun() : void{
  $manager = $this->getFromThreadStore('generation.level'. $this->levelId. '.manager');
  $generator = $this->getFromThreadStore('generation.level'. $this->levelId. '.generator');
  if(!($manager instanceof SimpleChunkManager) or !($generator instanceof Generator)){
   $this->state = false;
   return;
  }
  $chunk2 = Chunk::fastDeserialize($this->chunk);
  $x = $chunk2->getX();
  $z = $chunk2->getZ();
  $manager->setChunk($x, $z, $chunk2);
  $chunk = $manager->getChunk($x, $z);
  if(!$chunk2->isGenerated()){
   $generator->generateChunk($x, $z);  
   $chunk->setGenerated();
  }
  $generator->populateChunk($chunk->getX(), $chunk->getZ());
  $chunk->setPopulated();
  $chunk->recalculateHeightMap();
  $chunk->setLightPopulated();
  $this->chunk = $chunk->fastSerialize();
  $manager->cleanChunks();
 }
 public function onCompletion(Server $server) : void{
  $level = $server->getLevel($this->levelId);
   if($level !== null){
  if(!$this->state){
   $level->registerGenerator();
  }
  $chunk = Chunk::fastDeserialize($this->chunk);
  $level->generateChunkCallback($chunk->getX(), $chunk->getZ(), $this->state ? $chunk : null);
   }
 }
}
