<?php

namespace pocketmine\level;

use pocketmine\block\{Air, Beetroot, Block, BrownMushroom, Cactus, Carrot, CocoaBlock, Farmland, Grass, Ice, Leaves, Leaves2, MelonStem, Mycelium, NetherWart, Potato, PumpkinStem, RedMushroom, Sapling, SnowLayer, Sugarcane, Wheat};
use pocketmine\entity\{Entity, Item as DroppedItem, Lightning, XPOrb};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent, BlockUpdateEvent};
use pocketmine\event\level\{ChunkLoadEvent, ChunkPopulateEvent, ChunkUnloadEvent, LevelSaveEvent, LevelUnloadEvent, SpawnChangeEvent};
use pocketmine\event\{LevelTimings, Timings};
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\level\format\{Chunk, EmptySubChunk};
use pocketmine\level\format\io\{BaseLevelProvider, ChunkException, ChunkRequestTask, LevelProvider};
use pocketmine\level\generator\{Generator, GeneratorRegisterTask, GeneratorUnregisterTask, PopulationTask};
use pocketmine\level\light\{BlockLightUpdate, LightPopulationTask, SkyLightUpdate};
use pocketmine\level\particle\{DestroyBlockParticle, Particle};
use pocketmine\level\sound\{BlockPlaceSound, Sound};
use pocketmine\level\weather\Weather;
use pocketmine\math\{AxisAlignedBB, Vector2, Vector3};
use pocketmine\metadata\{BlockMetadataStore, Metadatable, MetadataValue};
use pocketmine\nbt\tag\{CompoundTag, DoubleTag, FloatTag, ListTag, LongTag, ShortTag, StringTag};
use pocketmine\network\mcpe\protocol\{AddEntityPacket, BatchPacket, DataPacket, LevelEventPacket, LevelSoundEventPacket, MoveEntityPacket, SetEntityMotionPacket, SetTimePacket, UpdateBlockPacket};
use pocketmine\{Player, Server};
use pocketmine\plugin\Plugin;
use pocketmine\tile\{Chest, Container, Tile};
use pocketmine\utils\{ReversePriorityQueue, Random};

#include <rules/Level.h>

class Level implements ChunkManager, Metadatable{

 const BLOCK_UPDATE_NORMAL = 1;
 const BLOCK_UPDATE_RANDOM = 2;
 const BLOCK_UPDATE_SCHEDULED = 3;
 const BLOCK_UPDATE_WEAK = 4;
 const BLOCK_UPDATE_TOUCH = 5;
 public const Y_MASK = 0xFF;
 public const Y_MAX = 0x100; //256
 public const TIME_DAY = 1000;
 public const TIME_NOON = 6000;
 public const TIME_SUNSET = 12000;
 public const TIME_NIGHT = 13000;
 public const TIME_MIDNIGHT = 18000;
 public const TIME_SUNRISE = 23000;
 public const TIME_FULL = 24000;
 public const DIMENSION_NORMAL = 0;
 public const DIMENSION_NETHER = 1;
 public const DIMENSION_END = 2;

 private static int $levelIdCounter = 1, $chunkLoaderCounter = 1;
 private array $tiles = [], $players = [], $entities = [], $blockCache = [], $chunkCache = [], $loaders = [], $loaderCounter = [], $chunkLoaders = [], $playerLoaders = [], $chunkPackets = [], $globalPackets = [], $unloadQueue = [], $chunks = [], $changedBlocks = [], $chunkSendQueue = [], $chunkSendTasks = [], $chunkPopulationQueue = [], $chunkPopulationLock = [], $blockTempData = [], $scheduledBlockUpdateQueueIndex = [], $chunkTickList = [], $randomTickBlocks = [
 Block::GRASS => Grass::class,
 Block::SAPLING => Sapling::class,
 Block::LEAVES => Leaves::class,
 Block::WHEAT_BLOCK => Wheat::class,
 Block::COCOA_BLOCK => CocoaBlock::class,
 Block::FARMLAND => Farmland::class,
 Block::SNOW_LAYER => SnowLayer::class,
 Block::ICE => Ice::class,
 Block::CACTUS => Cactus::class,
 Block::SUGARCANE_BLOCK => Sugarcane::class,
 Block::RED_MUSHROOM => RedMushroom::class,
 Block::BROWN_MUSHROOM => BrownMushroom::class,
 Block::PUMPKIN_STEM => PumpkinStem::class,
 Block::NETHER_WART_BLOCK => NetherWart::class,
 Block::MELON_STEM => MelonStem::class,
 //Block::VINE => true,
 Block::MYCELIUM => Mycelium::class,
 //Block::COCOA_BLOCK => true,
 Block::CARROT_BLOCK => Carrot::class,
 Block::POTATO_BLOCK => Potato::class,
 Block::LEAVES2 => Leaves2::class,
 Block::BEETROOT_BLOCK => Beetroot::class,
 ];
 public array $updateEntities = [], $updateTiles = [];
 private int $sendTimeTicker = 0, $levelId, $providerGarbageCollectionTicker = 0, $worldHeight, $time, $skyLightReduction = 0, $chunkPopulationQueueSize = 2, $chunkTickRadius, $chunksPerTick, $dimension = self::DIMENSION_NORMAL;
 private Server $server;
 private ?LevelProvider $provider;
 public bool $stopTime = false;
 private float $sunAnglePercentage = 0.0;
 private string $folderName, $displayName;
 private ReversePriorityQueue $scheduledBlockUpdateQueue;
 private \SplQueue $neighbourBlockUpdateQueue;
 private bool $autoSave = true, $clearChunksOnTick, $doingTick = false, $closed = false;
 private ?BlockMetadataStore $blockMetadata;
 private ?Position $temporalPosition;
 private Vector3 $temporalVector;
 private \SplFixedArray $blockStates;
 public int $sleepTicks = 0;
 public float $tickRateTime = 0;
 public LevelTimings $timings;
 private string|Generator $generator;
 private ?BlockLightUpdate $blockLightUpdate = null;
 private ?SkyLightUpdate $skyLightUpdate = null;
 private Weather $weather;

public function setBlockTempData(Vector3 $pos, $data = null) : void{
if ($data == null and isset($this->blockTempData[self::blockHash($pos->x, $pos->y, $pos->z)])) {
unset($this->blockTempData[self::blockHash($pos->x, $pos->y, $pos->z)]);
}else{
$this->blockTempData[self::blockHash($pos->x, $pos->y, $pos->z)] = $data;
}
}
public function getBlockTempData(Vector3 $pos) : int{
if (isset($this->blockTempData[self::blockHash($pos->x, $pos->y, $pos->z)])) {
return $this->blockTempData[self::blockHash($pos->x, $pos->y, $pos->z)];
}
return 0;
}
public static function chunkHash(int $x, int $z) : int{
return PHP_INT_SIZE === 8 ? (($x & 0xFFFFFFFF) << 32) | ($z & 0xFFFFFFFF) : $x . ':'. $z;
}
public static function blockHash(int $x, int $y, int $z) : int{
if($y < 0 or $y >= Level::Y_MAX){
throw new \InvalidArgumentException("Y coordinate $y is out of range!");
}
return PHP_INT_SIZE === 8 ? (($x & 0xFFFFFFF) << 36) | (($y & Level::Y_MASK) << 28) | ($z & 0xFFFFFFF) : $x . ':' . $y . ':' . $z;
}
public static function chunkBlockHash(int $x, int $y, int $z) : int{
return ($y << 8) | (($z & 0xf) << 4) | ($x & 0xf);
}
public static function getBlockXYZ(int $hash, ?int &$x, ?int &$y, ?int &$z) : void{
if (PHP_INT_SIZE === 8) {
$x = $hash >> 36;
$y = ($hash >> 28) & Level::Y_MASK; //it's always positive
$z = ($hash & 0xFFFFFFF) << 36 >> 36;
}else{
$hash = explode(':', $hash);
$x = $hash[0];
$y = $hash[1];
$z = $hash[2];
}
}
public static function getXZ(int $hash, ?int &$x, ?int &$z) : void{
if(PHP_INT_SIZE === 8) {
$x = $hash >> 32;
$z = ($hash & 0xFFFFFFFF) << 32 >> 32;
}else{
$hash = explode(':', $hash);
$x = (int) $hash[0];
$z = (int) $hash[1];
}
}
public static function generateChunkLoaderId(ChunkLoader $loader) : int {
if($loader->getLoaderId() === 0 or $loader->getLoaderId() === null){
return self::$chunkLoaderCounter++;
}else{
throw new \InvalidStateException("ChunkLoader has a loader id already assigned: " . $loader->getLoaderId());
}
}
public function __construct(Server $server, string $name, string $path, string $provider) {
$this->blockStates = Block::$fullList;
$this->levelId = static::$levelIdCounter++;
$this->blockMetadata = new BlockMetadataStore($this);
$this->server = $server;
$this->autoSave = $server->getAutoSave();
$this->folderName = $name;
$this->timings = new LevelTimings($this);
if (is_subclass_of($provider, LevelProvider::class, true)) {
$this->provider = new $provider($path, $this->timings);
}else{
throw new LevelException("Provider is not a subclass of LevelProvider");
}
$this->displayName = $this->provider->getName();
$this->worldHeight = $this->provider->getWorldHeight();
$this->server->getLogger()->info($this->server->getLanguage()->translateString("pocketmine.level.preparing", [$this->displayName]));
$this->generator = Generator::getGenerator($this->provider->getGenerator());
$this->scheduledBlockUpdateQueue = new ReversePriorityQueue();
$this->scheduledBlockUpdateQueue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
$this->neighbourBlockUpdateQueue = new \SplQueue();
$this->time = (int)$this->provider->getTime();
$this->chunkTickRadius = min($this->server->getViewDistance(), max(1, (int)$this->server->getProperty("chunk-ticking.tick-radius", 4)));
$this->chunksPerTick = (int)$this->server->getProperty("chunk-ticking.per-tick", 40);
$this->chunkPopulationQueueSize = (int)$this->server->getProperty("chunk-generation.population-queue-size", 2);
$this->clearChunksOnTick = (bool)$this->server->getProperty("chunk-ticking.clear-tick-list", true);
$this->temporalPosition = new Position(0, 0, 0, $this);
$this->temporalVector = new Vector3(0, 0, 0);
$this->weather = new Weather($this, 0);
$this->setDimension(self::DIMENSION_NORMAL);
if ($this->server->netherEnabled and 'nether' == $this->folderName)
$this->setDimension(self::DIMENSION_NETHER);
elseif ($this->server->enderEnabled and 'ender' == $this->folderName)
$this->setDimension(self::DIMENSION_END);
if ($this->server->weatherEnabled and $this->getDimension() == self::DIMENSION_NORMAL) {
$this->weather->setCanCalculate(true);
} else $this->weather->setCanCalculate(false);
}
public function setDimension(int $dimension) : void{
$this->dimension = $dimension;
}
public function getDimension() : int{
return $this->dimension;
}
public function getWeather() : Weather{
return $this->weather;
}
public function getTickRateTime() : float{
return $this->tickRateTime;
}
public function initLevel() : void{
$this->registerGenerator();
}
public function getWaterHeight() : int {
if ($this->generator instanceof Generator) {
return $this->generator->getWaterHeight();
}
return 0;
}
public function registerGenerator() : void{
for ($i = 0; $i < $this->server->getScheduler()->getAsyncTaskPoolSize(); ++$i) {
$this->server->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorRegisterTask($this, $this->generator, $this->provider->getGeneratorOptions()), $i);
}
}
public function unregisterGenerator() : void{
for ($i = 0; $i < $this->server->getScheduler()->getAsyncTaskPoolSize(); ++$i) {
$this->server->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorUnregisterTask($this), $i);
}
}
public function getBlockMetadata(): BlockMetadataStore{
return $this->blockMetadata;
}
public function getServer(): Server{
return $this->server;
}
final public function getProvider() : LevelProvider{
return $this->provider;
}
final public function getId(): int{
return $this->levelId;
}
public function isClosed(): bool{
return $this->closed;
}
public function close() : void{
if($this->closed){
throw new \InvalidStateException("Tried to close a world which is already closed");
}
foreach($this->chunks as $chunk){
$this->unloadChunk($chunk->getX(), $chunk->getZ(), false);
}
$this->save();
$this->unregisterGenerator();
$this->provider->close();
$this->provider = null;
$this->blockMetadata = null;
$this->blockCache = [];
$this->temporalPosition = null;
$this->closed = true;
}
public function addSound(Sound $sound, array $players = null) : void{
$pk = $sound->encode();
if(!is_array($pk)){
$pk = [$pk];
}
if(count($pk) > 0){
if($players === null){
foreach($pk as $e){
$this->broadcastPacketToViewers($sound, $e);
}
}else{
$this->server->batchPackets($players, $pk, false);
}
}
}
public function addParticle(Particle $particle, array $players = null) : void{
$pk = $particle->encode();
if(!is_array($pk)){
$pk = [$pk];
}
if(count($pk) > 0){
if($players === null){
foreach($pk as $e){
$this->broadcastPacketToViewers($particle, $e);
}
}else{
$this->server->batchPackets($players, $pk, false);
}
}
}
public function broadcastLevelEvent(?Vector3 $pos, int $evid, int $data = 0) : void{
$pk = new LevelEventPacket();
$pk->evid = $evid;
$pk->data = $data;
if($pos !== null){
list($pk->x, $pk->y, $pk->z) = [$pos->x, $pos->y, $pos->z];
$this->broadcastPacketToViewers($pos, $pk);
}else{
$pk->x = null;
$pk->y = null;
$pk->z = null;
$this->addGlobalPacket($pk);
}
}
public function broadcastLevelSoundEvent(Vector3 $pos, int $soundId, int $pitch = 1, int $extraData = -1) : void{
$pk = new LevelSoundEventPacket();
$pk->sound = $soundId;
$pk->pitch = $pitch;
$pk->extraData = $extraData;
list($pk->x, $pk->y, $pk->z) = [$pos->x, $pos->y, $pos->z];
$this->addChunkPacket($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4, $pk);
}
public function getAutoSave(): bool {
return $this->autoSave;
}
public function setAutoSave(bool $value) : void{
$this->autoSave = $value;
}
public function unload(bool $force = false) : bool{
if($this->doingTick and !$force){
throw new \InvalidStateException("Cannot unload a level during level tick");
}
$ev = new LevelUnloadEvent($this);
if ($this === $this->server->getDefaultLevel() and !$force) {
$ev->setCancelled(true);
}
$this->server->getPluginManager()->callEvent($ev);
if(!$force and $ev->isCancelled()){
return false;
}
$this->server->getLogger()->info($this->server->getLanguage()->translateString("pocketmine.level.unloading", [$this->getName()]));
$defaultLevel = $this->server->getDefaultLevel();
foreach ($this->getPlayers() as $player) {
if ($this === $defaultLevel or $defaultLevel === null) {
$player->close($player->getLeaveMessage(), "Forced default level unload");
}else{
$player->teleport($defaultLevel->getSafeSpawn());
}
}
if ($this === $defaultLevel) {
$this->server->setDefaultLevel(null);
}
$this->server->removeLevel($this);
$this->close();
return true;
}
public function getChunkPlayers(int $chunkX, int $chunkZ) : array{
return $this->playerLoaders[Level::chunkHash($chunkX, $chunkZ)] ?? [];
}
public function getChunkLoaders(int $chunkX, int $chunkZ) : array{
return $this->chunkLoaders[Level::chunkHash($chunkX, $chunkZ)] ?? [];
}
public function getViewersForPosition(Vector3 $pos) : array{
return $this->getChunkPlayers($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4);
}
public function addChunkPacket(int $chunkX, int $chunkZ, DataPacket $packet) : void{
if (!isset($this->chunkPackets[$index = Level::chunkHash($chunkX, $chunkZ)])) {
$this->chunkPackets[$index] = [$packet];
}else{
$this->chunkPackets[$index][] = $packet;
}
}
public function broadcastPacketToViewers(Vector3 $pos, DataPacket $packet) : void{
$this->addChunkPacket($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4, $packet);
}
public function broadcastGlobalPacket(DataPacket $packet) : void{
$this->globalPackets[] = $packet;
}
public function addGlobalPacket(DataPacket $packet) : void{
$this->globalPackets[] = $packet;
}
public function registerChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ, bool $autoLoad = true) : void{
$loaderId = $loader->getLoaderId();
if(!isset($this->chunkLoaders[$chunkHash = Level::chunkHash($chunkX, $chunkZ)])){
$this->chunkLoaders[$chunkHash] = [];
$this->playerLoaders[$chunkHash] = [];
}elseif(isset($this->chunkLoaders[$chunkHash][$loaderId])){
return;
}
$this->chunkLoaders[$chunkHash][$loaderId] = $loader;
if($loader instanceof Player){
$this->playerLoaders[$chunkHash][$loaderId] = $loader;
}
if(!isset($this->loaders[$loaderId])){
$this->loaderCounter[$loaderId] = 1;
$this->loaders[$loaderId] = $loader;
}else{
++$this->loaderCounter[$loaderId];
}
$this->cancelUnloadChunkRequest($chunkX, $chunkZ);
if ($autoLoad) {
$this->loadChunk($chunkX, $chunkZ);
}
}
public function unregisterChunkLoader(ChunkLoader $loader, int $chunkX, int $chunkZ) : void{
if (isset($this->chunkLoaders[$index = Level::chunkHash($chunkX, $chunkZ)][$hash = $loader->getLoaderId()])) {
unset($this->chunkLoaders[$index][$hash]);
unset($this->playerLoaders[$index][$hash]);
if (count($this->chunkLoaders[$index]) === 0) {
unset($this->chunkLoaders[$index]);
unset($this->playerLoaders[$index]);
$this->unloadChunkRequest($chunkX, $chunkZ, true);
}
if (--$this->loaderCounter[$hash] === 0) {
unset($this->loaderCounter[$hash]);
unset($this->loaders[$hash]);
}
}
}
public function sendTime(Player ...$targets) : void{
$pk = new SetTimePacket();
$pk->time = $this->time & 0xffffffff; //avoid overflowing the field, since the packet uses an int32
$this->server->broadcastPacket(count($targets) > 0 ? $targets : $this->players, $pk);
}
public function doTick(int $currentTick) : void{
if($this->closed){
throw new \InvalidStateException("Attempted to tick a Level which has been closed");
}
$this->timings->doTick->startTiming();
$this->doingTick = true;
try{
$this->actuallyDoTick($currentTick);
}finally{
$this->doingTick = false;
$this->timings->doTick->stopTiming();
}
}
protected function actuallyDoTick(int $currentTick) : void{
if(!$this->stopTime){
if($this->time === PHP_INT_MAX){
$this->time = PHP_INT_MIN;
}else{
$this->time++;
}
}
$this->sunAnglePercentage = $this->computeSunAnglePercentage(); //Sun angle depends on the current time
$this->skyLightReduction = $this->computeSkyLightReduction(); //Sky light reduction depends on the sun angle
if (++$this->sendTimeTicker === 200) {
$this->sendTime();
$this->sendTimeTicker = 0;
}
$this->weather->calcWeather($currentTick);
$this->unloadChunks();
if(++$this->providerGarbageCollectionTicker >= 6000){
$this->provider->doGarbageCollection();
$this->providerGarbageCollectionTicker = 0;
}
$this->timings->doTickPending->startTiming();
while($this->scheduledBlockUpdateQueue->count() > 0 and $this->scheduledBlockUpdateQueue->current()['priority'] <= $currentTick){
$vec = $this->scheduledBlockUpdateQueue->extract()['data'];
unset($this->scheduledBlockUpdateQueueIndex[Level::blockHash($vec->x, $vec->y, $vec->z)]);
if(!$this->isInLoadedTerrain($vec)){
continue;
}
$this->getBlock($vec)->onUpdate(self::BLOCK_UPDATE_SCHEDULED);
}
while($this->neighbourBlockUpdateQueue->count() > 0){
$index = $this->neighbourBlockUpdateQueue->dequeue();
Level::getBlockXYZ($index, $x, $y, $z);
$block = $this->getBlockAt($x, $y, $z);
$block->clearCaches(); //for blocks like fences, force recalculation of connected AABBs
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($block));
if(!$ev->isCancelled()){
$block->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
}
$this->timings->doTickPending->stopTiming();
$this->timings->entityTick->startTiming();
Timings::$tickEntityTimer->startTiming();
foreach ($this->updateEntities as $id => $entity) {
if ($entity->closed or !$entity->onUpdate($currentTick)) {
unset($this->updateEntities[$id]);
}
}
Timings::$tickEntityTimer->stopTiming();
$this->timings->entityTick->stopTiming();
$this->timings->tileEntityTick->startTiming();
Timings::$tickTileEntityTimer->startTiming();
foreach($this->updateTiles as $id => $tile){
if(!$tile->onUpdate()){
unset($this->updateTiles[$id]);
}
}
Timings::$tickTileEntityTimer->stopTiming();
$this->timings->tileEntityTick->stopTiming();
$this->timings->doTickTiles->startTiming();
$this->tickChunks();
$this->timings->doTickTiles->stopTiming();
$this->executeQueuedLightUpdates();
if (count($this->changedBlocks) > 0) {
if (count($this->players) > 0) {
foreach ($this->changedBlocks as $index => $blocks) {
if(count($blocks) === 0){ //blocks can be set normally and then later re-set with direct send
continue;
}
unset($this->chunkCache[$index]);
Level::getXZ($index, $chunkX, $chunkZ);
if (count($blocks) > 512) {
$chunk = $this->getChunk($chunkX, $chunkZ);
foreach ($this->getChunkPlayers($chunkX, $chunkZ) as $p) {
$p->onChunkChanged($chunk);
}
}else{
$this->sendBlocks($this->getChunkPlayers($chunkX, $chunkZ), $blocks, UpdateBlockPacket::FLAG_ALL);
}
}
}else{
$this->chunkCache = [];
}
$this->changedBlocks = [];
}
$this->processChunkRequest();
if ($this->sleepTicks > 0 and --$this->sleepTicks <= 0) {
$this->checkSleep();}
if(count($this->globalPackets) > 0){
if(count($this->players) > 0){
$this->server->batchPackets($this->players, $this->globalPackets);
}
$this->globalPackets = [];
}
foreach ($this->chunkPackets as $index => $entries) {
Level::getXZ($index, $chunkX, $chunkZ);
$chunkPlayers = $this->getChunkPlayers($chunkX, $chunkZ);
if (count($chunkPlayers) > 0) {
$this->server->batchPackets($chunkPlayers, $entries, false, false);
}
}
$this->chunkPackets = [];
}
public function checkSleep() : void{
if (count($this->players) === 0) {
return;
}
$resetTime = true;
foreach ($this->getPlayers() as $p) {
if (!$p->isSleeping()) {
$resetTime = false;
break;
}
}
if ($resetTime) {
$time = $this->getTimeOfDay();
if ($time >= Level::TIME_NIGHT and $time < Level::TIME_SUNRISE) {
$this->setTime($this->getTime() + Level::TIME_FULL - $time);
foreach ($this->getPlayers() as $p) {
$p->stopSleep();
}
}
}
}
public function sendBlockExtraData(int $x, int $y, int $z, int $id, int $data, array $targets = null) : void{
$pk = new LevelEventPacket;
$pk->evid = LevelEventPacket::EVENT_SET_DATA;
$pk->x = $x + 0.5;
$pk->y = $y + 0.5;
$pk->z = $z + 0.5;
$pk->data = ($data << 8) | $id;
$this->server->broadcastPacket($targets === null ? $this->getChunkPlayers($x >> 4, $z >> 4) : $targets, $pk);
}
public function sendBlocks(array $target, array $blocks, $flags = UpdateBlockPacket::FLAG_NONE, bool $optimizeRebuilds = false) : void{
$packets = [];
if ($optimizeRebuilds) {
$chunks = [];
foreach ($blocks as $b) {
if(!($b instanceof Vector3)){
throw new \TypeError("Expected Vector3 in blocks array, got " . (is_object($b) ? get_class($b) : gettype($b)));
}
$pk = new UpdateBlockPacket();
$first = false;
if (!isset($chunks[$index = Level::chunkHash($b->x >> 4, $b->z >> 4)])) {
$chunks[$index] = true;
$first = true;
}
$pk->x = $b->x;
$pk->z = $b->z;
$pk->y = $b->y;
if($b instanceof Block){
$pk->blockId = $b->getId();
$pk->blockData = $b->getDamage();
}else{
$fullBlock = $this->getFullBlock($b->x, $b->y, $b->z);
$pk->blockId = $fullBlock >> 4;
$pk->blockData = $fullBlock & 0xf;
}
$pk->flags = $first ? $flags : UpdateBlockPacket::FLAG_NONE;
$packets[] = $pk;
}
}else{
foreach ($blocks as $b) {
if(!($b instanceof Vector3)){
throw new \TypeError("Expected Vector3 in blocks array, got " . (is_object($b) ? get_class($b) : gettype($b)));
}
$pk = new UpdateBlockPacket();
$pk->x = $b->x;
$pk->z = $b->z;
$pk->y = $b->y;
if($b instanceof Block){
$pk->blockId = $b->getId();
$pk->blockData = $b->getDamage();
}else{
$fullBlock = $this->getFullBlock($b->x, $b->y, $b->z);
$pk->blockId = $fullBlock >> 4;
$pk->blockData = $fullBlock & 0xf;
}
$pk->flags = $flags;
$packets[] = $pk;
}
}
$this->server->batchPackets($target, $packets, false, false);
}
public function clearCache(bool $force = false) : void{
if($force){
$this->chunkCache = [];
$this->blockCache = [];
}else{
$count = 0;
foreach($this->blockCache as $list){
$count += count($list);
if($count > 2048){
$this->blockCache = [];
break;
}
}
}
}
public function clearChunkCache(int $chunkX, int $chunkZ) : void{
unset($this->chunkCache[Level::chunkHash($chunkX, $chunkZ)]);
}
public function getRandomTickedBlocks() : \SplFixedArray{
return $this->randomTickBlocks;
}
private function tickChunks() : void{
if ($this->chunksPerTick <= 0 or count($this->loaders) === 0) {
$this->chunkTickList = [];
return;
}
$chunksPerLoader = min(200, max(1, (int)((($this->chunksPerTick - count($this->loaders)) / count($this->loaders)) + 0.5)));
$randRange = 3 + $chunksPerLoader / 30;
$randRange = (int)($randRange > $this->chunkTickRadius ? $this->chunkTickRadius : $randRange);
foreach ($this->loaders as $loader) {
$chunkX = (int) floor($loader->getX()) >> 4;
$chunkZ = (int) floor($loader->getZ()) >> 4;
$index = Level::chunkHash($chunkX, $chunkZ);
$existingLoaders = max(0, isset($this->chunkTickList[$index]) ? $this->chunkTickList[$index] : 0);
$this->chunkTickList[$index] = $existingLoaders + 1;
for ($chunk = 0; $chunk < $chunksPerLoader; ++$chunk) {
$dx = mt_rand(-$randRange, $randRange);
$dz = mt_rand(-$randRange, $randRange);
$hash = Level::chunkHash($dx + $chunkX, $dz + $chunkZ);
if (!isset($this->chunkTickList[$hash]) and isset($this->chunks[$hash])) {
$this->chunkTickList[$hash] = -1;
}
}
}
foreach ($this->chunkTickList as $index => $loaders) {
Level::getXZ($index, $chunkX, $chunkZ);
for($cx = -1; $cx <= 1; ++$cx){
for($cz = -1; $cz <= 1; ++$cz){
if(!isset($this->chunks[Level::chunkHash($chunkX + $cx, $chunkZ + $cz)])){
unset($this->chunkTickList[$index]);
goto skip_to_next; //no "continue 3" thanks!
}
}
}
if($loaders <= 0){
unset($this->chunkTickList[$index]);
}
$chunk = $this->chunks[$index];
foreach ($chunk->getEntities() as $entity) {
$entity->scheduleUpdate();
}
foreach ($chunk->getSubChunks() as $Y => $subChunk) {
if(!($subChunk instanceof EmptySubChunk)){
$k = mt_rand(0, 0xfffffffff); //36 bits
for($i = 0; $i < 3; ++$i){
$x = $k & 0x0f;
$y = ($k >> 4) & 0x0f;
$z = ($k >> 8) & 0x0f;
$k >>= 12;
$blockId = $subChunk->getBlockId($x, $y, $z);
if (isset($this->randomTickBlocks[$blockId])) {
$class = $this->randomTickBlocks[$blockId];
$block = new $class($subChunk->getBlockData($x, $y, $z));
$block->x = $chunkX * 16 + $x;
$block->y = ($Y << 4) + $y;
$block->z = $chunkZ * 16 + $z;
$block->level = $this;
$block->onUpdate(self::BLOCK_UPDATE_RANDOM);
}
}
}
}
skip_to_next: //dummy label to break out of nested loops
}
if ($this->clearChunksOnTick) {
$this->chunkTickList = [];
}
}
public function __debugInfo() : array{
return [];
}
public function save(bool $force = false) : bool{
if (!$this->getAutoSave() and !$force) {
return false;
}
$this->server->getPluginManager()->callEvent(new LevelSaveEvent($this));
$this->provider->setTime($this->time);
$this->saveChunks();
if ($this->provider instanceof BaseLevelProvider) {
$this->provider->saveLevelData();
}
return true;
}
public function saveChunks() : void{
$this->timings->syncChunkSaveTimer->startTiming();
try{
foreach($this->chunks as $chunk){
if(($chunk->hasChanged() or count($chunk->getTiles()) > 0 or count($chunk->getSavableEntities()) > 0) and $chunk->isGenerated()){
$this->provider->saveChunk($chunk);
$chunk->setChanged(false);
}
}
}finally{
$this->timings->syncChunkSaveTimer->stopTiming();
}
}
public function updateAround(Vector3 $pos) : void{
$x = (int) floor($pos->x);
$y = (int) floor($pos->y);
$z = (int) floor($pos->z);
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x, $y - 1, $z)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x, $y + 1, $z)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x - 1, $y, $z)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x + 1, $y, $z)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x, $y, $z - 1)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($x, $y, $z + 1)));
if (!$ev->isCancelled()) {
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
}
}
public function scheduleUpdate(Vector3 $pos, int $delay) : void{
$this->scheduleDelayedBlockUpdate($pos, $delay);
}
public function scheduleDelayedBlockUpdate(Vector3 $pos, int $delay) : void{
if(
!$this->isInWorld($pos->x, $pos->y, $pos->z) or
(isset($this->scheduledBlockUpdateQueueIndex[$index = Level::blockHash($pos->x, $pos->y, $pos->z)]) and $this->scheduledBlockUpdateQueueIndex[$index] <= $delay)
){
return;
}
$this->scheduledBlockUpdateQueueIndex[$index] = $delay;
$this->scheduledBlockUpdateQueue->insert(new Vector3((int) $pos->x, (int) $pos->y, (int) $pos->z), (int) $delay + $this->server->getTick());
}
public function scheduleNeighbourBlockUpdates(Vector3 $pos) : void{
$pos = $pos->floor();
for($i = 0; $i <= 5; ++$i){
$side = $pos->getSide($i);
if($this->isInWorld($side->x, $side->y, $side->z)){
$this->neighbourBlockUpdateQueue->enqueue(Level::blockHash($side->x, $side->y, $side->z));
}
}
}
public function getCollisionBlocks(AxisAlignedBB $bb, bool $targetFirst = false): array {
$minX = (int) floor($bb->minX - 1);
$minY = (int) floor($bb->minY - 1);
$minZ = (int) floor($bb->minZ - 1);
$maxX = (int) floor($bb->maxX + 1);
$maxY = (int) floor($bb->maxY + 1);
$maxZ = (int) floor($bb->maxZ + 1);
$collides = [];
if($targetFirst){
for($z = $minZ; $z <= $maxZ; ++$z){
for($x = $minX; $x <= $maxX; ++$x){
for($y = $minY; $y <= $maxY; ++$y){
$block = $this->getBlockAt($x, $y, $z);
if(!$block->canPassThrough() and $block->collidesWithBB($bb)){
return [$block];
}
}
}
}
}else{
for($z = $minZ; $z <= $maxZ; ++$z){
for($x = $minX; $x <= $maxX; ++$x){
for($y = $minY; $y <= $maxY; ++$y){
$block = $this->getBlockAt($x, $y, $z);
if(!$block->canPassThrough() and $block->collidesWithBB($bb)){
$collides[] = $block;
}
}
}
}
}
return $collides;
}
public function isFullBlock(Vector3 $pos) : bool{
if ($pos instanceof Block) {
if ($pos->isSolid()) {
return true;
}
$bb = $pos->getBoundingBox();
}else{
$bb = $this->getBlock($pos)->getBoundingBox();
}
return $bb !== null and $bb->getAverageEdgeLength() >= 1;
}
public function getCollisionCubes(Entity $entity, AxisAlignedBB $bb, bool $entities = true): array {
$minX = (int) floor($bb->minX - 1);
$minY = (int) floor($bb->minY - 1);
$minZ = (int) floor($bb->minZ - 1);
$maxX = (int) floor($bb->maxX + 1);
$maxY = (int) floor($bb->maxY + 1);
$maxZ = (int) floor($bb->maxZ + 1);
$collides = [];
for($z = $minZ; $z <= $maxZ; ++$z){
for($x = $minX; $x <= $maxX; ++$x){
for($y = $minY; $y <= $maxY; ++$y){
$block = $this->getBlockAt($x, $y, $z);
if(!$block->canPassThrough()){
foreach($block->getCollisionBoxes() as $blockBB){
if($blockBB->intersectsWith($bb)){
$collides[] = $blockBB;
}
}
}
}
}
}
if($entities){
foreach($this->getCollidingEntities($bb->expandedCopy(0.25, 0.25, 0.25), $entity) as $ent){
$collides[] = clone $ent->boundingBox;
}
}
return $collides;
}
public function getFullLight(Vector3 $pos): int {
return $this->getFullLightAt($pos->x, $pos->y, $pos->z);
}
public function getFullLightAt(int $x, int $y, int $z) : int{
$skyLight = $this->getRealBlockSkyLightAt($x, $y, $z);
if($skyLight < 15){
return max($skyLight, $this->getBlockLightAt($x, $y, $z));
}else{
return $skyLight;
}
}
public function computeSunAnglePercentage() : float{
$timeProgress = ($this->time % 24000) / 24000;
$sunProgress = $timeProgress + ($timeProgress < 0.25 ? 0.75 : -0.25);
$diff = (((1 - ((cos($sunProgress * M_PI) + 1) / 2)) - $sunProgress) / 3);
return $sunProgress + $diff;
}
public function getSunAnglePercentage() : float{
return $this->sunAnglePercentage;
}
public function getSunAngleRadians() : float{
return $this->sunAnglePercentage * 2 * M_PI;
}
public function getSunAngleDegrees() : float{
return $this->sunAnglePercentage * 360.0;
}
public function computeSkyLightReduction() : int{
$percentage = max(0, min(1, -(cos($this->getSunAngleRadians()) * 2 - 0.5)));
return (int) ($percentage * 11);
}
public function getSkyLightReduction() : int{
return $this->skyLightReduction;
}
public function getRealBlockSkyLightAt(int $x, int $y, int $z) : int{
$light = $this->getBlockSkyLightAt($x, $y, $z) - $this->skyLightReduction;
return $light < 0 ? 0 : $light;
}
public function getFullBlock(int $x, int $y, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, false)->getFullBlock($x & 0x0f, $y, $z & 0x0f);
}
public function isInWorld(int $x, int $y, int $z) : bool{
return (
$x <= INT32_MAX and $x >= INT32_MIN and
$y < $this->getWorldHeight() and $y >= 0 and
$z <= INT32_MAX and $z >= INT32_MIN
);
}
public function getBlock(Vector3 $pos, bool $cached = true, bool $addToCache = true) : Block{
return $this->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z), $cached, $addToCache);
}
public function getBlockAt(int $x, int $y, int $z, bool $cached = true, bool $addToCache = true) : Block{
$fullState = 0;
$blockHash = null;
$chunkHash = Level::chunkHash($x >> 4, $z >> 4);
if($this->isInWorld($x, $y, $z)){
$blockHash = Level::blockHash($x, $y, $z);
if($cached and isset($this->blockCache[$chunkHash][$blockHash])){
return $this->blockCache[$chunkHash][$blockHash];
}
$chunk = $this->chunks[$chunkHash] ?? null;
if($chunk !== null){
$fullState = $chunk->getFullBlock($x & 0x0f, $y, $z & 0x0f);
}else{
$addToCache = false;
}
}
$block = clone $this->blockStates[$fullState & 0xfff];
$block->x = $x;
$block->y = $y;
$block->z = $z;
$block->level = $this;
if($addToCache and $blockHash !== null){
$this->blockCache[$chunkHash][$blockHash] = $block;
}
return $block;
}
public function updateAllLight(Vector3 $pos) : void{
$this->updateBlockSkyLight($pos->x, $pos->y, $pos->z);
$this->updateBlockLight($pos->x, $pos->y, $pos->z);
}
public function getHighestAdjacentBlockSkyLight(int $x, int $y, int $z) : int{
return max([
$this->getBlockSkyLightAt($x + 1, $y, $z),
$this->getBlockSkyLightAt($x - 1, $y, $z),
$this->getBlockSkyLightAt($x, $y + 1, $z),
$this->getBlockSkyLightAt($x, $y - 1, $z),
$this->getBlockSkyLightAt($x, $y, $z + 1),
$this->getBlockSkyLightAt($x, $y, $z - 1)
]);
}
public function updateBlockSkyLight(int $x, int $y, int $z) : void{
$this->timings->doBlockSkyLightUpdates->startTiming();
$oldHeightMap = $this->getHeightMap($x, $z);
$sourceId = $this->getBlockIdAt($x, $y, $z);
$yPlusOne = $y + 1;
if($yPlusOne === $oldHeightMap){ //Block changed directly beneath the heightmap. Check if a block was removed or changed to a different light-filter.
$newHeightMap = $this->getChunk($x >> 4, $z >> 4)->recalculateHeightMapColumn($x & 0x0f, $z & 0x0f);
}elseif($yPlusOne > $oldHeightMap){ //Block **placed** above the heightmap.
$this->setHeightMap($x, $z, $yPlusOne);
$newHeightMap = $yPlusOne;
}else{ //block changed below heightmap
$newHeightMap = $oldHeightMap;
}
$update = new SkyLightUpdate($this);
if($newHeightMap > $oldHeightMap){ //Heightmap increase, block placed, remove sky light
for($i = $y; $i >= $oldHeightMap; --$i){
$update->setAndUpdateLight($x, $i, $z, 0); //Remove all light beneath, adjacent recalculation will handle the rest.
}
}elseif($newHeightMap < $oldHeightMap){ //Heightmap decrease, block changed or removed, add sky light
for($i = $y; $i >= $newHeightMap; --$i){
$update->setAndUpdateLight($x, $i, $z, 15);
}
}else{ //No heightmap change, block changed "underground"
$update->setAndUpdateLight($x, $y, $z, max(0, $this->getHighestAdjacentBlockSkyLight($x, $y, $z) - Block::$lightFilter[$sourceId]));
}
$update->execute();
$this->timings->doBlockSkyLightUpdates->stopTiming();
}
public function getHighestAdjacentBlockLight(int $x, int $y, int $z): int {
return max([
$this->getBlockLightAt($x + 1, $y, $z),
$this->getBlockLightAt($x - 1, $y, $z),
$this->getBlockLightAt($x, $y + 1, $z),
$this->getBlockLightAt($x, $y - 1, $z),
$this->getBlockLightAt($x, $y, $z + 1),
$this->getBlockLightAt($x, $y, $z - 1)
]);
}
public function updateBlockLight(int $x, int $y, int $z) : void{
$this->timings->doBlockLightUpdates->startTiming();
$id = $this->getBlockIdAt($x, $y, $z);
$newLevel = max(Block::$light[$id], $this->getHighestAdjacentBlockLight($x, $y, $z) - Block::$lightFilter[$id]);
if($this->blockLightUpdate === null){
$this->blockLightUpdate = new BlockLightUpdate($this);
}
$this->blockLightUpdate->setAndUpdateLight($x, $y, $z, $newLevel);
$this->timings->doBlockLightUpdates->stopTiming();
}
public function executeQueuedLightUpdates() : void{
if($this->blockLightUpdate !== null){
$this->timings->doBlockLightUpdates->startTiming();
$this->blockLightUpdate->execute();
$this->blockLightUpdate = null;
$this->timings->doBlockLightUpdates->stopTiming();
}
if($this->skyLightUpdate !== null){
$this->timings->doBlockSkyLightUpdates->startTiming();
$this->skyLightUpdate->execute();
$this->skyLightUpdate = null;
$this->timings->doBlockSkyLightUpdates->stopTiming();
}
}
public function setBlock(Vector3 $pos, Block $block, bool $direct = false, bool $update = true) : bool{
$pos = $pos->floor();
if(!$this->isInWorld($pos->x, $pos->y, $pos->z)){
return false;
}
$this->timings->setBlock->startTiming();
if ($this->getChunkAtPosition($pos, true)->setBlock($pos->x & 0x0f, $pos->y, $pos->z & 0x0f, $block->getId(), $block->getDamage())) {
if (!($pos instanceof Position)) {
$pos = $this->temporalPosition->setComponents($pos->x, $pos->y, $pos->z);
}
$block = clone $block;
$block->position($pos);
$block->clearCaches();
$chunkHash = Level::chunkHash($pos->x >> 4, $pos->z >> 4);
$blockHash = Level::blockHash($pos->x, $pos->y, $pos->z);
unset($this->blockCache[$chunkHash][$blockHash]);
if ($direct) {
$this->sendBlocks($this->getChunkPlayers($pos->x >> 4, $pos->z >> 4), [$block], UpdateBlockPacket::FLAG_ALL_PRIORITY);
unset($this->chunkCache[$chunkHash], $this->changedBlocks[$chunkHash][$blockHash]);
}else{
if (!isset($this->changedBlocks[$chunkHash])) {
$this->changedBlocks[$chunkHash] = [];
}
$this->changedBlocks[$chunkHash][$blockHash] = $block;
}
foreach ($this->getChunkLoaders($pos->x >> 4, $pos->z >> 4) as $loader) {
$loader->onBlockChanged($block);
}
if ($update) {
$this->updateAllLight($block);
$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($block));
if (!$ev->isCancelled()) {
foreach ($this->getNearbyEntities(new AxisAlignedBB($block->x - 1, $block->y - 1, $block->z - 1, $block->x + 1, $block->y + 1, $block->z + 1)) as $entity) {
$entity->onNearbyBlockChange();
}
$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
$this->scheduleNeighbourBlockUpdates($pos);
}
}
$this->timings->setBlock->stopTiming();
return true;
}
$this->timings->setBlock->stopTiming();
return false;
}
public function dropItem(Vector3 $source, Item $item, Vector3 $motion = null, int $delay = 10) : mixed{
$motion = $motion === null ? new Vector3(lcg_value() * 0.2 - 0.1, 0.2, lcg_value() * 0.2 - 0.1) : $motion;
if ($item->getId() > 0 and $item->getCount() > 0) {
$itemEntity = Entity::createEntity("Item", $this, new CompoundTag("", [
"Pos" => new ListTag("Pos", [
new DoubleTag("", $source->getX()),
new DoubleTag("", $source->getY()),
new DoubleTag("", $source->getZ())
]),
"Motion" => new ListTag("Motion", [
new DoubleTag("", $motion->x),
new DoubleTag("", $motion->y),
new DoubleTag("", $motion->z)
]),
"Rotation" => new ListTag("Rotation", [
new FloatTag("", lcg_value() * 360),
new FloatTag("", 0)
]),
"Health" => new ShortTag("Health", 5),
"Item" => $item->nbtSerialize(-1, "Item"),
"PickupDelay" => new ShortTag("PickupDelay", $delay)
]));
$itemEntity->spawnToAll();
return $itemEntity;
}
return null;
}
public function checkSpawnProtection(Player $player, Vector3 $vector) : bool{
if(!$player->hasPermission("pocketmine.spawnprotect.bypass") and ($distance = $this->server->getSpawnRadius()) > -1){
$t = new Vector2($vector->x, $vector->z);
$spawnLocation = $this->getSpawnLocation();
$s = new Vector2($spawnLocation->x, $spawnLocation->z);
if(count($this->server->getOps()->getAll()) > 0 and $t->distance($s) <= $distance){
return true;
}
}
return false;
}
public function useBreakOn(Vector3 $vector, Item &$item = null, Player $player = null, bool $createParticles = false) : bool {
$target = $this->getBlock($vector);
if ($item === null) {
$item = Item::get(Item::AIR, 0, 0);
}
if ($player !== null) {
$ev = new BlockBreakEvent($player, $target, $item, ($player->isCreative()));
if($target instanceof Air or ($player->isSurvival() and !$target->isBreakable($item)) or $player->isSpectator()){
$ev->setCancelled();
} elseif (!$player->isOp() and ($distance = $this->server->getSpawnRadius()) > -1) {
$t = new Vector2($target->x, $target->z);
$s = new Vector2($this->getSpawnLocation()->x, $this->getSpawnLocation()->z);
if (count($this->server->getOps()->getAll()) > 0 and $t->distance($s) <= $distance) { //set it to cancelled so plugins can bypass this
$ev->setCancelled();
}
}
$this->server->getPluginManager()->callEvent($ev);
if ($ev->isCancelled()) {
return false;
}
$drops = $ev->getDrops();
$expEnabled = false;
if ($player->isSurvival() and $expEnabled) {
$exp = 0;
if ($item->getEnchantmentLevel(Enchantment::TYPE_MINING_SILK_TOUCH) === 0) {
switch ($target->getId()) {
case Block::COAL_ORE:
$exp = mt_rand(0, 2);
break;
case Block::DIAMOND_ORE:
case Block::EMERALD_ORE:
$exp = mt_rand(3, 7);
break;
case Block::NETHER_QUARTZ_ORE:
case Block::LAPIS_ORE:
$exp = mt_rand(2, 5);
break;
case Block::REDSTONE_ORE:
case Block::GLOWING_REDSTONE_ORE:
$exp = mt_rand(1, 5);
break;
}
}
switch ($target->getId()) {
case Block::MONSTER_SPAWNER:
$exp = mt_rand(15, 43);
break;
}
if ($exp > 0) {
$this->spawnXPOrb($vector->add(0, 1, 0), $exp);
}
}
} elseif (!$target->isBreakable($item)) {
return false;
}else{
$drops = $target->getDrops($item); //Fixes tile entities being deleted before getting drops
foreach ($drops as $k => $i) {
if ((isset ($i[0])) && (isset ($i[1])) && (isset ($i[2]))) $drops[$k] = Item::get($i[0], $i[1], $i[2]);
}
}
$tag = $item->getNamedTagEntry("CanDestroy");
if ($tag instanceof ListTag) {
$canBreak = false;
foreach ($tag as $v) {
if ($v instanceof StringTag) {
$entry = Item::fromString($v->getValue());
if ($entry->getId() > 0 and $entry->getBlock() !== null and $entry->getBlock()->getId() === $target->getId()) {
$canBreak = true;
break;
}
}
}
if (!$canBreak) {
return false;
}
}
if ($createParticles) {
$this->addParticle(new DestroyBlockParticle($target, $target));
}
$target->onBreak($item);
$tile = $this->getTile($target);
if ($tile !== null) {
if ($tile instanceof Container) {
if ($tile instanceof Chest) {
$tile->unpair();
}
if($player !== null && $player->getTransactionQueue() !== null){
$player->getTransactionQueue()->execute();
}
$tile->getInventory()->dropContents($this, $target);
}
$tile->close();
}
$item->useOn($target);
if ($item->isTool() and $item->getDamage() >= $item->getMaxDurability()) {
$item = Item::get(Item::AIR, 0, 0);
}
if ($player === null or $player->isSurvival()) {
foreach ($drops as $drop) {
if ($drop->getCount() > 0) {
$this->dropItem($vector->add(0.5, 0.5, 0.5), $drop);
}
}
}
return true;
}
public function isInLoadedTerrain(Vector3 $pos) : bool{
return $this->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4);
}
public function useItemOn(Vector3 $vector, Item &$item, int $face, float $fx = 0.0, float $fy = 0.0, float $fz = 0.0, Player $player = null): bool {
$target = $this->getBlock($vector);
$block = $target->getSide($face);
if(!$this->isInWorld($block->x, $block->y, $block->z)){
return false;
}
if ($target->getId() === Item::AIR) {
return false;
}
if ($player !== null) {
$ev = new PlayerInteractEvent($player, $item, $target, $face, $target->getId() === 0 ? PlayerInteractEvent::RIGHT_CLICK_AIR : PlayerInteractEvent::RIGHT_CLICK_BLOCK);
if (!$player->isOp() and ($distance = $this->server->getSpawnRadius()) > -1) {
$t = new Vector2($target->x, $target->z);
$s = new Vector2($this->getSpawnLocation()->x, $this->getSpawnLocation()->z);
if (count($this->server->getOps()->getAll()) > 0 and $t->distance($s) <= $distance) { //set it to cancelled so plugins can bypass this
$ev->setCancelled();
}
}
if ($player->isSpectator()) {
$ev->setCancelled();
}
$this->server->getPluginManager()->callEvent($ev);
if (!$ev->isCancelled()) {
$target->onUpdate(self::BLOCK_UPDATE_TOUCH);
if (!$player->isSneaking()) {
if ($target->canBeActivated() === true and $target->onActivate($item, $player) === true) {
if ($item->getCount() <= 0) {
$item = Item::get(Item::AIR, 0, 0);
} elseif ($item->isTool() and $item->getDamage() >= $item->getMaxDurability()) {
$item = Item::get(Item::AIR, 0, 0);
}
return true;
}
if ($item->canBeActivated() and $item->onActivate($this, $player, $block, $target, $face, $fx, $fy, $fz)) {
if ($item->getCount() <= 0) {
$item = Item::get(Item::AIR, 0, 0);
return true;
} elseif ($item->isTool() and $item->getDamage() >= $item->getMaxDurability()) {
$item = Item::get(Item::AIR, 0, 0);
return true;
}
}
}
}else{
return false;
}
} elseif ($target->canBeActivated() === true and $target->onActivate($item, $player) === true) {
return true;
}
if ($item->canBePlaced()) {
$hand = $item->getBlock();
$hand->position($block);
}else{
return false;
}
$facePos = new Vector3($fx, $fy, $fz);
if($hand->canBePlacedAt($target, $facePos, $face, true)){
$block = $target;
$hand->position($block);
}elseif(!$hand->canBePlacedAt($block, $facePos, $face, false)){
return false;
}
if($hand->isSolid()){
foreach($hand->getCollisionBoxes() as $collisionBox){
if(count($this->getCollidingEntities($collisionBox)) > 0){
return false;  //Entity in block
}
}
}
$tag = $item->getNamedTagEntry("CanPlaceOn");
if ($tag instanceof ListTag) {
$canPlace = false;
foreach ($tag as $v) {
if ($v instanceof StringTag) {
$entry = Item::fromString($v->getValue());
if ($entry->getId() > 0 and $entry->getBlock() !== null and $entry->getBlock()->getId() === $target->getId()) {
$canPlace = true;
break;
}
}
}
if (!$canPlace) {
return false;
}
}
if ($player !== null) {
$ev = new BlockPlaceEvent($player, $hand, $block, $target, $item);
if (!$player->isOp() and ($distance = $this->server->getSpawnRadius()) > -1) {
$t = new Vector2($target->x, $target->z);
$s = new Vector2($this->getSpawnLocation()->x, $this->getSpawnLocation()->z);
if (count($this->server->getOps()->getAll()) > 0 and $t->distance($s) <= $distance) { //set it to cancelled so plugins can bypass this
$ev->setCancelled();
}
}
$this->server->getPluginManager()->callEvent($ev);
if ($ev->isCancelled()) {
return false;
}
$this->addSound(new BlockPlaceSound($hand));
}
if ($hand->place($item, $block, $target, $face, $fx, $fy, $fz, $player) === false) {
return false;
}
$item->pop();
return true;
}
public function getEntity(int $entityId) : ?Entity{
return isset($this->entities[$entityId]) ? $this->entities[$entityId] : null;
}
public function getEntities(): array {
return $this->entities;
}
public function getCollidingEntities(AxisAlignedBB $bb, Entity $entity = null) : array{
$nearby = [];
if ($entity === null or $entity->canCollide) {
$minX = ((int) floor($bb->minX - 2)) >> 4;
$maxX = ((int) floor($bb->maxX + 2)) >> 4;
$minZ = ((int) floor($bb->minZ - 2)) >> 4;
$maxZ = ((int) floor($bb->maxZ + 2)) >> 4;
for($x = $minX; $x <= $maxX; ++$x){
for($z = $minZ; $z <= $maxZ; ++$z){
foreach($this->getChunkEntities($x, $z) as $ent){
if($ent->canBeCollidedWith() and ($entity === null or ($ent !== $entity and $entity->canCollideWith($ent))) and $ent->boundingBox->intersectsWith($bb)){
$nearby[] = $ent;
}
}
}
}
}
return $nearby;
}
public function getNearbyEntities(AxisAlignedBB $bb, Entity $entity = null) : array{
$nearby = [];
$minX = ((int) floor($bb->minX - 2)) >> 4;
$maxX = ((int) floor($bb->maxX + 2)) >> 4;
$minZ = ((int) floor($bb->minZ - 2)) >> 4;
$maxZ = ((int) floor($bb->maxZ + 2)) >> 4;
for($x = $minX; $x <= $maxX; ++$x){
for($z = $minZ; $z <= $maxZ; ++$z){
foreach($this->getChunkEntities($x, $z) as $ent){
if ($ent instanceof Player and $ent->isSpectator()) {
continue;
}
if ($ent !== $entity and $ent->boundingBox->intersectsWith($bb)) {
$nearby[] = $ent;
}
}
}
}
return $nearby;
}
public function getNearestEntity(Vector3 $pos, float $maxDistance, string $entityType = Entity::class, bool $includeDead = false) : ?Entity{
assert(is_a($entityType, Entity::class, true));
$minX = ((int) floor($pos->x - $maxDistance)) >> 4;
$maxX = ((int) floor($pos->x + $maxDistance)) >> 4;
$minZ = ((int) floor($pos->z - $maxDistance)) >> 4;
$maxZ = ((int) floor($pos->z + $maxDistance)) >> 4;
$currentTargetDistSq = $maxDistance ** 2;
$currentTarget = null;
for($x = $minX; $x <= $maxX; ++$x){
for($z = $minZ; $z <= $maxZ; ++$z){
foreach($this->getChunkEntities($x, $z) as $entity){
if(!($entity instanceof $entityType) or $entity->isClosed() or (!$includeDead and !$entity->isAlive())){
continue;
}
$distSq = $entity->distanceSquared($pos);
if($distSq < $currentTargetDistSq){
$currentTargetDistSq = $distSq;
$currentTarget = $entity;
}
}
}
}
return $currentTarget;
}
public function getNearbyExperienceOrb(AxisAlignedBB $bb): array {
$nearby = [];
foreach ($this->getNearbyEntities($bb) as $entity) {
if ($entity instanceof XPOrb) {
$nearby[] = $entity;
}
}
return $nearby;
}
public function getTiles() : array{
return $this->tiles;
}
public function getTileById(int $tileId) : ?Tile{
return isset($this->tiles[$tileId]) ? $this->tiles[$tileId] : null;
}
public function getPlayers() : array {
return $this->players;
}
public function getLoaders() : array {
return $this->loaders;
}
public function getTile(Vector3 $pos) : ?Tile{
return $this->getTileAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
}
public function getTileAt(int $x, int $y, int $z) : ?Tile{
$chunk = $this->getChunk($x >> 4, $z >> 4);
if ($chunk !== null) {
return $chunk->getTile($x & 0x0f, $y, $z & 0x0f);
}
return null;
}
public function getChunkEntities($X, $Z) : array{
return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getEntities() : [];
}
public function getChunkTiles($X, $Z) : array{
return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getTiles() : [];
}
public function getBlockIdAt(int $x, int $y, int $z) : int{
return $this->getChunk($x >> 4, $z >> 4, true)->getBlockId($x & 0x0f, $y, $z & 0x0f);
}
public function setBlockIdAt(int $x, int $y, int $z, int $id) : void{
if(!$this->isInWorld($x, $y, $z)){ 
return;
}
unset($this->blockCache[$chunkHash = Level::chunkHash($x >> 4, $z >> 4)][$blockHash = Level::blockHash($x, $y, $z)]);
$this->getChunk($x >> 4, $z >> 4, true)->setBlockId($x & 0x0f, $y, $z & 0x0f, $id & 0xff);
if(!isset($this->changedBlocks[$chunkHash])){
$this->changedBlocks[$chunkHash] = [];
}
$this->changedBlocks[$chunkHash][$blockHash] = $v = new Vector3($x, $y, $z);
foreach ($this->getChunkLoaders($x >> 4, $z >> 4) as $loader) {
$loader->onBlockChanged($v);
}
}
public function getBlockExtraDataAt(int $x, int $y, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getBlockExtraData($x & 0x0f, $y & Level::Y_MASK, $z & 0x0f);
}
public function setBlockExtraDataAt(int $x, int $y, int $z, int $id, int $data) : void{
$this->getChunk($x >> 4, $z >> 4, true)->setBlockExtraData($x & 0x0f, $y, $z & 0x0f, ($data << 8) | $id);
$this->sendBlockExtraData($x, $y, $z, $id, $data);
}
public function getBlockDataAt(int $x, int $y, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getBlockData($x & 0x0f, $y, $z & 0x0f);
}
public function setBlockDataAt(int $x, int $y, int $z, int $data) : void{
if(!$this->isInWorld($x, $y, $z)){ 
return;
}
unset($this->blockCache[$chunkHash = Level::chunkHash($x >> 4, $z >> 4)][$blockHash = Level::blockHash($x, $y, $z)]);
$this->getChunk($x >> 4, $z >> 4, true)->setBlockData($x & 0x0f, $y, $z & 0x0f, $data & 0x0f);
if(!isset($this->changedBlocks[$chunkHash])){
$this->changedBlocks[$chunkHash] = [];
}
$this->changedBlocks[$chunkHash][$blockHash] = $v = new Vector3($x, $y, $z);
foreach ($this->getChunkLoaders($x >> 4, $z >> 4) as $loader) {
$loader->onBlockChanged($v);
}
}
public function getBlockSkyLightAt(int $x, int $y, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getBlockSkyLight($x & 0x0f, $y, $z & 0x0f);
}
public function setBlockSkyLightAt(int $x, int $y, int $z, int $level) : void{
$this->getChunk($x >> 4, $z >> 4, true)->setBlockSkyLight($x & 0x0f, $y, $z & 0x0f, $level & 0x0f);
}
public function getBlockLightAt(int $x, int $y, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getBlockLight($x & 0x0f, $y, $z & 0x0f);
}
public function setBlockLightAt(int $x, int $y, int $z, int $level) : void{
$this->getChunk($x >> 4, $z >> 4, true)->setBlockLight($x & 0x0f, $y, $z & 0x0f, $level & 0x0f);
}
public function getBiomeId(int $x, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getBiomeId($x & 0x0f, $z & 0x0f);
}
public function getHeightMap(int $x, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getHeightMap($x & 0x0f, $z & 0x0f);
}
public function setBiomeId(int $x, int $z, int $biomeId) : void{
$this->getChunk($x >> 4, $z >> 4, true)->setBiomeId($x & 0x0f, $z & 0x0f, $biomeId);
}
public function setHeightMap(int $x, int $z, int $value) : void{
$this->getChunk($x >> 4, $z >> 4, true)->setHeightMap($x & 0x0f, $z & 0x0f, $value);
}
public function getChunks(): array {
return $this->chunks;
}
public function getRandomChunk() : array{
$rand = array_rand($this->getChunks());
$rand2 = $this->chunks[$rand];
return $rand2;
}
public function getChunk(int $x, int $z, bool $create = false)  : ?Chunk{
if(isset($this->chunks[$index = Level::chunkHash($x, $z)])){
return $this->chunks[$index];
}elseif($this->loadChunk($x, $z, $create)){
return $this->chunks[$index];
}
return null;
}
public function getChunkAtPosition(Vector3 $pos, bool $create = false) : ?Chunk{
return $this->getChunk($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4, $create);
}
public function getAdjacentChunks(int $x, int $z): array {
$result = [];
for ($xx = 0; $xx <= 2; ++$xx) {
for ($zz = 0; $zz <= 2; ++$zz) {
$i = $zz * 3 + $xx;
if ($i === 4) {
continue; //center chunk
}
$result[$i] = $this->getChunk($x + $xx - 1, $z + $zz - 1, false);
}
}
return $result;
}
public function generateChunkCallback(int $x, int $z, ?Chunk $chunk) : void{
Timings::$generationCallbackTimer->startTiming();
if (isset($this->chunkPopulationQueue[$index = Level::chunkHash($x, $z)])) {
for ($xx = -1; $xx <= 1; ++$xx) {
for ($zz = -1; $zz <= 1; ++$zz) {
unset($this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)]);
}
}
unset($this->chunkPopulationQueue[$index]);
if($chunk !== null){
$oldChunk = $this->getChunk($x, $z, false);
$this->setChunk($x, $z, $chunk, false);
if(($oldChunk === null or !$oldChunk->isPopulated()) and $chunk->isPopulated()){
$this->server->getPluginManager()->callEvent(new ChunkPopulateEvent($this, $chunk));
foreach($this->getChunkLoaders($x, $z) as $loader){
$loader->onChunkPopulated($chunk);
}
}
}
}elseif(isset($this->chunkPopulationLock[$index])){
unset($this->chunkPopulationLock[$index]);
if($chunk !== null){
$this->setChunk($x, $z, $chunk, false);
}
}elseif($chunk !== null){
$this->setChunk($x, $z, $chunk, false);
}
Timings::$generationCallbackTimer->stopTiming();
}
public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk = null, bool $deleteEntitiesAndTiles = true) : void{
if ($chunk === null) {
return;
}
$chunk->setX($chunkX);
$chunk->setZ($chunkZ);
$index = Level::chunkHash($chunkX, $chunkZ);
$oldChunk = $this->getChunk($chunkX, $chunkZ, false);
if ($oldChunk !== null and $oldChunk !== $chunk){
if($deleteEntitiesAndTiles){
foreach($oldChunk->getEntities() as $player){
if(!($player instanceof Player)){
continue;
}
$chunk->addEntity($player);
$oldChunk->removeEntity($player);
$player->chunk = $chunk;
}
$this->unloadChunk($chunkX, $chunkZ, false, false);
}else{
foreach($oldChunk->getEntities() as $entity){
$chunk->addEntity($entity);
$oldChunk->removeEntity($entity);
$entity->chunk = $chunk;
}
foreach($oldChunk->getTiles() as $tile){
$chunk->addTile($tile);
$oldChunk->removeTile($tile);
}
}
}
$this->chunks[$index] = $chunk;
unset($this->blockCache[$index]);
unset($this->chunkCache[$index]);
unset($this->changedBlocks[$index]);
if(isset($this->chunkSendTasks[$index])){ //invalidate pending caches
$this->chunkSendTasks[$index]->cancelRun();
unset($this->chunkSendTasks[$index]);
}
$chunk->setChanged();
if (!$this->isChunkInUse($chunkX, $chunkZ)) {
$this->unloadChunkRequest($chunkX, $chunkZ);
}else{
foreach ($this->getChunkLoaders($chunkX, $chunkZ) as $loader) {
$loader->onChunkChanged($chunk);
}
}
}
public function sendLighting(int $x, int $y, int $z, Player $p) : void{
$pk = new AddEntityPacket();
$pk->type = Lightning::NETWORK_ID;
$pk->eid = mt_rand(10000000, 100000000);
$pk->x = $x;
$pk->y = $y;
$pk->z = $z;
$pk->metadata = array(3, 3, 3, 3);
$p->dataPacket($pk);
}
public function spawnLightning(Vector3 $pos): Lightning {
$nbt = new CompoundTag("", [
"Pos" => new ListTag("Pos", [
new DoubleTag("", $pos->getX()),
new DoubleTag("", $pos->getY()),
new DoubleTag("", $pos->getZ())
]),
"Motion" => new ListTag("Motion", [
new DoubleTag("", 0),
new DoubleTag("", 0),
new DoubleTag("", 0)
]),
"Rotation" => new ListTag("Rotation", [
new FloatTag("", 0),
new FloatTag("", 0)
]),
]);
$lightning = new Lightning($this, $nbt);
$lightning->spawnToAll();
return $lightning;
}
public function spawnXPOrb(Vector3 $pos, int $exp = 1) : XPOrb{
if ($exp > 0) {
$nbt = new CompoundTag("", [
"Pos" => new ListTag("Pos", [
new DoubleTag("", $pos->getX()),
new DoubleTag("", $pos->getY() + 0.5),
new DoubleTag("", $pos->getZ())
]),
"Motion" => new ListTag("Motion", [
new DoubleTag("", 0),
new DoubleTag("", 0),
new DoubleTag("", 0)
]),
"Rotation" => new ListTag("Rotation", [
new FloatTag("", 0),
new FloatTag("", 0)
]),
"Experience" => new LongTag("Experience", $exp),
]);
$expOrb = new XPOrb($this, $nbt);
$expOrb->spawnToAll();
return $expOrb;
}
}
public function getHighestBlockAt(int $x, int $z): int {
return $this->getChunk($x >> 4, $z >> 4, true)->getHighestBlockAt($x & 0x0f, $z & 0x0f);
}
public function canBlockSeeSky(Vector3 $pos): bool {
return $this->getHighestBlockAt($pos->getFloorX(), $pos->getFloorZ()) < $pos->getY();
}
public function isChunkLoaded(int $x, int $z): bool {
return isset($this->chunks[Level::chunkHash($x, $z)]);
}
public function isChunkGenerated(int $x, int $z): bool {
$chunk = $this->getChunk($x, $z);
return $chunk !== null ? $chunk->isGenerated() : false;
}
public function isChunkPopulated(int $x, int $z): bool {
$chunk = $this->getChunk($x, $z);
return $chunk !== null ? $chunk->isPopulated() : false;
}
public function getSpawnLocation() : Position{
return Position::fromObject($this->provider->getSpawn(), $this);
}
public function setSpawnLocation(Vector3 $pos) : void{
$previousSpawn = $this->getSpawnLocation();
$this->provider->setSpawn($pos);
$this->server->getPluginManager()->callEvent(new SpawnChangeEvent($this, $previousSpawn));
}
public function requestChunk(int $x, int $z, Player $player) : void{
$index = Level::chunkHash($x, $z);
if (!isset($this->chunkSendQueue[$index])) {
$this->chunkSendQueue[$index] = [];
}
$this->chunkSendQueue[$index][$player->getLoaderId()] = $player;
}
private function sendChunkFromCache($x, $z) {
if (isset($this->chunkSendQueue[$index = Level::chunkHash($x, $z)])) {
foreach ($this->chunkSendQueue[$index] as $player) {
if ($player->isConnected() and isset($player->usedChunks[$index])) {
$player->sendChunk($x, $z, $this->chunkCache[$index]);
}
}
unset($this->chunkSendQueue[$index]);
}
}
private function processChunkRequest() : void{
if (count($this->chunkSendQueue) > 0) {
$this->timings->syncChunkSendTimer->startTiming();
foreach ($this->chunkSendQueue as $index => $players) {
Level::getXZ($index, $x, $z);
if(isset($this->chunkSendTasks[$index])){
if($this->chunkSendTasks[$index]->isCrashed()){
unset($this->chunkSendTasks[$index]);
$this->server->getLogger()->error("Failed to prepare chunk $x $z for sending, retrying");
}else{
continue;
}
}
if(isset($this->chunkCache[$index])){
$this->sendChunkFromCache($x, $z);
continue;
}
$this->timings->syncChunkSendPrepareTimer->startTiming();
$chunk = $this->chunks[$index] ?? null;
if(!($chunk instanceof Chunk)){
throw new ChunkException("Invalid Chunk sent");
}
assert($chunk->getX() === $x and $chunk->getZ() === $z, "Chunk coordinate mismatch: expected $x $z, but chunk has coordinates " . $chunk->getX() . " " . $chunk->getZ() . ", did you forget to clone a chunk before setting?");
$this->server->getScheduler()->scheduleAsyncTask($task = new ChunkRequestTask($this, $x, $z, $chunk));
$this->chunkSendTasks[$index] = $task;
$this->timings->syncChunkSendPrepareTimer->stopTiming();
}
$this->timings->syncChunkSendTimer->stopTiming();
}
}
public function chunkRequestCallback(int $x, int $z, BatchPacket $payload) : void{
$this->timings->syncChunkSendTimer->startTiming();
$index = Level::chunkHash($x, $z);
unset($this->chunkSendTasks[$index]);
$this->chunkCache[$index] = $payload;
$this->sendChunkFromCache($x, $z);
if(!$this->server->getMemoryManager()->canUseChunkCache()){
unset($this->chunkCache[$index]);
}
$this->timings->syncChunkSendTimer->stopTiming();
}
public function removeEntity(Entity $entity) : void{
if ($entity->getLevel() !== $this) {
throw new LevelException("Invalid Entity level");
}
if ($entity instanceof Player) {
unset($this->players[$entity->getId()]);
$this->checkSleep();
}
unset($this->entities[$entity->getId()]);
unset($this->updateEntities[$entity->getId()]);
}
public function addEntity(Entity $entity) : void{
if($entity->isClosed()){
throw new \InvalidArgumentException("Attempted to add a garbage closed Entity to world");
}
if ($entity->getLevel() !== $this) {
throw new LevelException("Invalid Entity level");
}
if ($entity instanceof Player) {
$this->players[$entity->getId()] = $entity;
}
$this->entities[$entity->getId()] = $entity;
}
public function addTile(Tile $tile) : void{
if($tile->isClosed()){
throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to world");
}
if ($tile->getLevel() !== $this) {
throw new LevelException("Invalid Tile level");
}
$chunkX = $tile->getFloorX() >> 4;
$chunkZ = $tile->getFloorZ() >> 4;
if(isset($this->chunks[$hash = Level::chunkHash($chunkX, $chunkZ)])){
$this->chunks[$hash]->addTile($tile);
}else{
throw new \InvalidStateException("Attempted to create tile " . get_class($tile) . " in unloaded chunk $chunkX $chunkZ");
}
$this->tiles[$tile->getId()] = $tile;
$this->clearChunkCache($chunkX, $chunkZ);
}
public function removeTile(Tile $tile) : void{
if ($tile->getLevel() !== $this) {
throw new LevelException("Invalid Tile world");
}
unset($this->tiles[$tile->getId()], $this->updateTiles[$tile->getId()]);
$chunkX = $tile->getFloorX() >> 4;
$chunkZ = $tile->getFloorZ() >> 4;
if(isset($this->chunks[$hash = Level::chunkHash($chunkX, $chunkZ)])){
$this->chunks[$hash]->removeTile($tile);
}
$this->clearChunkCache($chunkX, $chunkZ);
}
public function isChunkInUse(int $x, int $z) : bool{
return isset($this->chunkLoaders[$index = Level::chunkHash($x, $z)]) and count($this->chunkLoaders[$index]) > 0;
}
public function loadChunk(int $x, int $z, bool $create = true) : bool{
if (isset($this->chunks[$index = Level::chunkHash($x, $z)])) {
return true;
}
$this->timings->syncChunkLoadTimer->startTiming();
$this->cancelUnloadChunkRequest($x, $z);
$this->timings->syncChunkLoadDataTimer->startTiming();
$chunk = null;
try{
$chunk = $this->provider->loadChunk($x, $z);
}catch(CorruptedChunkException | UnsupportedChunkFormatException $e){
$logger = $this->server->getLogger();
$logger->critical("Failed to load chunk x=$x z=$z: " . $e->getMessage());
}
if($chunk === null and $create){
$chunk = new Chunk($x, $z);
}
$this->timings->syncChunkLoadDataTimer->stopTiming();
if ($chunk === null) {
$this->timings->syncChunkLoadTimer->stopTiming();
return false;
}
$this->chunks[$index] = $chunk;
unset($this->blockCache[$index]);
$chunk->initChunk($this);
$this->server->getPluginManager()->callEvent(new ChunkLoadEvent($this, $chunk, !$chunk->isGenerated()));
if ($this->isChunkInUse($x, $z)) {
foreach ($this->getChunkLoaders($x, $z) as $loader) {
$loader->onChunkLoaded($chunk);
}
}else{
$this->server->getLogger()->debug("Newly loaded chunk $x $z has no loaders registered, will be unloaded at next available opportunity");
$this->unloadChunkRequest($x, $z);
}
$this->timings->syncChunkLoadTimer->stopTiming();
return true;
}
private function queueUnloadChunk(int $x, int $z) : void{
$this->unloadQueue[$index = Level::chunkHash($x, $z)] = microtime(true);
unset($this->chunkTickList[$index]);
}
public function unloadChunkRequest(int $x, int $z, bool $safe = true) : bool{
if (($safe and $this->isChunkInUse($x, $z)) or $this->isSpawnChunk($x, $z)) {
return false;
}
$this->queueUnloadChunk($x, $z);
return true;
}
public function cancelUnloadChunkRequest(int $x, int $z) : void{
unset($this->unloadQueue[Level::chunkHash($x, $z)]);
}
public function unloadChunk(int $x, int $z, bool $safe = true, bool $trySave = true) : bool{
if($safe and $this->isChunkInUse($x, $z)){
return false;
}
if(!$this->isChunkLoaded($x, $z)){
return true;
}
$this->timings->doChunkUnload->startTiming();
$index = Level::chunkHash($x, $z);
$chunk = $this->chunks[$index] ?? null;
if ($chunk !== null) {
$this->server->getPluginManager()->callEvent($ev = new ChunkUnloadEvent($this, $chunk));
if ($ev->isCancelled()) {
$this->timings->doChunkUnload->stopTiming();
return false;
}
if($trySave and $this->getAutoSave() and $chunk->isGenerated()){
if($chunk->hasChanged() or count($chunk->getTiles()) > 0 or count($chunk->getSavableEntities()) > 0){
$this->timings->syncChunkSaveTimer->startTiming();
try{
$this->provider->saveChunk($chunk);
}finally{
$this->timings->syncChunkSaveTimer->stopTiming();
}
}
}
foreach($this->getChunkLoaders($x, $z) as $loader){
$loader->onChunkUnloaded($chunk);
}
$chunk->onUnload();
}
unset($this->chunks[$index]);
unset($this->chunkTickList[$index]);
unset($this->chunkCache[$index]);
unset($this->blockCache[$index]);
unset($this->changedBlocks[$index]);
unset($this->chunkSendQueue[$index]);
unset($this->chunkSendTasks[$index]);
$this->timings->doChunkUnload->stopTiming();
return true;
}
public function isSpawnChunk(int $X, int $Z) : bool {
$spawn = $this->provider->getSpawn();
$spawnX = $spawn->x >> 4;
$spawnZ = $spawn->z >> 4;
return abs($X - $spawnX) <= 1 and abs($Z - $spawnZ) <= 1;
}
public function getSafeSpawn(?Vector3 $spawn = null) : Position{
if (!($spawn instanceof Vector3) or $spawn->y < 1) {
$spawn = $this->getSpawnLocation();
}
$max = $this->worldHeight;
$v = $spawn->floor();
$chunk = $this->getChunkAtPosition($v, false);
$x = (int) $v->x;
$z = (int) $v->z;
if($chunk !== null and $chunk->isGenerated()){
$y = (int) min($max - 2, $v->y);
$wasAir = ($chunk->getBlockId($x & 0x0f, $y - 1, $z & 0x0f) === 0);
for(; $y > 0; --$y){
if($this->isFullBlock($this->getBlockAt($x, $y, $z))){
if($wasAir){
$y++;
break;
}
}else{
$wasAir = true;
}
}
for(; $y >= 0 and $y < $max; ++$y){
if(!$this->isFullBlock($this->getBlockAt($x, $y + 1, $z))){
if(!$this->isFullBlock($this->getBlockAt($x, $y, $z))){
return new Position($spawn->x, $y === (int) $spawn->y ? $spawn->y : $y, $spawn->z, $this);
}
}else{
++$y;
}
}
$v->y = $y;
}
return new Position($spawn->x, $v->y, $spawn->z, $this);
}
public function getTime() : int{
return $this->time;
}
public function getTimeOfDay() : int{
return $this->time % self::TIME_FULL;
}
public function getName() : string{
return $this->displayName;
}
public function getFolderName() : string{
return $this->folderName;
}
public function setTime(int $time) : void{
$this->time = $time;
$this->sendTime();
}
public function stopTime() : void{
$this->stopTime = true;
$this->sendTime();
}
public function startTime() : void{
$this->stopTime = false;
$this->sendTime();
}
public function getSeed() : int|string{
return $this->provider->getSeed();
}
public function setSeed(int $seed) : void{
$this->provider->setSeed($seed);
}
public function getWorldHeight() : int{
return $this->provider->getWorldHeight();
}
public function populateChunk(int $x, int $z, bool $force = false) : bool {
if(isset($this->chunkPopulationQueue[$index = Level::chunkHash($x, $z)]) or (count($this->chunkPopulationQueue) >= $this->chunkPopulationQueueSize and !$force)){
return false;
}
for($xx = -1; $xx <= 1; ++$xx){
for($zz = -1; $zz <= 1; ++$zz){
if(isset($this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)])){
return false;
}
}
}
$chunk = $this->getChunk($x, $z, true);
if (!$chunk->isPopulated()) {
Timings::$populationTimer->startTiming();
$this->chunkPopulationQueue[$index] = true;
for ($xx = -1; $xx <= 1; ++$xx) {
for ($zz = -1; $zz <= 1; ++$zz) {
$this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)] = true;
}
}
$task = new PopulationTask($this, $chunk);
$this->server->getScheduler()->scheduleAsyncTask($task);
Timings::$populationTimer->stopTiming();
return false;
}
return true;
}
public function doChunkGarbageCollection() : void{
$this->timings->doChunkGC->startTiming();
foreach($this->chunks as $index => $chunk){
if(!isset($this->unloadQueue[$index])){
Level::getXZ($index, $X, $Z);
if(!$this->isSpawnChunk($X, $Z)){
$this->unloadChunkRequest($X, $Z, true);
}
}
$chunk->collectGarbage();
}
$this->provider->doGarbageCollection();
$this->timings->doChunkGC->stopTiming();
}
public function unloadChunks(bool $force = false) : void{
if(count($this->unloadQueue) > 0){
$maxUnload = 96;
$now = microtime(true);
foreach($this->unloadQueue as $index => $time){
Level::getXZ($index, $X, $Z);
if(!$force){
if($maxUnload <= 0){
break;
}elseif($time > ($now - 30)){
continue;
}
}
if ($this->unloadChunk($X, $Z, true)) {
unset($this->unloadQueue[$index]);
--$maxUnload;
}
}
}
}
public function setMetadata(string $metadataKey, MetadataValue $newMetadataValue) : void{
$this->server->getLevelMetadata()->setMetadata($this, $metadataKey, $newMetadataValue);
} 
public function getMetadata(string $metadataKey) : array{
return $this->server->getLevelMetadata()->getMetadata($this, $metadataKey);
}
public function hasMetadata(string $metadataKey) : bool{
return $this->server->getLevelMetadata()->hasMetadata($this, $metadataKey);
}
public function removeMetadata(string $metadataKey, Plugin $owningPlugin) : void{
$this->server->getLevelMetadata()->removeMetadata($this, $metadataKey, $owningPlugin);
}
public function addEntityMotion(int $chunkX, int $chunkZ, int $entityId, float $x, float $y, float $z) : void{
$pk = new SetEntityMotionPacket();
$pk->eid = $entityId;
$pk->motionX = $x;
$pk->motionY = $y;
$pk->motionZ = $z;
$this->addChunkPacket($chunkX, $chunkZ, $pk);
}
public function addEntityMovement(int $chunkX, int $chunkZ, int $entityId, float $x, float $y, float $z, float $yaw, float $pitch, $headYaw = null) : void{
$pk = new MoveEntityPacket();
$pk->eid = $entityId;
$pk->x = $x;
$pk->y = $y;
$pk->z = $z;
$pk->yaw = $yaw;
$pk->pitch = $pitch;
$pk->headYaw = $headYaw ?? $yaw;
$this->addChunkPacket($chunkX, $chunkZ, $pk);
}
//support pmmp method
public function getLoadedChunks() : array{
return $this->chunks;
}
}
