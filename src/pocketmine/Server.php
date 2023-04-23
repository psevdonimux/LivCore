<?php declare(strict_types=1);

namespace pocketmine;

use pocketmine\block\Block;
use pocketmine\command\{CommandReader, CommandSender, ConsoleCommandSender, PluginIdentifiableCommand, SimpleCommandMap};
use pocketmine\entity\{Attribute, Effect, Entity};
use pocketmine\event\level\{LevelInitEvent, LevelLoadEvent};
use pocketmine\event\server\{QueryRegenerateEvent, ServerCommandEvent};
use pocketmine\event\{HandlerList, Timings, TimingsHandler, TranslationContainer};
use pocketmine\inventory\{CraftingManager, InventoryType, Recipe};
use pocketmine\item\enchantment\{Enchantment, EnchantmentLevelTable};
use pocketmine\item\Item;
use pocketmine\lang\BaseLang;
use pocketmine\level\format\io\LevelProviderManager;
use pocketmine\level\format\io\leveldb\LevelDB;
use pocketmine\level\format\io\region\{Anvil, McRegion, PMAnvil};
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\{Flat, Generator, VoidGenerator};
use pocketmine\level\generator\hell\Nether;
use pocketmine\level\generator\normal\{Normal, Normal2};
use pocketmine\level\{Level, LevelException};
use pocketmine\metadata\{EntityMetadataStore, LevelMetadataStore, PlayerMetadataStore};
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\{ByteTag, CompoundTag, DoubleTag, FloatTag, IntTag, ListTag, LongTag, ShortTag, StringTag};
use pocketmine\network\{AdvancedSourceInterface, CompressBatchedTask, Network};
use pocketmine\network\mcpe\protocol\{BatchPacket, DataPacket, ProtocolInfo, PlayerListPacket};
use pocketmine\network\query\QueryHandler;
use pocketmine\network\mcpe\RakLibInterface;
use pocketmine\network\rcon\RCON;
use pocketmine\network\upnp\UPnP;
use pocketmine\permission\{BanList, DefaultPermissions};
use pocketmine\plugin\{PharPluginLoader, FolderPluginLoader, Plugin, PluginLoadOrder, PluginManager, ScriptPluginLoader};
use pocketmine\resourcepacks\ResourcePackManager;
use pocketmine\scheduler\{CallbackTask, DServerTask, FileWriteTask, SendUsageTask, ServerScheduler};
use pocketmine\snooze\{SleeperHandler, SleeperNotifier};
use pocketmine\tile\Tile;
use pocketmine\utils\{Binary, Color, Config, MainLogger, ServerException, Terminal, TextFormat, Utils, UUID, VersionString};

class Server{

 public const BROADCAST_CHANNEL_ADMINISTRATIVE = 'pocketmine.broadcast.admin';
 public const BROADCAST_CHANNEL_USERS = 'pocketmine.broadcast.user';

 private bool $isRunning = true, $hasStopped = false, $dispatchSignals = false, $networkCompressionAsync = true, $forceLanguage = false, $autoSave;
 private static ?Server $instance = null;
 private static ?\Threaded $sleeper = null;
 private ?BanList $banByName = null, $banByIP = null, $banByCID = null;
 private ?Config $operators = null, $whitelist = null;
 private ?PluginManager $pluginManager = null;
 private float $profilingTickRate = 20, $nextTick = 0, $currentUse = 0, $currentTPS = 20;
 private ?ServerScheduler $scheduler = null;
 private int $tickCounter = 0, $sendUsageTicker = 0, $maxPlayers, $autoSaveTicker = 0, $autoSaveTicks = 6000;
 private array $tickAverage = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20], $useAverage = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $expCache, $uniquePlayers = [], $propertyCache = [], $players = [], $playerList = [], $levels = [];
 private \ThreadedLogger $logger;
 private MemoryManager $memoryManager;
 private ?CommandReader $console = null;
 private ?SimpleCommandMap $commandMap = null;
 private CraftingManager $craftingManager;
 private ResourcePackManager $resourceManager;
 private ConsoleCommandSender $consoleSender;
 private ?RCON $rcon = null;
 private EntityMetadataStore $entityMetadata;
 private PlayerMetadataStore $playerMetadata;
 private LevelMetadataStore $levelMetadata;
 private Network $network;
 private BaseLang $baseLang;
 private UUID $serverID;
 private \ClassLoader $autoloader;
 private string $dataPath, $pluginPath;
 private QueryHandler $queryHandler;
 private ?QueryRegenerateEvent $queryRegenerateTask = null;
 private Config $properties, $config;
 private ?Level $levelDefault = null;
 private SleeperHandler $tickSleeper;
 public int $networkCompressionLevel = 7, $weatherRandomDurationMin = 6000, $weatherRandomDurationMax = 12000, $lightningTime = 200, $dserverPlayers = 0, $dserverAllPlayers = 0, $pulseFrequency = 20, $chunkRadius = -1;
 public ?Config $advancedConfig = null;
 public bool $weatherEnabled = true, $netherEnabled = false, $lightningFire = false, $redstoneEnabled = false, $allowFrequencyPulse = true, $anvilEnabled = false, $keepExperience = false, $destroyBlockParticle = true, $allowSplashPotion = true, $fireSpread = false, $advancedCommandSelector = false, $enchantingTableEnabled = true, $countBookshelf = false, $allowInventoryCheats = false, $folderpluginloader = true, $loadIncompatibleAPI = true, $enderEnabled = true, $absorbWater = false, $allowSnowGolem, $allowIronGolem;
 public ?Level $netherLevel = null, $enderLevel = null; 
 public VersionString $version;
 public array $dserverConfig = [];

 public function getName() : string{
  return 'LivCore-public v1.0.0-beta';
 }
 public function isRunning() : bool{
  return $this->isRunning;
 }
 public function getUptime() : string{
  $time = function(int $modulo, int $division) : float{
   $time2 = floor((microtime(true) - \pocketmine\START_TIME) % $modulo / $division);
   return $time2 >= 1 ? $time2 : 0;
  };
   $lang = function(string $lang) : string{
    return $this->getLanguage()->translateString($lang);
   };
   $days = $time(1, 86400);
   $hours = $time(86400, 3600);
   $minutes = $time(3600, 60);
   $seconds = $time(60, 1);
   return 
   ($days != 0 ? $days. ' '. $lang('%pocketmine.command.status.days'). ' ' : '').
   ($hours != 0 ? $hours. ' '. $lang('%pocketmine.command.status.hours'). ' ' : '').
   ($minutes != 0 ? $minutes. ' '. $lang('%pocketmine.command.status.minutes'). ' ' : '').
   ($seconds != 0 ? $seconds. ' '. $lang('%pocketmine.command.status.seconds') : '');
 }
 public function getPocketMineVersion(){
  return \pocketmine\VERSION;
 }
 public function getFormattedVersion($prefix = ''){
  return (\pocketmine\VERSION !== '' ? $prefix . \pocketmine\VERSION : '');
 }
 public function getGitCommit(){
  return \pocketmine\GIT_COMMIT;
 }
 public function getShortGitCommit(){
  return substr(\pocketmine\GIT_COMMIT, 0, 7);
 }
 public function getCodename(){
  return \pocketmine\CODENAME;
 }
 public function getVersion(){
  return implode(',', ProtocolInfo::MINECRAFT_VERSION);
 }
 public function getApiVersion(){
  return \pocketmine\API_VERSION;
 }
 public function getGeniApiVersion(){
  return \pocketmine\GENISYS_API_VERSION;
 }
 public function getFilePath(){
  return \pocketmine\PATH;
 }
 public function getResourcePath() : string{
  return \pocketmine\RESOURCE_PATH;
 }
 public function getDataPath(){
  return $this->dataPath;
 }
 public function getPluginPath(){
  return $this->pluginPath;
 }
 public function getMaxPlayers() : int{
  return $this->maxPlayers;
 }
 public function getPort() : int{
  return $this->getConfigInt('server-port', 19132);
 }
 public function getViewDistance() : int{
  return max(2, $this->getConfigInt('view-distance', 8));
 }
 public function getAllowedViewDistance(int $distance) : int{
  return max(2, min($distance, $this->memoryManager->getViewDistance($this->getViewDistance())));
 }
 public function getIp() : string{
  $str = $this->getConfigString('server-ip');
  return $str !== '' ? $str : '0.0.0.0';
 }
 public function getServerUniqueId(){
  return $this->serverID;
 }
 public function getAutoSave(){
  return $this->autoSave;
 }
 public function setAutoSave($value) : void{
  $this->autoSave = (bool) $value;
  foreach($this->getLevels() as $level){
   $level->setAutoSave($this->autoSave);
  }
 }
 public function getLevelType() : string{
  return $this->getConfigString('level-type', 'DEFAULT');
 }
 public function getGenerateStructures() : bool{
  return $this->getConfigBoolean('generate-structures', true);
 }
 public function getGamemode() : int{
  return $this->getConfigInt('gamemode', 0) & 0b11;
 }
 public function getForceGamemode() : bool{
  return $this->getConfigBoolean('force-gamemode', false);
 }
 public static function getGamemodeString(int $mode) : string{
  switch($mode){
  case Player::SURVIVAL:
  return '%gameMode.survival';
  case Player::CREATIVE:
  return '%gameMode.creative';
  case Player::ADVENTURE:
  return '%gameMode.adventure';
  case Player::SPECTATOR:
  return '%gameMode.spectator';
  }
  return 'UNKNOWN';
 }
 public static function getGamemodeName(int $mode) : string{
  switch($mode){
  case Player::SURVIVAL:
  return 'Survival';
  case Player::CREATIVE:
  return 'Creative';
  case Player::ADVENTURE:
  return 'Adventure';
  case Player::SPECTATOR:
  return 'Spectator';
  default:
  throw new \InvalidArgumentException("Invalid gamemode $mode");
  }
 }
 public static function getGamemodeFromString(string $str){
  switch(strtolower(trim($str))){
  case Player::SURVIVAL:
  case 'survival':
  case 's':
  return Player::SURVIVAL;
  case Player::CREATIVE:
  case 'creative':
  case 'c':
  return Player::CREATIVE;
  case (string) Player::ADVENTURE:
  case 'adventure':
  case 'a':
  return Player::ADVENTURE;
  case (string) Player::SPECTATOR:
  case 'spectator':
  case 'view':
  case 'v':
  return Player::SPECTATOR;
  }
  return -1;
 }
 public static function getDifficultyFromString($str){
  switch(strtolower(trim($str))){
  case '0':
  case 'peaceful':
  case 'p':
  return 0;
  case '1':
  case 'easy':
  case 'e':
  return 1;
  case '2':
  case 'normal':
  case 'n':
  return 2;
  case '3':
  case 'hard':
  case 'h':
  return 3;
  }
  return -1;
 }
 public function getDifficulty(){
  return $this->getConfigInt('difficulty', 1);
 }
 public function hasWhitelist() : bool{
  return $this->getConfigBoolean('white-list', false);
 }
 public function getSpawnRadius(){
  return $this->getConfigInt('spawn-protection', 16);
 }
 public function getAllowFlight() : bool{
  return true;
 }
 public function isHardcore(){
  return $this->getConfigBoolean('hardcore', false);
 }
 public function getDefaultGamemode(){
  return $this->getConfigInt('gamemode', 0) & 0b11;
 }
 public function getMotd() : string{
  return $this->getConfigString('motd', 'Minecraft: PE Server');
 }
 public function getLoader(){
  return $this->autoloader;
 }
 public function getLogger(){
  return $this->logger;
 }
 public function getEntityMetadata(){
  return $this->entityMetadata;
 }
 public function getPlayerMetadata(){
  return $this->playerMetadata;
 }
 public function getLevelMetadata(){
  return $this->levelMetadata;
 }
 public function getPluginManager(){
  return $this->pluginManager;
 }
 public function getCraftingManager(){
  return $this->craftingManager;
 }
 public function getResourceManager() : ResourcePackManager{
  return $this->resourceManager;
 }
 public function getResourcePackManager() : ResourcePackManager{
  return $this->resourceManager;
 }
 public function getScheduler() : ?ServerScheduler{
  return $this->scheduler;
 }
 public function getTick() : int{
  return $this->tickCounter;
 }
 public function getTicksPerSecond() : float{
  return round($this->currentTPS, 2);
 }
 public function getTicksPerSecondAverage() : float{
  return round(array_sum($this->tickAverage) / count($this->tickAverage), 2);
 }
 public function getTickUsage() : float{
  return round($this->currentUse * 100, 2);
 }
 public function getTickUsageAverage() : float{
  return round((array_sum($this->useAverage) / count($this->useAverage)) * 100, 2);
 }
 public function getCommandMap() : SimpleCommandMap{
  return $this->commandMap;
 }
 public function getOnlinePlayers() : array{
  return $this->playerList;
 }
 public function addRecipe(Recipe $recipe) : void{
  $this->craftingManager->registerRecipe($recipe);
 }
 public function shouldSavePlayerData() : bool{
  return (bool) $this->getProperty('player.save-player-data', true);
 }
 public function getOfflinePlayer(string $name){
  $name = strtolower($name);
  $result = $this->getPlayerExact($name);
  if($result === null){
   $result = new OfflinePlayer($this, $name);
  }
  return $result;
 }
 private function getPlayerDataPath(string $username) : string{
  return $this->getDataPath() . '/players/' . strtolower($username) . '.dat';
 }
 public function hasOfflinePlayerData(string $name) : bool{
  return file_exists($this->getPlayerDataPath($name));
 }
 public function getOfflinePlayerData(string $name) : CompoundTag{
  $name = strtolower($name);
  $path = $this->getDataPath() . 'players/';
    if($this->shouldSavePlayerData()){
   if(file_exists($path . "$name.dat")){
  try{
   $nbt = new NBT(NBT::BIG_ENDIAN);
   $nbt->readCompressed(file_get_contents($path . "$name.dat"));
   return $nbt->getData();
  }
  catch(\Throwable $e){ 
   rename($path . "$name.dat", $path . "$name.dat.bak");
   $this->logger->notice($this->getLanguage()->translateString('pocketmine.data.playerCorrupted', [$name]));
  }
   }
   else{
   $this->logger->notice($this->getLanguage()->translateString('pocketmine.data.playerNotFound', [$name]));
   }
    }
  $spawn = $this->getDefaultLevel()->getSafeSpawn();
  $currentTimeMillis = (int) (microtime(true) * 1000);
  $nbt = new CompoundTag('', [
  new LongTag('firstPlayed', $currentTimeMillis),
  new LongTag('lastPlayed', $currentTimeMillis),
  new ListTag('Pos', [
  new DoubleTag(0, $spawn->x),
  new DoubleTag(1, $spawn->y),
  new DoubleTag(2, $spawn->z)
  ]),
  new StringTag("Level", $this->getDefaultLevel()->getName()),
  new ListTag("Inventory", []),
  new ListTag("EnderChestInventory", []),
  new CompoundTag("Achievements", []),
  new IntTag("playerGameType", $this->getGamemode()),
  new ListTag("Motion", [
  new DoubleTag(0, 0.0),
  new DoubleTag(1, 0.0),
  new DoubleTag(2, 0.0)
  ]),
  new ListTag("Rotation", [
  new FloatTag(0, 0.0),
  new FloatTag(1, 0.0)
  ]),
  new FloatTag("FallDistance", 0.0),
  new ShortTag("Fire", 0),
  new ShortTag("Air", 300),
  new ByteTag("OnGround", 1),
  new ByteTag("Invulnerable", 0),
  new StringTag("NameTag", $name),
  new ShortTag("Health", 20),
  new ShortTag("MaxHealth", 20),
  ]);
  $nbt->Pos->setTagType(NBT::TAG_Double);
  $nbt->Inventory->setTagType(NBT::TAG_Compound);
  $nbt->EnderChestInventory->setTagType(NBT::TAG_Compound);
  $nbt->Motion->setTagType(NBT::TAG_Double);
  $nbt->Rotation->setTagType(NBT::TAG_Float);
  $this->saveOfflinePlayerData($name, $nbt);
  return $nbt;
 }
 public function saveOfflinePlayerData(string $name, CompoundTag $nbtTag) : void{
   if($this->shouldSavePlayerData()){
  $nbt = new NBT(NBT::BIG_ENDIAN);
  try{
   $nbt->setData($nbtTag);
   file_put_contents($this->getDataPath() . 'players/' . strtolower($name) . '.dat', $nbt->writeCompressed());
  } 
  catch(\Throwable $e){
   $this->logger->critical($this->getLanguage()->translateString("pocketmine.data.saveError", [$name, $e->getMessage()]));
   $this->logger->logException($e);
  }
   }
 }
 public function getPlayer(string $name) : ?Player{
  $found = null;
  $name = strtolower($name);
  $delta = PHP_INT_MAX;
    foreach($this->getOnlinePlayers() as $player){
   if(stripos($player->getName(), $name) === 0){
  $curDelta = strlen($player->getName()) - strlen($name);
  if($curDelta < $delta){
   $found = $player;
   $delta = $curDelta;
  }
  if($curDelta === 0){
   break;
  }
   }
    }
  return $found;
 }
 public function getPlayerExact(string $name) : ?Player{
  $name = strtolower($name);
   foreach($this->getOnlinePlayers() as $player){
  if(strtolower($player->getName()) === $name){
   return $player;
  }
   }
  return null;
 }
 public function matchPlayer($partialName){
  $partialName = strtolower($partialName);
  $matchedPlayers = [];
   foreach($this->getOnlinePlayers() as $player){
  if(strtolower($player->getName()) === $partialName){
   $matchedPlayers = [$player];
   break;
  }
  elseif(stripos($player->getName(), $partialName) !== false){
   $matchedPlayers[] = $player;
  }
   }
  return $matchedPlayers;
 }
 public function getPlayerByRawUUID(string $rawUUID) : ?Player{
  return $this->playerList[$rawUUID] ?? null;
 }
 public function getPlayerByUUID(UUID $uuid) : ?Player{
  return $this->getPlayerByRawUUID($uuid->toBinary());
 }
 public function removePlayer(Player $player) : void{
  unset($this->players[spl_object_hash($player)]);
 }
 public function getLevels(){
  return $this->levels;
 }
 public function getDefaultLevel(){
  return $this->levelDefault;
 }
 public function setDefaultLevel(?Level $level) : void{
  if($level === null or ($this->isLevelLoaded($level->getFolderName()) and $level !== $this->levelDefault)){
   $this->levelDefault = $level;
  }
 }
 public function isLevelLoaded($name) : bool{
  return $this->getLevelByName($name) instanceof Level;
 }
 public function getLevel($levelId){
  return $this->levels[$levelId] ?? null;
 }
 public function getLevelByName($name){
   foreach($this->getLevels() as $level){
  if($level->getFolderName() === $name){
   return $level;
  }
   }
  return null;
 }
 public function getExpectedExperience($level){
  if(isset($this->expCache[$level])) return $this->expCache[$level];
  $levelSquared = $level ** 2;
  if($level < 16) $this->expCache[$level] = $levelSquared + 6 * $level;
  elseif($level < 31) $this->expCache[$level] = 2.5 * $levelSquared - 40.5 * $level + 360;
  else $this->expCache[$level] = 4.5 * $levelSquared - 162.5 * $level + 2220;
  return $this->expCache[$level];
 }
 public function unloadLevel(Level $level, $forceUnload = false){
  if($level === $this->getDefaultLevel() and !$forceUnload){
   throw new \InvalidStateException("The default world cannot be unloaded while running, please switch worlds.");
  } 
  return $level->unload($forceUnload);
 }
 public function removeLevel(Level $level) : void{
  unset($this->levels[$level->getId()]);
 }
 public function loadLevel($name){
  if(trim($name) === ''){
   throw new LevelException("Invalid empty level name");
  }
  if($this->isLevelLoaded($name)){
   return true;
  }
  elseif(!$this->isLevelGenerated($name)){
   $this->logger->notice($this->getLanguage()->translateString("pocketmine.level.notFound", [$name]));
   return false;
  }
  $path = $this->getDataPath() . "worlds/" . $name . "/";
  $provider = LevelProviderManager::getProvider($path);
  if($provider === null){
   $this->logger->error($this->getLanguage()->translateString("pocketmine.level.loadError", [$name, "Cannot identify format of world"]));
   return false;
  }
  try{
   $level = new Level($this, $name, $path, $provider);
  }
  catch(\Throwable $e){
   $this->logger->error($this->getLanguage()->translateString("pocketmine.level.loadError", [$name, $e->getMessage()]));
   if($this->logger instanceof MainLogger){
   $this->logger->logException($e);
   }
  return false;
  }
  $this->levels[$level->getId()] = $level;
  $level->initLevel();
  $this->getPluginManager()->callEvent(new LevelLoadEvent($level));
  return true;
 }
 public function generateLevel($name, $seed = null, $generator = null, $options = []){
  if(trim($name) === "" or $this->isLevelGenerated($name)){
   return false;
  }
  $seed = $seed ?? random_int(INT32_MIN, INT32_MAX);
  if(!isset($options["preset"])){
   $options["preset"] = $this->getConfigString("generator-settings", "");
  }
  if(!($generator !== null and class_exists($generator, true) and is_subclass_of($generator, Generator::class))){
   $generator = Generator::getGenerator($this->getLevelType());
  }
   if(($provider = LevelProviderManager::getProviderByName($providerName = $this->getProperty("level-settings.default-format", "pmanvil"))) === null){
   $provider = LevelProviderManager::getProviderByName($providerName = "pmanvil");
  if($provider === null){
   throw new \InvalidStateException("Default level provider has not been registered");
  }
   }
  try{
   $path = $this->getDataPath() . "worlds/" . $name . "/";
   $provider::generate($path, $name, $seed, $generator, $options);
   $level = new Level($this, $name, $path, $provider);
   $this->levels[$level->getId()] = $level;
   $level->initLevel();
  } 
  catch(\Throwable $e){
   $this->logger->error($this->getLanguage()->translateString("pocketmine.level.generationError", [$name, $e->getMessage()]));
   if($this->logger instanceof MainLogger){
   $this->logger->logException($e);
   }
  return false;
  }
  $this->getPluginManager()->callEvent(new LevelInitEvent($level));
  $this->getPluginManager()->callEvent(new LevelLoadEvent($level));
  $this->getLogger()->notice($this->getLanguage()->translateString("pocketmine.level.backgroundGeneration", [$name]));
  $spawnLocation = $level->getSpawnLocation();
  $centerX = $spawnLocation->getFloorX() >> 4;
  $centerZ = $spawnLocation->getFloorZ() >> 4;
  $order = [];
   for($X = -3; $X <= 3; ++$X){
  for($Z = -3; $Z <= 3; ++$Z){
   $distance = $X ** 2 + $Z ** 2;
   $chunkX = $X + $centerX;
   $chunkZ = $Z + $centerZ;
   $index = Level::chunkHash($chunkX, $chunkZ);
   $order[$index] = $distance;
  }
   }
  asort($order);
  foreach($order as $index => $distance){
   Level::getXZ($index, $chunkX, $chunkZ);
   $level->populateChunk($chunkX, $chunkZ, true);
  }
  return true;
 }
 public function isLevelGenerated($name){
  if(trim($name) === ""){
   return false;
  }
  $path = $this->getDataPath() . "worlds/" . $name . "/";
  if(!($this->getLevelByName($name) instanceof Level)){
   return is_dir($path) and !empty(array_filter(scandir($path, SCANDIR_SORT_NONE), function($v){
   return $v !== ".." and $v !== ".";
   }));
  }
  return true;
 }
 public function findEntity(int $entityId, Level $expectedLevel = null){
   foreach($this->levels as $level){
  assert(!$level->isClosed());
  if(($entity = $level->getEntity($entityId)) instanceof Entity){
   return $entity;
  }
   }
  return null;
 }
 public function getConfigString($variable, $defaultValue = ""){
  $v = getopt("", ["$variable::"]);
  if(isset($v[$variable])){
   return (string) $v[$variable];
  }
  return $this->properties->exists($variable) ? $this->properties->get($variable) : $defaultValue;
 }
 public function getProperty($variable, $defaultValue = null){
   if(!array_key_exists($variable, $this->propertyCache)){
   $v = getopt("", ["$variable::"]);
  if(isset($v[$variable])){
   $this->propertyCache[$variable] = $v[$variable];
  }
  else{
   $this->propertyCache[$variable] = $this->config->getNested($variable);
  }
   }
  return $this->propertyCache[$variable] === null ? $defaultValue : $this->propertyCache[$variable];
 }
 public function setConfigString($variable, $value){
  $this->properties->set($variable, $value);
 }
 public function getConfigInt($variable, $defaultValue = 0){
  $v = getopt("", ["$variable::"]);
  if(isset($v[$variable])){
   return (int) $v[$variable];
  }
  return $this->properties->exists($variable) ? (int) $this->properties->get($variable) : (int) $defaultValue;
 }
 public function setConfigInt($variable, $value){
  $this->properties->set($variable, (int) $value);
 }
 public function getConfigBoolean($variable, $defaultValue = false){
  $v = getopt("", ["$variable::"]);
  if(isset($v[$variable])){
   $value = $v[$variable];
  }
  else{
   $value = $this->properties->exists($variable) ? $this->properties->get($variable) : $defaultValue;
  }
  if(is_bool($value)){
   return $value;
  }
  switch(strtolower($value)){
  case "on":
  case "true":
  case "1":
  case "yes":
  return true;
  }
  return false;
 }
 public function setConfigBool($variable, $value){
  $this->properties->set($variable, $value ? "1" : "0");
 }
 public function getPluginCommand($name){
  if(($command = $this->commandMap->getCommand($name)) instanceof PluginIdentifiableCommand){
   return $command;
  }
  else{
   return null;
  }
 }
 public function getNameBans(){
  return $this->banByName;
 }
 public function getIPBans(){
  return $this->banByIP;
 }
 public function getCIDBans(){
  return $this->banByCID;
 }
 public function addOp($name) : void{
  $this->operators->set(strtolower($name), true);
  if(($player = $this->getPlayerExact($name)) !== null){
   $player->recalculatePermissions();
  }
  $this->operators->save();
 }
 public function removeOp($name) : void{
   foreach($this->operators->getAll() as $opName => $dummyValue){
  if(strtolower($name) === strtolower($opName)){
   $this->operators->remove($opName);
  }
   }
  if(($player = $this->getPlayerExact($name)) !== null){
   $player->recalculatePermissions();
  }
  $this->operators->save();
 }
 public function addWhitelist($name) : void{
  $this->whitelist->set(strtolower($name), true);
  $this->whitelist->save();
 }
 public function removeWhitelist($name) : void{
  $this->whitelist->remove(strtolower($name));
  $this->whitelist->save();
 }
 public function isWhitelisted($name) : bool{
  return !$this->hasWhitelist() or $this->whitelist->exists($name, true);
 }
 public function isOp($name) : bool{
  return $this->operators->exists($name, true);
 }
 public function getWhitelisted(){
  return $this->whitelist;
 }
 public function getOps(){
  return $this->operators;
 }
 public function reloadWhitelist(){
  $this->whitelist->reload();
 }
 public function getCommandAliases(){
  $section = $this->getProperty("aliases");
  $result = [];
    if(is_array($section)){
   foreach($section as $key => $value){
  $commands = [];
  if(is_array($value)){
   $commands = $value;
  }
  else{
   $commands[] = (string) $value;
  }
  $result[$key] = $commands;
   }
    }
  return $result;
 }
 public function getCrashPath(){
  return $this->dataPath . "crashdumps/";
 }
 public static function getInstance() : Server{
  if(self::$instance === null){
   throw new \RuntimeException("Attempt to retrieve Server instance outside server thread");
  }
  return self::$instance;
 }
 public static function microSleep(int $microseconds) : void{
  if(self::$sleeper === null){
   self::$sleeper = new \Threaded();
  }
  self::$sleeper->synchronized(function(int $ms) : void{
  Server::$sleeper->wait($ms);
  }, $microseconds);
 }
 public function loadAdvancedConfig() : void{
  $this->weatherEnabled = $this->getAdvancedProperty("level.weather", true);
  $this->keepExperience = $this->getAdvancedProperty("player.keep-experience", false);
  $this->loadIncompatibleAPI = $this->getAdvancedProperty("developer.load-incompatible-api", true);
  $this->netherEnabled = $this->getAdvancedProperty("nether.allow-nether", false);
  $this->enderEnabled = $this->getAdvancedProperty("ender.allow-ender", false);
  $this->weatherRandomDurationMin = $this->getAdvancedProperty("level.weather-random-duration-min", 6000);
  $this->weatherRandomDurationMax = $this->getAdvancedProperty("level.weather-random-duration-max", 12000);
  $this->lightningTime = $this->getAdvancedProperty("level.lightning-time", 200);
  $this->lightningFire = $this->getAdvancedProperty("level.lightning-fire", false);
  $this->allowSnowGolem = $this->getAdvancedProperty("server.allow-snow-golem", false);
  $this->allowIronGolem = $this->getAdvancedProperty("server.allow-iron-golem", false);
  $this->dserverConfig = [
  "enable" => $this->getAdvancedProperty("dserver.enable", false),
  "queryAutoUpdate" => $this->getAdvancedProperty("dserver.query-auto-update", false),
  "queryTickUpdate" => $this->getAdvancedProperty("dserver.query-tick-update", true),
  "motdMaxPlayers" => $this->getAdvancedProperty("dserver.motd-max-players", 0),
  "queryMaxPlayers" => $this->getAdvancedProperty("dserver.query-max-players", 0),
  "motdAllPlayers" => $this->getAdvancedProperty("dserver.motd-all-players", false),
  "queryAllPlayers" => $this->getAdvancedProperty("dserver.query-all-players", false),
  "motdPlayers" => $this->getAdvancedProperty("dserver.motd-players", false),
  "queryPlayers" => $this->getAdvancedProperty("dserver.query-players", false),
  "timer" => $this->getAdvancedProperty("dserver.time", 40),
  "retryTimes" => $this->getAdvancedProperty("dserver.retry-times", 3),
  "serverList" => explode(";", $this->getAdvancedProperty("dserver.server-list", ""))
  ];
  $this->redstoneEnabled = $this->getAdvancedProperty("redstone.enable", false);
  $this->allowFrequencyPulse = $this->getAdvancedProperty("redstone.allow-frequency-pulse", false);
  $this->pulseFrequency = $this->getAdvancedProperty("redstone.pulse-frequency", 20);
  $this->getLogger()->setWrite(!$this->getAdvancedProperty("server.disable-log", false));
  $this->chunkRadius = $this->getAdvancedProperty("player.chunk-radius", -1);
  $this->destroyBlockParticle = $this->getAdvancedProperty("server.destroy-block-particle", true);
  $this->allowSplashPotion = $this->getAdvancedProperty("server.allow-splash-potion", true);
  $this->fireSpread = $this->getAdvancedProperty("level.fire-spread", false);
  $this->advancedCommandSelector = $this->getAdvancedProperty("server.advanced-command-selector", false);
  $this->anvilEnabled = $this->getAdvancedProperty("enchantment.enable-anvil", true);
  $this->enchantingTableEnabled = $this->getAdvancedProperty("enchantment.enable-enchanting-table", true);
  $this->countBookshelf = $this->getAdvancedProperty("enchantment.count-bookshelf", false);
  $this->allowInventoryCheats = $this->getAdvancedProperty("inventory.allow-cheats", false);
  $this->folderpluginloader = $this->getAdvancedProperty("developer.folder-plugin-loader", true);
  $this->absorbWater = $this->getAdvancedProperty("server.absorb-water", false);
 }
 public function getDServerMaxPlayers() : int{
  return ($this->dserverAllPlayers + $this->getMaxPlayers());
 }
 public function getDServerOnlinePlayers() : int{
  return ($this->dserverPlayers + count($this->getOnlinePlayers()));
 }
 public function isDServerEnabled() : bool{
  return $this->dserverConfig['enable'];
 }
 public function updateDServerInfo() : void{
  $this->scheduler->scheduleAsyncTask(new DServerTask($this->dserverConfig['serverList'], $this->dserverConfig['retryTimes']));
 }
 public function getBuild(){
  return $this->version->getBuild();
 }
 public function getGameVersion(){
  return $this->version->getRelease();
 }
 public function __construct(\ClassLoader $autoloader, \ThreadedLogger $logger, $dataPath, $pluginPath, $defaultLang = "unknown"){
  if(self::$instance !== null){
   throw new \InvalidStateException("Only one server instance can exist at once");
  }
  self::$instance = $this;
  $this->tickSleeper = new SleeperHandler();
  $this->autoloader = $autoloader;
  $this->logger = $logger;
   try{
  if(!file_exists($dataPath . "worlds/")){
   mkdir($dataPath . "worlds/", 0777);
  }
  if(!file_exists($dataPath . "players/")){
   mkdir($dataPath . "players/", 0777);
  }
  if(!file_exists($pluginPath)){
   mkdir($pluginPath, 0777);
  }
  if(!file_exists($dataPath . "crashdumps/")){
   mkdir($dataPath . "crashdumps/", 0777);
  }
  $this->dataPath = realpath($dataPath) . DIRECTORY_SEPARATOR;
  $this->pluginPath = realpath($pluginPath) . DIRECTORY_SEPARATOR;
  $version = new VersionString($this->getPocketMineVersion());
  $this->version = $version;
     if(!file_exists($this->dataPath . "pocketmine.yml")){
    if(file_exists($this->dataPath . "lang.txt")){
   $langFile = new Config($configPath = $this->dataPath . "lang.txt", Config::ENUM, []);
   $wizardLang = null;
   foreach ($langFile->getAll(true) as $langName) {
   $wizardLang = $langName;
   break;
   }
   if(file_exists(\pocketmine\PATH . "src/pocketmine/resources/pocketmine_$wizardLang.yml")){
   $content = file_get_contents($file = \pocketmine\PATH . "src/pocketmine/resources/pocketmine_$wizardLang.yml");
   }
   else{
   $content = file_get_contents($file = \pocketmine\PATH . "src/pocketmine/resources/pocketmine_rus.yml");
   }
    }
    else{
   $content = file_get_contents($file = \pocketmine\PATH . "src/pocketmine/resources/pocketmine_rus.yml");
    }
   @file_put_contents($this->dataPath . "pocketmine.yml", $content);
     }
  if(file_exists($this->dataPath . "lang.txt")){
   unlink($this->dataPath . "lang.txt");
  }
  $this->config = new Config($configPath = $this->dataPath . "pocketmine.yml", Config::YAML, []);
  $nowLang = $this->getProperty("settings.language", "rus");
   if(strpos(\pocketmine\VERSION, "unsupported") !== false and !getenv('CI')){
  if($this->getProperty("settings.enable-testing", false) !== true){
   throw new ServerException("This build is not intended for production use. You may set 'settings.enable-testing: true' under pocketmine.yml to allow use of non-production builds. Do so at your own risk and ONLY if you know what you are doing.");
  }
  else{
   $this->logger->warning("You are using an unsupported build. Do not use this build in a production environment.");
  }
   }
  if($defaultLang != "unknown" and $nowLang != $defaultLang){
   @file_put_contents($configPath, str_replace('language: "' . $nowLang . '"', 'language: "' . $defaultLang . '"', file_get_contents($configPath)));
   $this->config->reload();
   unset($this->propertyCache["settings.language"]);
  }
  $lang = $this->getProperty("settings.language", BaseLang::FALLBACK_LANGUAGE);
  if(file_exists(\pocketmine\PATH . "src/pocketmine/resources/lite_$lang.yml")){
   $content = file_get_contents($file = \pocketmine\PATH . "src/pocketmine/resources/lite_$lang.yml");
  }
  else{
   $content = file_get_contents($file = \pocketmine\PATH . "src/pocketmine/resources/lite_rus.yml");
  }
  if(!file_exists($this->dataPath . "lite.yml")){
   @file_put_contents($this->dataPath . "lite.yml", $content);
  }
  $internelConfig = new Config($file, Config::YAML, []);
  $this->advancedConfig = new Config($this->dataPath . "lite.yml", Config::YAML, []);
  $cfgVer = $this->getAdvancedProperty("config.version", 0, $internelConfig);
  $advVer = $this->getAdvancedProperty("config.version", 0);
  $this->loadAdvancedConfig();
  $this->properties = new Config($this->dataPath . "server.properties", Config::PROPERTIES, [
  "motd" => "Minecraft: PE Server",
  "server-port" => 19132,
  "white-list" => false,
  "announce-player-achievements" => true,
  "spawn-protection" => 16,
  "max-players" => 20,
  "spawn-animals" => true,
  "spawn-mobs" => true,
  "gamemode" => 0,
  "force-gamemode" => false,
  "hardcore" => false,
  "pvp" => true,
  "difficulty" => 1,
  "generator-settings" => "",
  "level-name" => "world",
  "level-seed" => "",
  "level-type" => "DEFAULT",
  "enable-query" => true,
  "enable-rcon" => false,
  "rcon.password" => substr(base64_encode(random_bytes(20)), 3, 10),
  "auto-save" => true,
  "online-mode" => false,
  "view-distance" => 8
  ]);
  $onlineMode = $this->getConfigBoolean("online-mode", false);
  if(!extension_loaded("openssl")){
   $this->logger->info("OpenSSL extension not found");
   $this->logger->info("Please configure OpenSSL extension for PHP if you want to use Xbox Live authentication or global resource pack.");
   $this->setConfigBool("online-mode", false);
  }
  $this->forceLanguage = $this->getProperty("settings.force-language", false);
  $this->baseLang = new BaseLang($this->getProperty("settings.language", BaseLang::FALLBACK_LANGUAGE));
  $this->memoryManager = new MemoryManager($this);
   if(($poolSize = $this->getProperty("settings.async-workers", "auto")) === "auto"){
  $poolSize = ServerScheduler::$WORKERS;
  $processors = Utils::getCoreCount() - 2;
  if($processors > 0){
   $poolSize = max(1, $processors);
  }
   }
   else{
   $poolSize = max(1, (int) $poolSize);
   }
  ServerScheduler::$WORKERS = $poolSize;
  if($this->getProperty("network.batch-threshold", 256) >= 0){
   Network::$BATCH_THRESHOLD = (int) $this->getProperty("network.batch-threshold", 256);
  }
  else{
   Network::$BATCH_THRESHOLD = -1;
  }
  $this->networkCompressionLevel = (int) $this->getProperty("network.compression-level", 6);
  if($this->networkCompressionLevel < 1 or $this->networkCompressionLevel > 9){
   $this->logger->warning("Invalid network compression level $this->networkCompressionLevel set, setting to default 6");
   $this->networkCompressionLevel = 6;
  }
  $this->networkCompressionAsync = (bool) $this->getProperty("network.async-compression", true);
  $this->scheduler = new ServerScheduler();
  $consoleNotifier = new SleeperNotifier();
  $this->console = new CommandReader($consoleNotifier);
  $this->tickSleeper->addNotifier($consoleNotifier, function() : void{
  $this->checkConsole();
  });
  $this->console->start(PTHREADS_INHERIT_CONSTANTS);
   if($this->getConfigBoolean("enable-rcon", false)){
  try{
   $this->rcon = new RCON(
   $this,
   $this->getConfigString("rcon.password", ""),
   $this->getConfigInt("rcon.port", $this->getPort()),
   $this->getIp(),
   $this->getConfigInt("rcon.max-clients", 50)
   );
  }
  catch(\Exception $e){
   $this->getLogger()->critical("RCON can't be started: " . $e->getMessage());
  }
   }
  $this->entityMetadata = new EntityMetadataStore();
  $this->playerMetadata = new PlayerMetadataStore();
  $this->levelMetadata = new LevelMetadataStore();
  $this->operators = new Config($this->dataPath . "ops.txt", Config::ENUM);
  $this->whitelist = new Config($this->dataPath . "white-list.txt", Config::ENUM);
  if(file_exists($this->dataPath . "banned.txt") and !file_exists($this->dataPath . "banned-players.txt")){
   @rename($this->dataPath . "banned.txt", $this->dataPath . "banned-players.txt");
  }
  @touch($this->dataPath . "banned-players.txt");
  $this->banByName = new BanList($this->dataPath . "banned-players.txt");
  $this->banByName->load();
  @touch($this->dataPath . "banned-ips.txt");
  $this->banByIP = new BanList($this->dataPath . "banned-ips.txt");
  $this->banByIP->load();
  @touch($this->dataPath . "banned-cids.txt");
  $this->banByCID = new BanList($this->dataPath . "banned-cids.txt");
  $this->banByCID->load();
  $this->maxPlayers = $this->getConfigInt("max-players", 20);
  $this->setAutoSave($this->getConfigBoolean("auto-save", true));
  if($this->getConfigBoolean("hardcore", false) and $this->getDifficulty() < 3){
   $this->setConfigInt("difficulty", 3);
  }
  define('pocketmine\DEBUG', (int) $this->getProperty("debug.level", 1));
  if(((int) ini_get('zend.assertions')) !== -1){
   $this->logger->warning("Debugging assertions are enabled, this may impact on performance. To disable them, set `zend.assertions = -1` in php.ini.");
  }
  ini_set('assert.exception', '1');
  if($this->logger instanceof MainLogger){
   $this->logger->setLogDebug(\pocketmine\DEBUG > 1);
  }
  if(\pocketmine\DEBUG >= 0){
   @cli_set_process_title($this->getName() . " " . $this->getPocketMineVersion());
  }
  $this->serverID = Utils::getMachineUniqueId($this->getIp() . $this->getPort());
  $this->getLogger()->debug("Server unique id: " . $this->getServerUniqueId());
  $this->getLogger()->debug("Machine unique id: " . Utils::getMachineUniqueId());
  $this->network = new Network($this);
  $this->network->setName($this->getMotd());
  Timings::init();
  $this->consoleSender = new ConsoleCommandSender();
  $this->commandMap = new SimpleCommandMap($this);
  Entity::init();
  Tile::init();
  InventoryType::init();
  Block::init();
  Enchantment::init();
  Item::init();
  Biome::init();
  EnchantmentLevelTable::init();
  Color::init();
  LevelProviderManager::addProvider(Anvil::class);
  LevelProviderManager::addProvider(McRegion::class);
  LevelProviderManager::addProvider(PMAnvil::class);
  if(extension_loaded("leveldb")){
   $this->logger->debug($this->getLanguage()->translateString("pocketmine.debug.enable"));
   LevelProviderManager::addProvider(LevelDB::class);
  }
  //Generator::addGenerator(Flat::class, "flat");
  //Generator::addGenerator(Normal::class, "normal");
  Generator::addGenerator(Normal::class, "default");
  //Generator::addGenerator(Nether::class, "hell");
  //Generator::addGenerator(Nether::class, "nether");
  //Generator::addGenerator(VoidGenerator::class, "void");
  //Generator::addGenerator(Normal2::class, "normal2");
  //Generator::addGenerator(Ender::class, "ender");
  $this->craftingManager = new CraftingManager();
  $this->resourceManager = new ResourcePackManager($this, $this->getDataPath() . "resource_packs" . DIRECTORY_SEPARATOR);
  $this->pluginManager = new PluginManager($this, $this->commandMap);
  $this->pluginManager->subscribeToPermission(Server::BROADCAST_CHANNEL_ADMINISTRATIVE, $this->consoleSender);
  $this->pluginManager->setUseTimings($this->getProperty("settings.enable-profiling", false));
  $this->profilingTickRate = (float) $this->getProperty("settings.profile-report-trigger", 20);
  $this->pluginManager->registerInterface(PharPluginLoader::class);
  if($this->folderpluginloader){
   $this->pluginManager->registerInterface(FolderPluginLoader::class);
  }
  $this->pluginManager->registerInterface(ScriptPluginLoader::class);
  register_shutdown_function([$this, "crashDump"]);
  $this->queryRegenerateTask = new QueryRegenerateEvent($this);
  $this->pluginManager->loadPlugins($this->pluginPath);
  $this->enablePlugins(PluginLoadOrder::STARTUP);
  $this->network->registerInterface(new RakLibInterface($this));
   foreach((array) $this->getProperty("worlds", []) as $name => $options){
  if($options === null){
   $options = [];
  }
  elseif(!is_array($options)){
   continue;
  }
   if(!$this->loadLevel($name)){
   $seed = $options["seed"] ?? time();
  if(is_string($seed) and !is_numeric($seed)){
   $seed = Utils::javaStringHash($seed);
  }
  elseif(!is_int($seed)){
   $seed = (int) $seed;
  }
  $options = explode(":", $this->getProperty("worlds.$name.generator", Generator::getGenerator("default")));
  $generator = Generator::getGenerator(array_shift($options));
  if(count($options) > 0){
   $options = ["preset" => implode(":", $options)];
  }
  else{
   $options = [];
  }
  $this->generateLevel($name, $seed, $generator, $options);
   }
    }
   if($this->getDefaultLevel() === null){
  $default = $this->getConfigString("level-name", "world");
  if(trim($default) == ""){
   $this->getLogger()->warning("level-name cannot be null, using default");
   $default = "world";
   $this->setConfigString("level-name", "world");
  }
   if(!$this->loadLevel($default)){
   $seed = $this->getConfigString("level-seed", (string) time());
  if(!is_numeric($seed) or bccomp($seed, "9223372036854775807") > 0){
   $seed = Utils::javaStringHash($seed);
  }
  else{
   $seed = (int) $seed;
  }
  $this->generateLevel($default, $seed === 0 ? time() : $seed);
   }
  $this->setDefaultLevel($this->getLevelByName($default));
    }
  if($this->properties->hasChanged()){
   $this->properties->save();
  }
  if(!($this->getDefaultLevel() instanceof Level)){
   $this->getLogger()->emergency($this->getLanguage()->translateString("pocketmine.level.defaultError"));
   $this->forceShutdown();
   return;
  }
  $netherName = 'nether';
  $enderName = 'ender';
   if($this->netherEnabled){
  if(!$this->loadLevel($netherName)){
   $this->generateLevel($netherName, time(), Generator::getGenerator('nether'));
  }
   $this->netherLevel = $this->getLevelByName($netherName);
   }
   if($this->enderEnabled){
  if(!$this->loadLevel($enderName)){
   $this->generateLevel($enderName, time(), Generator::getGenerator('ender'));
  }
   $this->enderLevel = $this->getLevelByName($enderName);
   }
  if($this->getProperty("ticks-per.autosave", 6000) > 0){
   $this->autoSaveTicks = (int) $this->getProperty("ticks-per.autosave", 6000);
  }
  $this->enablePlugins(PluginLoadOrder::POSTWORLD);
   if($this->dserverConfig["enable"] and ($this->getAdvancedProperty("dserver.server-list", "") != "")) $this->scheduler->scheduleRepeatingTask(new CallbackTask([
  $this,
  "updateDServerInfo"
  ]), $this->dserverConfig["timer"]);
  if($cfgVer > $advVer){
   $this->logger->notice("Your lite.yml needs update");
   $this->logger->notice("Current Version: $advVer   Latest Version: $cfgVer");
  }
  $this->start();
   }
  catch(\Throwable $e){
   $this->exceptionHandler($e);
  }
 }
 public function broadcastMessage(mixed $message, ?array $recipients = null) : int{
  if(!is_array($recipients)){
   return $this->broadcast($message, self::BROADCAST_CHANNEL_USERS);
  }
  foreach($recipients as $recipient){
   $recipient->sendMessage($message);
  }
  return count($recipients);
 }
 public function broadcastTip(mixed $tip, ?array $recipients = null) : int{
    if(!is_array($recipients)){
  $recipients = [];
   foreach($this->pluginManager->getPermissionSubscriptions(self::BROADCAST_CHANNEL_USERS) as $permissible){
  if($permissible instanceof Player and $permissible->hasPermission(self::BROADCAST_CHANNEL_USERS)){
   $recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
  }
   }
    }
  foreach($recipients as $recipient){
   $recipient->sendTip($tip);
  }
  return count($recipients);
 }
 public function broadcastPopup(mixed $popup, ?array $recipients = null) : int{
    if(!is_array($recipients)){
  $recipients = [];
   foreach($this->pluginManager->getPermissionSubscriptions(self::BROADCAST_CHANNEL_USERS) as $permissible){
  if($permissible instanceof Player and $permissible->hasPermission(self::BROADCAST_CHANNEL_USERS)){
   $recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
  }
   }
    }
  foreach($recipients as $recipient){
   $recipient->sendPopup($popup);
  }
  return count($recipients);
 }
 public function broadcastTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1, ?array $recipients = null){
    if(!is_array($recipients)){
  $recipients = [];
   foreach($this->pluginManager->getPermissionSubscriptions(self::BROADCAST_CHANNEL_USERS) as $permissible){
  if($permissible instanceof Player and $permissible->hasPermission(self::BROADCAST_CHANNEL_USERS)){
   $recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
  }
   }
    }
  foreach($recipients as $recipient){
   $recipient->addTitle($title, $subtitle, $fadeIn, $stay, $fadeOut);
  }
  return count($recipients);
 }
 public function broadcast(mixed $message, string $permissions) : int{
  $recipients = [];
    foreach(explode(";", $permissions) as $permission){
   foreach($this->pluginManager->getPermissionSubscriptions($permission) as $permissible){
  if($permissible instanceof CommandSender and $permissible->hasPermission($permission)){
   $recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
  }
   }
    }
  foreach($recipients as $recipient){
   $recipient->sendMessage($message);
  }
  return count($recipients);
 }
 public function broadcastPacket(array $players, DataPacket $packet) : void{
  $packet->encode();
  $packet->isEncoded = true;
  $this->batchPackets($players, [$packet], false);
 }
 public function batchPackets(array $players, array $packets, bool $forceSync = false, bool $immediate = false) : void{
  if(count($packets) === 0){
   throw new \InvalidArgumentException("Cannot send empty batch");
  }
  Timings::$playerNetworkTimer->startTiming();
  $targets = array_filter($players, function(Player $player) : bool{ return $player->isConnected(); });
   if(count($targets) > 0){
   $pk = new BatchPacket();
  foreach($packets as $p){
   $pk->addPacket($p);
  }
  if(Network::$BATCH_THRESHOLD >= 0 and strlen($pk->payload) >= Network::$BATCH_THRESHOLD){
   $pk->setCompressionLevel($this->networkCompressionLevel);
  }
  else{
   $pk->setCompressionLevel(0); //Do not compress packets under the threshold
   $forceSync = true;
  }
  if(!$forceSync and !$immediate and $this->networkCompressionAsync){
   $task = new CompressBatchedTask($pk, $targets);
   $this->getScheduler()->scheduleAsyncTask($task);
  }
  else{
   $this->broadcastPacketsCallback($pk, $targets, $immediate);
  }
   }
  Timings::$playerNetworkTimer->stopTiming();
 }
 public function broadcastPacketsCallback(BatchPacket $pk, array $players, bool $immediate = false) : void{
  if(!$pk->isEncoded){
   $pk->encode();
   $pk->isEncoded = true;
  }
  foreach($players as $i){
   $i->dataPacket($pk, false, $immediate);
  }
 }
 public function enablePlugins(int $type) : void{
   foreach($this->pluginManager->getPlugins() as $plugin){
  if(!$plugin->isEnabled() and $plugin->getDescription()->getOrder() === $type){
   $this->enablePlugin($plugin);
  }
   }
  if($type === PluginLoadOrder::POSTWORLD){
   $this->commandMap->registerServerAliases();
   DefaultPermissions::registerCorePermissions();
  }
 }
 public function enablePlugin(Plugin $plugin) : void{
  $this->pluginManager->enablePlugin($plugin);
 }
 public function disablePlugins() : void{
  $this->pluginManager->disablePlugins();
 }
 public function checkConsole() : void{
  Timings::$serverCommandTimer->startTiming();
   while(($line = $this->console->getLine()) !== null){
  $this->pluginManager->callEvent($ev = new ServerCommandEvent($this->consoleSender, $line));
  if(!$ev->isCancelled()){
   $this->dispatchCommand($ev->getSender(), $ev->getCommand());
  } 
   }
  Timings::$serverCommandTimer->stopTiming();
 }
 public function dispatchCommand(CommandSender $sender, $commandLine) : bool{
  if($this->commandMap->dispatch($sender, $commandLine)){
   return true;
  }
  $sender->sendMessage(new TranslationContainer(TextFormat::GOLD . "%commands.generic.notFound"));
  return false;
 }
 public function reload() : void{
  $this->logger->info("Saving worlds...");
  foreach($this->levels as $level){
   $level->save();
  }
  $this->pluginManager->disablePlugins();
  $this->pluginManager->clearPlugins();
  $this->commandMap->clearCommands();
  $this->logger->info("Reloading properties...");
  $this->properties->reload();
  $this->advancedConfig->reload();
  $this->loadAdvancedConfig();
  $this->maxPlayers = $this->getConfigInt("max-players", 20);
  if($this->getConfigBoolean("hardcore", false) and $this->getDifficulty() < 3){
   $this->setConfigInt("difficulty", 3);
  }
  $this->banByIP->load();
  $this->banByName->load();
  $this->banByCID->load();
  $this->reloadWhitelist();
  $this->operators->reload();
  $this->memoryManager->doObjectCleanup();
  foreach($this->getIPBans()->getEntries() as $entry){
   $this->getNetwork()->blockAddress($entry->getName(), -1);
  }
  $this->pluginManager->registerInterface(PharPluginLoader::class);
  if($this->folderpluginloader) {
   $this->pluginManager->registerInterface(FolderPluginLoader::class);
  }
  $this->pluginManager->registerInterface(ScriptPluginLoader::class);
  $this->pluginManager->loadPlugins($this->pluginPath);
  $this->enablePlugins(PluginLoadOrder::STARTUP);
  $this->enablePlugins(PluginLoadOrder::POSTWORLD);
  TimingsHandler::reload();
 } 
 public function shutdown(bool $restart = false, string $msg = "") : void{
  $this->isRunning = false;
  if($msg != ""){
   $this->propertyCache["settings.shutdown-message"] = $msg;
  }
 }
 public function forceShutdown(){
  if($this->hasStopped){
   return;
  }
   try{
  if(!$this->isRunning()){
   $this->sendUsage(SendUsageTask::TYPE_CLOSE);
  }
  $this->hasStopped = true;
  $this->shutdown();
  if($this->rcon instanceof RCON){
   $this->rcon->stop();
  }
  if($this->getProperty("network.upnp-forwarding", false)){
   $this->logger->info("[UPnP] Removing port forward...");
   UPnP::RemovePortForward($this->getPort());
  }
  if($this->pluginManager instanceof PluginManager){
   $this->getLogger()->debug("Disabling all plugins");
   $this->pluginManager->disablePlugins();
  }
  foreach($this->players as $player){
   $player->close($player->getLeaveMessage(), $this->getProperty("settings.shutdown-message", "Server closed"));
  }
  $this->getLogger()->debug("Unloading all worlds");
  foreach($this->getLevels() as $level){
   $this->unloadLevel($level, true);
  }
  $this->getLogger()->debug("Removing event handlers");
  HandlerList::unregisterAll();
  if($this->scheduler instanceof ServerScheduler){
   $this->getLogger()->debug("Shutting down task scheduler");
   $this->scheduler->shutdown();
  }
  if($this->properties !== null and $this->properties->hasChanged()){
   $this->getLogger()->debug("Saving properties");
   $this->properties->save();
  }
  if($this->console instanceof CommandReader){
   $this->getLogger()->debug("Closing console");
   $this->console->shutdown();
   $this->console->notify();
  }
   if($this->network instanceof Network){
   $this->getLogger()->debug("Stopping network interfaces");
  foreach($this->network->getInterfaces() as $interface){
   $this->getLogger()->debug("Stopping network interface " . get_class($interface));
   $interface->shutdown();
   $this->network->unregisterInterface($interface);
  }
   }
  }
  catch(\Throwable $e){
   $this->logger->logException($e);
   $this->logger->emergency("Crashed while crashing, killing process");
   @Utils::kill(getmypid());
  }
 }
 public function getQueryInformation(){
  return $this->queryRegenerateTask; 
 }
 public function start() : void{
  if($this->getConfigBoolean('enable-query', true)){
   $this->queryHandler = new QueryHandler();
  }
  foreach($this->getIPBans()->getEntries() as $entry){
   $this->network->blockAddress($entry->getName(), -1);
  }
  if($this->getProperty('settings.send-usage', true)){
   $this->sendUsageTicker = 6000;
   $this->sendUsage(SendUsageTask::TYPE_OPEN);
  }
   if($this->getProperty('network.upnp-forwarding', false)){
  $this->logger->info('[UPnP] Trying to port forward...');
  try{
   UPnP::PortForward($this->getPort());
  }
  catch(\Exception $e){
   $this->logger->alert('UPnP portforward failed: ' . $e->getMessage());
  }
   }
  $this->tickCounter = 0;
  if(function_exists('pcntl_signal')){
   pcntl_signal(SIGTERM, [$this, 'handleSignal']);
   pcntl_signal(SIGINT, [$this, 'handleSignal']);
   pcntl_signal(SIGHUP, [$this, 'handleSignal']);
   $this->dispatchSignals = true;
  }
  $this->logger->info($this->getLanguage()->translateString('pocketmine.server.startFinished', [round(microtime(true) - \pocketmine\START_TIME, 3)]));
   $this->tickProcessor();
   $this->forceShutdown();
 }
 public function handleSignal($signo) : void{
  if($signo === SIGTERM or $signo === SIGINT or $signo === SIGHUP){
   $this->shutdown();
  }
 }
 public function exceptionHandler(\Throwable $e, $trace = null) : void{
   while(@ob_end_flush()){}
  if($e === null){
   return;
  }
  global $lastError;
  if($trace === null){
   $trace = $e->getTrace();
  }
  $errstr = $e->getMessage();
  $errfile = $e->getFile();
  $errline = $e->getLine();
  if(($pos = strpos($errstr, "\n")) !== false){
   $errstr = substr($errstr, 0, $pos);
  }
  $errfile = Utils::cleanPath($errfile);
  if($this->logger instanceof MainLogger){
   $this->logger->logException($e, $trace);
  }
  $lastError = [
  "type" => get_class($e),
  "message" => $errstr,
  "fullFile" => $e->getFile(),
  "file" => $errfile,
  "line" => $errline,
  "trace" => Utils::getTrace(1, $trace)
  ];
  global $lastExceptionError, $lastError;
  $lastExceptionError = $lastError;
  $this->crashDump();
 }
 public function crashDump() : void{
   while(@ob_end_flush()){}
  if(!$this->isRunning){
   return;
  }
  if($this->sendUsageTicker > 0){
   $this->sendUsage(SendUsageTask::TYPE_CLOSE);
  }
  $this->hasStopped = false;
  ini_set("error_reporting", '0');
  ini_set("memory_limit", '-1'); //Fix error dump not dumped on memory problems
  try{
   $this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.create"));
   $dump = new CrashDump($this);
  }
   catch(\Throwable $e){
  $this->logger->logException($e);
  try{
   $this->logger->critical($this->getLanguage()->translateString("pocketmine.crash.error", [$e->getMessage()]));
  }
  catch(\Throwable $e){
  }
   }
  $this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.submit", [$dump->getPath()]));
  $stamp = $this->getDataPath() . "crashdumps/.last_crash";
  $crashInterval = 120; //2 minutes
  if(file_exists($stamp) and !($report = (filemtime($stamp) + $crashInterval < time()))){
   $this->logger->debug("Not sending crashdump due to last crash less than $crashInterval seconds ago");
  }
  @touch($stamp); //update file timestamp
     if($this->getProperty("auto-report.enabled", true) !== false){
   $report = true;
   $plugin = $dump->getData()["plugin"];
    if(is_string($plugin)){
   $p = $this->pluginManager->getPlugin($plugin);
   if($p instanceof Plugin and !($p->getPluginLoader() instanceof PharPluginLoader)){
   $this->logger->debug("Not sending crashdump due to caused by non-phar plugin");
   $report = false;
   }
    }
    elseif(\Phar::running(true) === ""){
   $report = false;
    }
  if($dump->getData()["error"]["type"] === \ParseError::class){
   $report = false;
  }
   if($report){
   $reply = Utils::postURL("http://" . $this->getProperty("auto-report.host", "crash.pocketmine.net") . "/submit/api", [
   "report" => "yes",
   "name" => $this->getName() . " " . $this->getPocketMineVersion(),
   "email" => "crash@pocketmine.net",
   "reportPaste" => base64_encode($dump->getEncodedData())
   ]);
  if($reply !== false and ($data = json_decode($reply)) !== null and isset($data->crashId) and isset($data->crashUrl)){
   $reportId = $data->crashId;
   $reportUrl = $data->crashUrl;
   $this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.archive", [$reportUrl, $reportId]));
  }
   }
    }
  $this->forceShutdown();
  $this->isRunning = false;
  $spacing = ((int) \pocketmine\START_TIME) - time() + 120;
  if($spacing > 0){
   echo "--- Waiting $spacing seconds to throttle automatic restart (you can kill the process safely now) ---" . PHP_EOL;
   sleep($spacing);
  }
  @Utils::kill(getmypid());
  exit(1);
 }
 public function __debugInfo() : array{
  return [];
 }
 public function getTickSleeper() : SleeperHandler{
  return $this->tickSleeper;
 }
 private function tickProcessor() : void{
  $this->nextTick = microtime(true);
  while($this->isRunning){
   $this->tick();
   $this->tickSleeper->sleepUntil($this->nextTick);
  }
 }
 public function onPlayerLogin(Player $player) : void{
  if($this->sendUsageTicker > 0){
   $this->uniquePlayers[$player->getRawUniqueId()] = $player->getRawUniqueId();
  }
  $this->sendFullPlayerListData($player);
  $player->dataPacket($this->craftingManager->getCraftingDataPacket());
 }
 public function addPlayer(Player $player) : void{
  $this->players[spl_object_hash($player)] = $player;
 }
 public function addOnlinePlayer(Player $player) : void{
  $this->updatePlayerListData($player->getUniqueId(), $player->getId(), $player->getDisplayName(), $player->getSkinId(), $player->getSkinData());
  $this->playerList[$player->getRawUniqueId()] = $player;
 }
 public function removeOnlinePlayer(Player $player) : void{
  if(isset($this->playerList[$player->getRawUniqueId()])){
   unset($this->playerList[$player->getRawUniqueId()]);
   $pk = new PlayerListPacket();
   $pk->type = PlayerListPacket::TYPE_REMOVE;
   $pk->entries[] = [$player->getUniqueId()];
   $this->broadcastPacket($this->playerList, $pk);
  }
 }
 public function updatePlayerListData(UUID $uuid, $entityId, $name, $skinId, $skinData, array $players = null) : void{
  $pk = new PlayerListPacket();
  $pk->type = PlayerListPacket::TYPE_ADD;
  $pk->entries[] = [$uuid, $entityId, $name, $skinId, $skinData];
  $this->broadcastPacket($players === null ? $this->playerList : $players, $pk);
 }
 public function removePlayerListData(UUID $uuid, array $players = null) : void{
  $pk = new PlayerListPacket();
  $pk->type = PlayerListPacket::TYPE_REMOVE;
  $pk->entries[] = [$uuid];
  $this->broadcastPacket($players === null ? $this->playerList : $players, $pk);
 }
 public function sendFullPlayerListData(Player $p) : void{
  $pk = new PlayerListPacket();
  $pk->type = PlayerListPacket::TYPE_ADD;
  foreach($this->playerList as $player){
   $pk->entries[] = [$player->getUniqueId(), $player->getId(), $player->getDisplayName(), $player->getSkinId(), $player->getSkinData()];
  }
  $p->dataPacket($pk);
 }
 private function checkTickUpdates($currentTick, $tickTime) : void{
   foreach($this->levels as $k => $level){
  if(!isset($this->levels[$k])){
   continue;
  }
  try{
   $levelTime = microtime(true);
   $level->doTick($currentTick);
   $tickMs = (microtime(true) - $levelTime) * 1000;
   $level->tickRateTime = $tickMs;
   if($tickMs >= 50){
   $this->getLogger()->debug(sprintf("World \"%s\" took too long to tick: %gms (%g ticks)", $level->getName(), $tickMs, round($tickMs / 50, 2)));
   }
  }
  catch(\Throwable $e){
   if(!$level->isClosed()){
    $this->logger->critical($this->getLanguage()->translateString("pocketmine.level.tickError", [$level->getName(), $e->getMessage()]));
   }
   else{
    $this->logger->critical($this->getLanguage()->translateString("pocketmine.level.tickUnloadError", [$level->getName()]));
   }
   if(\pocketmine\DEBUG > 1 and $this->logger instanceof MainLogger){
    $this->logger->logException($e);
   }
  }
   }
 }
 public function doAutoSave() : void{
    if($this->getAutoSave()){
  Timings::$worldSaveTimer->startTiming();
   foreach($this->players as $index => $player){
  if($player->spawned){
   $player->save();
  }
  elseif(!$player->isConnected()){
   $this->removePlayer($player);
  }
   }
  foreach($this->getLevels() as $level){
   $level->save(false);
  }
  Timings::$worldSaveTimer->stopTiming();
    }
 }
 public function sendUsage($type = SendUsageTask::TYPE_STATUS) : void{
  $this->scheduler->scheduleAsyncTask(new SendUsageTask($this, $type, $this->uniquePlayers));
  $this->uniquePlayers = [];
 }
 public function getLanguage(){
  return $this->baseLang;
 }
 public function isLanguageForced(){
  return $this->forceLanguage;
 }
 public function getNetwork() : Network{
  return $this->network;
 }
 public function getMemoryManager(){
  return $this->memoryManager;
 }
 public function handlePacket(AdvancedSourceInterface $interface, string $address, int $port, string $payload) : void{
  Timings::$serverRawPacketTimer->startTiming();
   try{
  if(strlen($payload) > 2 and substr($payload, 0, 2) === "\xfe\xfd" and $this->queryHandler instanceof QueryHandler){
   $this->queryHandler->handle($interface, $address, $port, $payload);
  }
  else{
   $this->logger->debug("Unhandled raw packet from $address $port: " . base64_encode($payload));
  }
   }
   catch(\Throwable $e){
  if($this->logger instanceof MainLogger){
   $this->logger->logException($e);
  }
  $this->getNetwork()->blockAddress($address, 600);
   }
  Timings::$serverRawPacketTimer->stopTiming();
 }
 public function getAdvancedProperty($variable, $defaultValue = null, Config $cfg = null){
  $vars = explode(".", $variable);
  $base = array_shift($vars);
  if($cfg == null) $cfg = $this->advancedConfig;
  if($cfg->exists($base)){
   $base = $cfg->get($base);
  }
  else{
   return $defaultValue;
  }
   while(count($vars) > 0){
   $baseKey = array_shift($vars);
  if(is_array($base) and isset($base[$baseKey])){
   $base = $base[$baseKey];
  }
  else{
   return $defaultValue;
  }
   }
  return $base;
 }
 public function updateQuery() : void{
  $this->getPluginManager()->callEvent($this->queryRegenerateTask = new QueryRegenerateEvent($this));
 }
 private function tick() : void{
  $tickTime = microtime(true);
  if(($tickTime - $this->nextTick) < -0.025){ //Allow half a tick of diff
   return;
  }
  Timings::$serverTickTimer->startTiming();
  ++$this->tickCounter;
  Timings::$connectionTimer->startTiming();
  $this->network->processInterfaces();
  Timings::$connectionTimer->stopTiming();
  Timings::$schedulerTimer->startTiming();
  $this->scheduler->mainThreadHeartbeat($this->tickCounter);
  Timings::$schedulerTimer->stopTiming();
  $this->checkTickUpdates($this->tickCounter, $tickTime);
  foreach($this->players as $player){
   $player->checkNetwork();
  }
   if(($this->tickCounter % 20) === 0){
  $this->currentTPS = 20;
  $this->currentUse = 0;
  if(($this->dserverConfig['enable'] and $this->dserverConfig['queryTickUpdate']) or !$this->dserverConfig['enable']){
   $this->updateQuery();
  }
  $this->network->updateName();
  $this->network->resetStatistics();
  if($this->dserverConfig['enable'] and $this->dserverConfig['motdPlayers']){
   $this->network->setName($this->network->getName().'['.$this->getDServerOnlinePlayers().'/'.$this->getDServerMaxPlayers().']');
  }
   }
  if($this->autoSave and ++$this->autoSaveTicker >= $this->autoSaveTicks){
   $this->autoSaveTicker = 0;
   $this->getLogger()->debug("[Auto Save] Saving worlds...");
   $start = microtime(true);
   $this->doAutoSave();
   $time = (microtime(true) - $start);
   $this->getLogger()->debug("[Auto Save] Save completed in " . ($time >= 1 ? round($time, 3) . "s" : round($time * 1000) . "ms"));
  }
  if(($this->tickCounter % 100) === 0){
   foreach($this->levels as $level){
   $level->clearCache();
   }
  }
  if($this->dispatchSignals and $this->tickCounter % 5 === 0){
   pcntl_signal_dispatch();
  }
  $this->getMemoryManager()->check();
  Timings::$serverTickTimer->stopTiming();
  $now = microtime(true);
  $this->currentTPS = min(20, 1 / max(0.001, $now - $tickTime));
  $this->currentUse = min(1, ($now - $tickTime) / 0.05);
  $idx = $this->tickCounter % 20;
  $this->tickAverage[$idx] = $this->currentTPS;
  $this->useAverage[$idx] = $this->currentUse;
  if(($this->nextTick - $tickTime) < -1){
   $this->nextTick = $tickTime;
  }
  else{
   $this->nextTick += 0.05;
  }
 }
//support pmmp method
 public function getPlayerByPrefix($name) : ?Player{
  return $this->getPlayer($name);
 }
 public function getWorldManager() : Server{
  return $this;
 }
 public function getWorldByName(string $name) : ?Level{
  return $this->getLevelByName($name);
 }
 public function getDefaultWorld() : Level{
  return $this->getDefaultLevel();
 }
 public function getWorlds() : array{
  return $this->getLevels();
 }
}