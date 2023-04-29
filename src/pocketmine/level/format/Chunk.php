<?php declare(strict_types = 1);

namespace pocketmine\level\format;

use pocketmine\block\Block;
use pocketmine\entity\{Entity, XPOrb};
use pocketmine\level\format\io\ChunkException;
use pocketmine\level\Level;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\tile\{Spawnable, Tile};
use pocketmine\utils\BinaryStream;

class Chunk{

    public const MAX_SUBCHUNKS = 16;

    protected int $x, $z, $height = Chunk::MAX_SUBCHUNKS;
    protected bool $hasChanged = false, $isInit = false, $lightPopulated = false, $terrainGenerated = false, $terrainPopulated = false;
    protected \SplFixedArray $subChunks, $heightMap;
    protected EmptySubChunk $emptySubChunk;
    protected array $tiles = [], $tileList = [], $entities = [], $extraData = [], $NBTtiles = [], $NBTentities = [];
    protected string $biomeIds;

    public function __construct(int $chunkX, int $chunkZ, array $subChunks = [], array $entities = [], array $tiles = [], string $biomeIds = '', array $heightMap = []){
        $this->x = $chunkX;
        $this->z = $chunkZ;
        $this->height = Chunk::MAX_SUBCHUNKS; //TODO: add a way of changing this
        $this->subChunks = new \SplFixedArray($this->height);
        $this->emptySubChunk = EmptySubChunk::getInstance();
        foreach($this->subChunks as $y => $null){
			$this->subChunks[$y] = $subChunks[$y] ?? $this->emptySubChunk;
        }
        if(count($heightMap) === 256){
            $this->heightMap = \SplFixedArray::fromArray($heightMap);
        }else{
            assert(count($heightMap) === 0, "Wrong HeightMap value count, expected 256, got " . count($heightMap));
            $val = ($this->height * 16);
            $this->heightMap = \SplFixedArray::fromArray(array_fill(0, 256, $val));
        }
        if(strlen($biomeIds) === 256){
            $this->biomeIds = $biomeIds;
        }else{
            assert(strlen($biomeIds) === 0, "Wrong BiomeIds value count, expected 256, got " . strlen($biomeIds));
            $this->biomeIds = str_repeat("\x00", 256);
        }
        $this->NBTtiles = $tiles;
        $this->NBTentities = $entities;
    }
    public function getX() : int{
        return $this->x;
    }
    public function getZ() : int{
        return $this->z;
    }
    public function setX(int $x) : void{
        $this->x = $x;
    }
    public function setZ(int $z) : void{
        $this->z = $z;
    }
    public function getHeight() : int{
        return $this->height;
    }
    public function getFullBlock(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getFullBlock($x, $y & 0x0f, $z);
    }
    public function setBlock(int $x, int $y, int $z, $blockId = null, $meta = null) : bool{
        if($this->getSubChunk($y >> 4, true)->setBlock($x, $y & 0x0f, $z, $blockId !== null ? ($blockId & 0xff) : null, $meta !== null ? ($meta & 0x0f) : null)){
            $this->hasChanged = true;
            return true;
        }
        return false;
    }
    public function getBlockId(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockId($x, $y & 0x0f, $z);
    }
    public function setBlockId(int $x, int $y, int $z, int $id) : void{
        if($this->getSubChunk($y >> 4, true)->setBlockId($x, $y & 0x0f, $z, $id)){
            $this->hasChanged = true;
        }
    }
    public function getBlockData(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockData($x, $y & 0x0f, $z);
    }
    public function setBlockData(int $x, int $y, int $z, int $data) : void{
        if($this->getSubChunk($y >> 4, true)->setBlockData($x, $y & 0x0f, $z, $data)){
            $this->hasChanged = true;
        }
    }
    public function getBlockExtraData(int $x, int $y, int $z) : int{
        return $this->extraData[Chunk::chunkBlockHash($x, $y, $z)] ?? 0;
    }
    public function setBlockExtraData(int $x, int $y, int $z, int $data) : void{
        if($data === 0){
            unset($this->extraData[Chunk::chunkBlockHash($x, $y, $z)]);
        }else{
            $this->extraData[Chunk::chunkBlockHash($x, $y, $z)] = $data;
        }
        $this->hasChanged = true;
    }
    public function getBlockSkyLight(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockSkyLight($x, $y & 0x0f, $z);
    }
    public function setBlockSkyLight(int $x, int $y, int $z, int $level) : void{
        if($this->getSubChunk($y >> 4, true)->setBlockSkyLight($x, $y & 0x0f, $z, $level)){
            $this->hasChanged = true;
        }
    }
    public function setAllBlockSkyLight(int $level) : void{
        $char = chr(($level & 0x0f) | ($level << 4));
        $data = str_repeat($char, 2048);
        for($y = $this->getHighestSubChunkIndex(); $y >= 0; --$y){
            $this->getSubChunk($y, true)->setBlockSkyLightArray($data);
        }
    }
    public function getBlockLight(int $x, int $y, int $z) : int{
        return $this->getSubChunk($y >> 4)->getBlockLight($x, $y & 0x0f, $z);
    }
    public function setBlockLight(int $x, int $y, int $z, int $level) : void{
        if($this->getSubChunk($y >> 4, true)->setBlockLight($x, $y & 0x0f, $z, $level)){
            $this->hasChanged = true;
        }
    }
    public function setAllBlockLight(int $level) : void{
        $char = chr(($level & 0x0f) | ($level << 4));
        $data = str_repeat($char, 2048);
        for($y = $this->getHighestSubChunkIndex(); $y >= 0; --$y){
            $this->getSubChunk($y, true)->setBlockLightArray($data);
        }
    }
    public function getHighestBlockAt(int $x, int $z) : int{
        $index = $this->getHighestSubChunkIndex();
        if($index === -1){
            return -1;
        }
        for($y = $index; $y >= 0; --$y){
            $height = $this->getSubChunk($y)->getHighestBlockAt($x, $z) | ($y << 4);
            if($height !== -1){
                return $height;
            }
        }
        return -1;
    }
    public function getMaxY() : int{
        return ($this->getHighestSubChunkIndex() << 4) | 0x0f;
    }
    public function getHeightMap(int $x, int $z) : int{
        return $this->heightMap[($z << 4) | $x];
    }
    public function setHeightMap(int $x, int $z, int $value) : void{
        $this->heightMap[($z << 4) | $x] = $value;
    }
    public function recalculateHeightMap() : void{
        for($z = 0; $z < 16; ++$z){
            for($x = 0; $x < 16; ++$x){
                $this->recalculateHeightMapColumn($x, $z);
            }
        }
    }
    public function recalculateHeightMapColumn(int $x, int $z) : int{
        $y = $this->getHighestBlockAt($x, $z);
        for(; $y >= 0; --$y){
            if(Block::$lightFilter[$id = $this->getBlockId($x, $y, $z)] > 1 or Block::$diffusesSkyLight[$id]){
                break;
            }
        }
        $this->setHeightMap($x, $z, $y + 1);
        return $y + 1;
    }
    public function populateSkyLight() : void{
        $maxY = $this->getMaxY();
        $this->setAllBlockSkyLight(0);
        for($x = 0; $x < 16; ++$x){
            for($z = 0; $z < 16; ++$z){
                $y = $maxY;
                $heightMap = $this->getHeightMap($x, $z);   
                for(; $y >= $heightMap; --$y){
                    $this->setBlockSkyLight($x, $y, $z, 15);
                }
                $light = 15;
                for(; $y >= 0; --$y){
                    $light -= Block::$lightFilter[$this->getBlockId($x, $y, $z)];
					if($light <= 0){
						break;
                    }
                    $this->setBlockSkyLight($x, $y, $z, $light);
                }
            }
        }
    }
    public function getBiomeId(int $x, int $z) : int{
        return ord($this->biomeIds[($z << 4) | $x]);
    }
    public function setBiomeId(int $x, int $z, int $biomeId) : void{
        $this->hasChanged = true;
        $this->biomeIds[($z << 4) | $x] = chr($biomeId & 0xff);
    }
    public function getBlockIdColumn(int $x, int $z) : string{
        $result = '';
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockIdColumn($x, $z);
        }
        return $result;
    }
    public function getBlockDataColumn(int $x, int $z) : string{
        $result = '';
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockDataColumn($x, $z);
        }
        return $result;
    }
    public function getBlockSkyLightColumn(int $x, int $z) : string{
        $result = '';
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getSkyLightColumn($x, $z);
        }
        return $result;
    }
    public function getBlockLightColumn(int $x, int $z) : string{
        $result = '';
        foreach($this->subChunks as $subChunk){
            $result .= $subChunk->getBlockLightColumn($x, $z);
        }
        return $result;
    }
    public function isLightPopulated() : bool{
        return $this->lightPopulated;
    }
    public function setLightPopulated(bool $value = true) : void{
        $this->lightPopulated = $value;
        $this->hasChanged = true;
    }
    public function isPopulated() : bool{
        return $this->terrainPopulated;
    }
    public function setPopulated(bool $value = true) : void{
        $this->terrainPopulated = $value;
        $this->hasChanged = true;
    }
    public function isGenerated() : bool{
        return $this->terrainGenerated;
    }
    public function setGenerated(bool $value = true) : void{
        $this->terrainGenerated = $value;
        $this->hasChanged = true;
    }
    public function addEntity(Entity $entity) : void{
    	if($entity->isClosed() and !($entity instanceof XPOrb)){ //TODO: очень тупой костыль
            throw new \InvalidArgumentException("Attempted to add a garbage closed Entity to a chunk");
        }
        $this->entities[$entity->getId()] = $entity;
        if(!($entity instanceof Player) and $this->isInit){
            $this->hasChanged = true;
        }
    }
    public function removeEntity(Entity $entity) : void{
        unset($this->entities[$entity->getId()]);
        if(!($entity instanceof Player) and $this->isInit){
            $this->hasChanged = true;
        }
    }
    public function addTile(Tile $tile) : void{
    	if($tile->isClosed()){
            throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to a chunk");
        }
        $this->tiles[$tile->getId()] = $tile;
        if(isset($this->tileList[$index = (($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]) and $this->tileList[$index] !== $tile){
            $this->tileList[$index]->close();
        }
        $this->tileList[$index] = $tile;
        if($this->isInit){
            $this->hasChanged = true;
        }
    }
    public function removeTile(Tile $tile) : void{
        unset($this->tiles[$tile->getId()]);
        unset($this->tileList[(($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]);
        if($this->isInit){
            $this->hasChanged = true;
        }
   }
    public function getEntities() : array{
        return $this->entities;
    }
    public function getSavableEntities() : array{
        return array_filter($this->entities, function(Entity $entity) : bool{ return $entity->canSaveWithChunk() and !$entity->isClosed(); });
    }
    public function getTiles() : array{
        return $this->tiles;
   }
    public function onUnload() : void{
        foreach($this->getEntities() as $entity){
            if($entity instanceof Player){
                continue;
            }
            $entity->close();
        }
        foreach($this->getTiles() as $tile){
            $tile->close();
        }
    }
    public function getTile(int $x, int $y, int $z) : ?Tile{
        $index = ($x << 12) | ($z << 8) | $y;
        return $this->tileList[$index] ?? null;
    }
    public function unload() : void{
        foreach($this->getEntities() as $entity){
            if($entity instanceof Player){
                continue;
            }
            $entity->close();
        }
        foreach($this->getTiles() as $tile){
            $tile->close();
        }
    }
    public function initChunk(Level $level) : void{
        if(!$this->isInit){
            $changed = false;
            $level->timings->syncChunkLoadEntitiesTimer->startTiming();
            foreach($this->NBTentities as $nbt){
                if($nbt instanceof CompoundTag){
                    if(!isset($nbt->id) or ($nbt['Pos'][0] >> 4) !== $this->x or ($nbt['Pos'][2] >> 4) !== $this->z or !Entity::createEntity($nbt['id'], $level, $nbt) instanceof Entity){
                        $changed = true;
                        continue;
                    }
                }
            }
            $this->NBTentities = [];
            $level->timings->syncChunkLoadEntitiesTimer->stopTiming();
            $level->timings->syncChunkLoadTileEntitiesTimer->startTiming();
            foreach($this->NBTtiles as $nbt){
                if($nbt instanceof CompoundTag){
                    if(!isset($nbt->id) or ($nbt['x'] >> 4) !== $this->x or ($nbt['z'] >> 4) !== $this->z or Tile::createTile($nbt['id'], $level, $nbt) === null){
                        $changed = true;
                        continue;
                    }              
                }
            }
            $this->NBTtiles = [];
            $level->timings->syncChunkLoadTileEntitiesTimer->stopTiming();
            $this->hasChanged = $changed;
            $this->isInit = true;
        }
    }
    public function getBiomeIdArray() : string{
        return $this->biomeIds;
    }
    public function getHeightMapArray() : array{
        return $this->heightMap->toArray();
    }
    public function getBlockExtraDataArray() : array{
        return $this->extraData;
    }
    public function hasChanged() : bool{
        return $this->hasChanged;
    }
    public function setChanged(bool $value = true) : void{
        $this->hasChanged = $value;
    }
    public function getSubChunk(int $y, bool $generateNew = false) : SubChunkInterface{
        if($y < 0 or $y >= $this->height){
            return $this->emptySubChunk;
        }elseif($generateNew and $this->subChunks[$y] instanceof EmptySubChunk){
            $this->subChunks[$y] = new SubChunk();
        }
        return $this->subChunks[$y];
    }
    public function setSubChunk(int $y, SubChunkInterface $subChunk = null, bool $allowEmpty = false) : bool{
        if($y < 0 or $y >= $this->height){
            return false;
        }
        if($subChunk === null or ($subChunk->isEmpty() and !$allowEmpty)){
            $this->subChunks[$y] = $this->emptySubChunk;
        }else{
            $this->subChunks[$y] = $subChunk;
        }
        $this->hasChanged = true;
        return true;
    }
	public function getSubChunks() : \SplFixedArray{
		return $this->subChunks;
	}
    public function getHighestSubChunkIndex() : int{
        for($y = $this->subChunks->count() - 1; $y >= 0; --$y){
			if($this->subChunks[$y] instanceof EmptySubChunk){
                continue;
            }
            return $y;
        }
        return -1;
    }
    public function getSubChunkSendCount() : int{
        return $this->getHighestSubChunkIndex() + 1;
    }
    public function collectGarbage() : void{
        foreach($this->subChunks as $y => $subChunk){
            if($subChunk instanceof SubChunk){
                if($subChunk->isEmpty()){
                    $this->subChunks[$y] = $this->emptySubChunk;
                }else{
                    $subChunk->collectGarbage();
                }
            }
        }
    }
    public function networkSerialize() : string{
        $result = '';
        $subChunkCount = $this->getSubChunkSendCount();
        $result .= chr($subChunkCount);
        for($y = 0; $y < $subChunkCount; ++$y){
            $result .= $this->subChunks[$y]->networkSerialize();
        }
        $result .= pack('v*', ...$this->heightMap). $this->biomeIds. chr(0); 
        $extraData = new BinaryStream();
        $extraData->putVarInt(count($this->extraData)); //WHY, Mojang, WHY
        foreach($this->extraData as $key => $value){
            $extraData->putVarInt($key);
            $extraData->putLShort($value);
        }
        $result .= $extraData->getBuffer();
        foreach($this->tiles as $tile){
			if($tile instanceof Spawnable){
				$result .= $tile->getSerializedSpawnCompound();
            }
        }
        return $result;
    }
    public function fastSerialize() : string{
        $stream = new BinaryStream();
        $stream->putInt($this->x);
        $stream->putInt($this->z);
        $stream->putByte(($this->lightPopulated ? 4 : 0) | ($this->terrainPopulated ? 2 : 0) | ($this->terrainGenerated ? 1 : 0));
        if($this->terrainGenerated){
            $count = 0;
            $subChunks = '';
            foreach($this->subChunks as $y => $subChunk){
                if($subChunk instanceof EmptySubChunk){
                    continue;
                }
                ++$count;
                $subChunks .= chr($y) . $subChunk->getBlockIdArray() . $subChunk->getBlockDataArray();
                if($this->lightPopulated){
                    $subChunks .= $subChunk->getSkyLightArray() . $subChunk->getBlockLightArray();
                }
            }
            $stream->putByte($count);
            $stream->put($subChunks);
            $stream->put($this->biomeIds);
            if($this->lightPopulated){
                $stream->put(pack('v*', ...$this->heightMap));
            }
        }
        return $stream->getBuffer();
    }
    public static function fastDeserialize(string $data) : Chunk{
        $stream = new BinaryStream($data);
        $x = $stream->getInt();
        $z = $stream->getInt();
        $flags = $stream->getByte();
        $lightPopulated = (bool) ($flags & 4);
        $terrainPopulated = (bool) ($flags & 2);
        $terrainGenerated = (bool) ($flags & 1);
        $subChunks = [];
        $biomeIds = '';
        $heightMap = [];
        if($terrainGenerated){
            $count = $stream->getByte();
            for($y = 0; $y < $count; ++$y){
                $subChunks[$stream->getByte()] = new SubChunk(
                    $stream->get(4096), //blockids
                    $stream->get(2048), //blockdata
                    $lightPopulated ? $stream->get(2048) : '', //skylight
                    $lightPopulated ? $stream->get(2048) : '' //blocklight
                );
            }
            $biomeIds = $stream->get(256);
            if($lightPopulated){
                $unpackedHeightMap = unpack('v*', $stream->get(512)); //unpack() will never fail here
                $heightMap = array_values($unpackedHeightMap);
            }
        }
        $chunk = new Chunk($x, $z, $subChunks, [], [], $biomeIds, $heightMap);
        $chunk->setGenerated($terrainGenerated);
        $chunk->setPopulated($terrainPopulated);
        $chunk->setLightPopulated($lightPopulated);
        $chunk->setChanged(false);
        return $chunk;
    }
    public static function chunkBlockHash(int $x, int $y, int $z) : int{
        return ($x << 12) | ($z << 8) | $y;
    }
}