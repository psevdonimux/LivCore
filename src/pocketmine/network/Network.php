<?php declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\network\mcpe\protocol\{AddEntityPacket, AddHangingEntityPacket, AddItemEntityPacket, AddItemPacket, AddPaintingPacket, AddPlayerPacket, AdventureSettingsPacket, AnimatePacket, AvailableCommandsPacket, BatchPacket, BlockEntityDataPacket, BlockEventPacket, BossEventPacket, BlockPickRequestPacket, ChangeDimensionPacket, ChunkRadiusUpdatedPacket, ClientboundMapItemDataPacket, ClientToServerHandshakePacket, CommandBlockUpdatePacket, CommandStepPacket, ContainerClosePacket, ContainerOpenPacket, ContainerSetContentPacket, ContainerSetDataPacket, ContainerSetSlotPacket, CraftingDataPacket, CraftingEventPacket, CameraPacket, DataPacket, DisconnectPacket, DropItemPacket, EntityEventPacket, ExplodePacket, FullChunkDataPacket, HurtArmorPacket, ProtocolInfo, InteractPacket, InventoryActionPacket, ItemFrameDropItemPacket, LevelEventPacket, LevelSoundEventPacket, LoginPacket, MapInfoRequestPacket, MobArmorEquipmentPacket, MobEquipmentPacket, MobEffectPacket, MoveEntityPacket, MovePlayerPacket, PlaySoundPacket, PlayStatusPacket, PlayerActionPacket, EntityFallPacket, PlayerInputPacket, PlayerListPacket, RemoveBlockPacket, RemoveEntityPacket, ReplaceItemInSlotPacket, RequestChunkRadiusPacket, ResourcePackChunkDataPacket, ResourcePackChunkRequestPacket, ResourcePackClientResponsePacket, ResourcePackDataInfoPacket, ResourcePackStackPacket, ResourcePacksInfoPacket, RespawnPacket, RiderJumpPacket, SetCommandsEnabledPacket, SetDifficultyPacket, SetEntityDataPacket, SetEntityLinkPacket, SetEntityMotionPacket, SetHealthPacket, SetPlayerGameTypePacket, SetSpawnPositionPacket, SetTimePacket, SetTitlePacket, ServerToClientHandshakePacket, ShowCreditsPacket, SpawnExperienceOrbPacket, StartGamePacket, StopSoundPacket, TakeItemEntityPacket, TextPacket, TransferPacket, UpdateAttributesPacket, UpdateBlockPacket, UpdateTradePacket, UseItemPacket};
use pocketmine\{Player, Server};
use pocketmine\utils\{BinaryStream, MainLogger};
use SplFixedArray, Throwable, UnexpectedValueException, InvalidArgumentException, InvalidStateException;
use const pocketmine\DEBUG;
use function spl_object_hash;

class Network{

 public static $BATCH_THRESHOLD = 512;
 private SplFixedArray $packetPool;
 private Server $server;
 private array $interfaces = [], $advancedInterfaces = [];
 private int $upload = 0, $download = 0;
 private string $name;

 public function __construct(Server $server){
  $this->registerPackets();
  $this->server = $server;
 }
 public function addStatistics(int $upload, int $download) : void{
  $this->upload += $upload;
  $this->download += $download;
 }
 public function getUpload() : int{
  return $this->upload;
 }
 public function getDownload() : int{
  return $this->download;
 }
 public function resetStatistics() : void{
  $this->upload = 0;
  $this->download = 0;
 }
 public function getInterfaces() : array{
  return $this->interfaces;
 }
 public function processInterfaces() : void{
  foreach($this->interfaces as $interface){
   $interface->process();
  }
 }
 public function processInterface(SourceInterface $interface) : void{
  $interface->process();
 }
 public function registerInterface(SourceInterface $interface) : void{
  $interface->start();
  $this->interfaces[$hash = spl_object_hash($interface)] = $interface;
  if($interface instanceof AdvancedSourceInterface){
   $this->advancedInterfaces[$hash] = $interface;
   $interface->setNetwork($this);
  }
  $interface->setName($this->name);
 }
 public function unregisterInterface(SourceInterface $interface) : void{
  unset($this->interfaces[$hash = spl_object_hash($interface)],
  $this->advancedInterfaces[$hash]);
 }
 public function setName(string $name) : void{
  $this->name = $name;
  foreach($this->interfaces as $interface){
   $interface->setName($this->name);
  }
 }
 public function getName() : string{
  return $this->name;
 }
 public function updateName() : void{
  foreach($this->interfaces as $interface){
   $interface->setName($this->name);
  }
 }
 public function registerPacket(int $id, string $class) : void{
  $this->packetPool[$id] = new $class;
 }
 public function getServer() : Server{
  return $this->server;
 }
 public function processBatch(BatchPacket $packet, Player $player) : void{
  try{
   if(strlen($packet->payload) === 0){
    throw new InvalidArgumentException("BatchPacket payload is empty or packet decode error");
   }
   $str = zlib_decode($packet->payload, 1024 * 1024 * 2); //Max 2MB
   $len = strlen($str);
   if($len === 0){
    throw new InvalidStateException("Decoded BatchPacket payload is empty");
   }
   $stream = new BinaryStream($str);
   $count = 0;
    while(!$stream->feof()){
   if($count++ >= 500){
    throw new UnexpectedValueException("Too many packets in a single batch");
   }
   $buf = $stream->getString();
    if(($pk = $this->getPacket(ord($buf[0]))) !== null){
   if(!$pk->canBeBatched()){
    throw new UnexpectedValueException("Received invalid " . get_class($pk) . " inside BatchPacket");
   }
   $pk->setBuffer($buf, 1);
   $pk->decode();
   if(!$pk->feof() and !$pk->mayHaveUnreadBytes()){
    $remains = substr($pk->buffer, $pk->offset);
    $this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $pk->getName() . ": 0x" . bin2hex($remains));
   }
   $player->handleDataPacket($pk);
    }
    }
    }
    catch(Throwable $e){
   if(DEBUG > 1){
    $logger = $this->server->getLogger();
   if($logger instanceof MainLogger){
    $logger->debug("BatchPacket " . " 0x" . bin2hex($packet->payload));
	$logger->logException($e);
   }
   }
    }
 }
 public function getPacket(int $id) : ?DataPacket{
  $class = $this->packetPool[$id];
  if($class !== null){
   return clone $class;
  }
  return null;
 }
 public function sendPacket(string $address, int $port, string $payload) : void{
  foreach($this->advancedInterfaces as $interface){
   $interface->sendRawPacket($address, $port, $payload);
  }
 }
 public function blockAddress(string $address, int $timeout = 300) : void{
  foreach($this->advancedInterfaces as $interface){
   $interface->blockAddress($address, $timeout);
  }
 }
 public function unblockAddress(string $address) : void{
  foreach($this->advancedInterfaces as $interface){
   $interface->unblockAddress($address);
  }
 }
 private function registerPackets() : void{
  $this->packetPool = new SplFixedArray(256);
  $this->registerPacket(ProtocolInfo::ADD_ENTITY_PACKET, AddEntityPacket::class);
  $this->registerPacket(ProtocolInfo::ADD_HANGING_ENTITY_PACKET, AddHangingEntityPacket::class);
  $this->registerPacket(ProtocolInfo::ADD_ITEM_ENTITY_PACKET, AddItemEntityPacket::class);
  $this->registerPacket(ProtocolInfo::ADD_ITEM_PACKET, AddItemPacket::class);
  $this->registerPacket(ProtocolInfo::ADD_PAINTING_PACKET, AddPaintingPacket::class);
  $this->registerPacket(ProtocolInfo::ADD_PLAYER_PACKET, AddPlayerPacket::class);
  $this->registerPacket(ProtocolInfo::ADVENTURE_SETTINGS_PACKET, AdventureSettingsPacket::class);
  $this->registerPacket(ProtocolInfo::ANIMATE_PACKET, AnimatePacket::class);
  $this->registerPacket(ProtocolInfo::AVAILABLE_COMMANDS_PACKET, AvailableCommandsPacket::class);
  $this->registerPacket(ProtocolInfo::BLOCK_ENTITY_DATA_PACKET, BlockEntityDataPacket::class);
  $this->registerPacket(ProtocolInfo::BLOCK_EVENT_PACKET, BlockEventPacket::class);
  $this->registerPacket(ProtocolInfo::BOSS_EVENT_PACKET, BossEventPacket::class);
  $this->registerPacket(ProtocolInfo::CAMERA_PACKET, CameraPacket::class);
  $this->registerPacket(ProtocolInfo::CHANGE_DIMENSION_PACKET, ChangeDimensionPacket::class);
  $this->registerPacket(ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET, ChunkRadiusUpdatedPacket::class);
  $this->registerPacket(ProtocolInfo::CLIENTBOUND_MAP_ITEM_DATA_PACKET, ClientboundMapItemDataPacket::class);
  $this->registerPacket(ProtocolInfo::CLIENT_TO_SERVER_HANDSHAKE_PACKET, ClientToServerHandshakePacket::class);
  $this->registerPacket(ProtocolInfo::COMMAND_STEP_PACKET, CommandStepPacket::class);
  $this->registerPacket(ProtocolInfo::CONTAINER_CLOSE_PACKET, ContainerClosePacket::class);
  $this->registerPacket(ProtocolInfo::CONTAINER_OPEN_PACKET, ContainerOpenPacket::class);
  $this->registerPacket(ProtocolInfo::CONTAINER_SET_CONTENT_PACKET, ContainerSetContentPacket::class);
  $this->registerPacket(ProtocolInfo::CONTAINER_SET_DATA_PACKET, ContainerSetDataPacket::class);
  $this->registerPacket(ProtocolInfo::CONTAINER_SET_SLOT_PACKET, ContainerSetSlotPacket::class);
  $this->registerPacket(ProtocolInfo::CRAFTING_DATA_PACKET, CraftingDataPacket::class);
  $this->registerPacket(ProtocolInfo::CRAFTING_EVENT_PACKET, CraftingEventPacket::class);
  $this->registerPacket(ProtocolInfo::DISCONNECT_PACKET, DisconnectPacket::class);
  $this->registerPacket(ProtocolInfo::DROP_ITEM_PACKET, DropItemPacket::class);
  $this->registerPacket(ProtocolInfo::ENTITY_EVENT_PACKET, EntityEventPacket::class);
  $this->registerPacket(ProtocolInfo::EXPLODE_PACKET, ExplodePacket::class);
  $this->registerPacket(ProtocolInfo::FULL_CHUNK_DATA_PACKET, FullChunkDataPacket::class);
  $this->registerPacket(ProtocolInfo::HURT_ARMOR_PACKET, HurtArmorPacket::class);
  $this->registerPacket(ProtocolInfo::INTERACT_PACKET, InteractPacket::class);
  $this->registerPacket(ProtocolInfo::INVENTORY_ACTION_PACKET, InventoryActionPacket::class);
  $this->registerPacket(ProtocolInfo::ITEM_FRAME_DROP_ITEM_PACKET, ItemFrameDropItemPacket::class);
  $this->registerPacket(ProtocolInfo::LEVEL_EVENT_PACKET, LevelEventPacket::class);
  $this->registerPacket(ProtocolInfo::LEVEL_SOUND_EVENT_PACKET, LevelSoundEventPacket::class);
  $this->registerPacket(ProtocolInfo::LOGIN_PACKET, LoginPacket::class);
  $this->registerPacket(ProtocolInfo::MAP_INFO_REQUEST_PACKET, MapInfoRequestPacket::class);
  $this->registerPacket(ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET, MobArmorEquipmentPacket::class);
  $this->registerPacket(ProtocolInfo::MOB_EFFECT_PACKET, MobEffectPacket::class);
  $this->registerPacket(ProtocolInfo::MOB_EQUIPMENT_PACKET, MobEquipmentPacket::class);
  $this->registerPacket(ProtocolInfo::MOVE_ENTITY_PACKET, MoveEntityPacket::class);
  $this->registerPacket(ProtocolInfo::MOVE_PLAYER_PACKET, MovePlayerPacket::class);
  $this->registerPacket(ProtocolInfo::ENTITY_FALL_PACKET, EntityFallPacket::class);
  $this->registerPacket(ProtocolInfo::PLAYER_ACTION_PACKET, PlayerActionPacket::class);
  $this->registerPacket(ProtocolInfo::PLAYER_INPUT_PACKET, PlayerInputPacket::class);
  $this->registerPacket(ProtocolInfo::PLAYER_LIST_PACKET, PlayerListPacket::class);
  $this->registerPacket(ProtocolInfo::PLAY_STATUS_PACKET, PlayStatusPacket::class);
  $this->registerPacket(ProtocolInfo::REMOVE_BLOCK_PACKET, RemoveBlockPacket::class);
  $this->registerPacket(ProtocolInfo::REMOVE_ENTITY_PACKET, RemoveEntityPacket::class);
  $this->registerPacket(ProtocolInfo::REPLACE_ITEM_IN_SLOT_PACKET, ReplaceItemInSlotPacket::class);
  $this->registerPacket(ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET, RequestChunkRadiusPacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACKS_INFO_PACKET, ResourcePacksInfoPacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACK_CHUNK_REQUEST_PACKET, ResourcePackChunkRequestPacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACK_CHUNK_DATA_PACKET, ResourcePackChunkDataPacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACK_CLIENT_RESPONSE_PACKET, ResourcePackClientResponsePacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACK_DATA_INFO_PACKET, ResourcePackDataInfoPacket::class);
  $this->registerPacket(ProtocolInfo::RESOURCE_PACK_STACK_PACKET, ResourcePackStackPacket::class);
  $this->registerPacket(ProtocolInfo::RESPAWN_PACKET, RespawnPacket::class);
  $this->registerPacket(ProtocolInfo::RIDER_JUMP_PACKET, RiderJumpPacket::class);
  $this->registerPacket(ProtocolInfo::SHOW_CREDITS_PACKET, ShowCreditsPacket::class);
  $this->registerPacket(ProtocolInfo::SERVER_TO_CLIENT_HANDSHAKE_PACKET, ServerToClientHandshakePacket::class);
  $this->registerPacket(ProtocolInfo::SET_COMMANDS_ENABLED_PACKET, SetCommandsEnabledPacket::class);
  $this->registerPacket(ProtocolInfo::SET_DIFFICULTY_PACKET, SetDifficultyPacket::class);
  $this->registerPacket(ProtocolInfo::SET_ENTITY_DATA_PACKET, SetEntityDataPacket::class);
  $this->registerPacket(ProtocolInfo::SET_ENTITY_LINK_PACKET, SetEntityLinkPacket::class);
  $this->registerPacket(ProtocolInfo::SET_ENTITY_MOTION_PACKET, SetEntityMotionPacket::class);
  $this->registerPacket(ProtocolInfo::SET_HEALTH_PACKET, SetHealthPacket::class);
  $this->registerPacket(ProtocolInfo::SET_PLAYER_GAME_TYPE_PACKET, SetPlayerGameTypePacket::class);
  $this->registerPacket(ProtocolInfo::SET_SPAWN_POSITION_PACKET, SetSpawnPositionPacket::class);
  $this->registerPacket(ProtocolInfo::SET_TIME_PACKET, SetTimePacket::class);
  $this->registerPacket(ProtocolInfo::SPAWN_EXPERIENCE_ORB_PACKET, SpawnExperienceOrbPacket::class);
  $this->registerPacket(ProtocolInfo::START_GAME_PACKET, StartGamePacket::class);
  $this->registerPacket(ProtocolInfo::TAKE_ITEM_ENTITY_PACKET, TakeItemEntityPacket::class);
  $this->registerPacket(ProtocolInfo::TEXT_PACKET, TextPacket::class);
  $this->registerPacket(ProtocolInfo::TRANSFER_PACKET, TransferPacket::class);
  $this->registerPacket(ProtocolInfo::UPDATE_ATTRIBUTES_PACKET, UpdateAttributesPacket::class);
  $this->registerPacket(ProtocolInfo::UPDATE_BLOCK_PACKET, UpdateBlockPacket::class);
  $this->registerPacket(ProtocolInfo::UPDATE_TRADE_PACKET, UpdateTradePacket::class);
  $this->registerPacket(ProtocolInfo::USE_ITEM_PACKET, UseItemPacket::class);
  $this->registerPacket(ProtocolInfo::BLOCK_PICK_REQUEST_PACKET, BlockPickRequestPacket::class);
  $this->registerPacket(ProtocolInfo::COMMAND_BLOCK_UPDATE_PACKET, CommandBlockUpdatePacket::class);
  $this->registerPacket(ProtocolInfo::PLAY_SOUND_PACKET, PlaySoundPacket::class);
  $this->registerPacket(ProtocolInfo::SET_TITLE_PACKET, SetTitlePacket::class);
  $this->registerPacket(ProtocolInfo::STOP_SOUND_PACKET, StopSoundPacket::class);
  $this->registerPacket(0xfe, BatchPacket::class);
 }
}