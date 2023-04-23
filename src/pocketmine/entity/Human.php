<?php

namespace pocketmine\entity;

use pocketmine\event\entity\{EntityConsumeTotemEvent, EntityDamageEvent, EntityRegainHealthEvent};
use pocketmine\event\player\{PlayerExhaustEvent, PlayerExperienceChangeEvent};
use pocketmine\inventory\{FloatingInventory, EnderChestInventory, InventoryHolder, OffhandInventory, PlayerInventory, SimpleTransactionQueue};
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\{Item as ItemItem, ItemIds, Totem};
use pocketmine\math\Math;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\{ByteArrayTag, ByteTag, CompoundTag, FloatTag, IntTag, ListTag, StringTag};
use pocketmine\network\mcpe\protocol\{AddPlayerPacket, EntityEventPacket, LevelEventPacket};
use pocketmine\Player;
use pocketmine\utils\UUID;
use ReflectionClass, InvalidArgumentException, InvalidStateException;

class Human extends Creature implements ProjectileSource, InventoryHolder {

const DATA_PLAYER_FLAG_SLEEP = 1;
const DATA_PLAYER_FLAG_DEAD = 2; //TODO: CHECK

const DATA_PLAYER_FLAGS = 27;

const DATA_PLAYER_BED_POSITION = 29;

/** @var PlayerInventory */
protected $inventory;

/** @var EnderChestInventory */
protected $enderChestInventory;

/** @var FloatingInventory */
protected $floatingInventory;

/** @var OffhandInventory */
protected $offhandInventory;

/** @var SimpleTransactionQueue */
protected $transactionQueue = null;

/** @var UUID */
protected $uuid;
protected $rawUUID;

public $width = 0.6;
public $length = 0.6;
public $height = 1.8;
public $eyeHeight = 1.62;

protected $skinId;
protected $skin;

protected $foodTickTimer = 0;

protected $totalXp = 0;
protected $xpSeed;
protected $xpCooldown = 0;

protected $baseOffset = 1.62;

public function getSkinData() : mixed{
return $this->skin;
}
public function getSkinId() : mixed{
return $this->skinId;
}
public function getUniqueId(){
return $this->uuid;
}
public function getRawUniqueId() : string{
return $this->rawUUID;
}
public function setSkin(string $str, string $skinId) : void{
$this->skin = $str;
$this->skinId = $skinId;
}
public function jump() : void{
parent::jump();
if($this->isSprinting()){
$this->exhaust(0.8, PlayerExhaustEvent::CAUSE_SPRINT_JUMPING);
}else{
$this->exhaust(0.2, PlayerExhaustEvent::CAUSE_JUMPING);
}
}
public function getFood() : float{
return $this->attributeMap->getAttribute(Attribute::HUNGER)->getValue();
}
public function setFood(float $new) : void{
$attr = $this->attributeMap->getAttribute(Attribute::HUNGER);
$old = $attr->getValue();
$attr->setValue($new);
// ranges: 18-20 (regen), 7-17 (none), 1-6 (no sprint), 0 (health depletion)
foreach([17, 6, 0] as $bound){
if(($old > $bound) !== ($new > $bound)){
$reset = true;
}
}
if(isset($reset)){
$this->foodTickTimer = 0;
}

}
public function getMaxFood() : float{
return $this->attributeMap->getAttribute(Attribute::HUNGER)->getMaxValue();
}
public function addFood(float $amount) : void{
$attr = $this->attributeMap->getAttribute(Attribute::HUNGER);
$amount += $attr->getValue();
$amount = max(min($amount, $attr->getMaxValue()), $attr->getMinValue());
$this->setFood($amount);
}
public function isHungry() : bool{
return $this->getFood() < $this->getMaxFood();
}
public function getSaturation() : float{
return $this->attributeMap->getAttribute(Attribute::SATURATION)->getValue();
}
public function setSaturation(float $saturation) : void{
$this->attributeMap->getAttribute(Attribute::SATURATION)->setValue($saturation);
}
public function addSaturation(float $amount) : void{
$attr = $this->attributeMap->getAttribute(Attribute::SATURATION);
$attr->setValue($attr->getValue() + $amount, true);
}
public function getExhaustion() : float{
return $this->attributeMap->getAttribute(Attribute::EXHAUSTION)->getValue();
}
public function setExhaustion(float $exhaustion) : void{
$this->attributeMap->getAttribute(Attribute::EXHAUSTION)->setValue($exhaustion);
}
public function exhaust(float $amount, int $cause = PlayerExhaustEvent::CAUSE_CUSTOM) : float{
$this->server->getPluginManager()->callEvent($ev = new PlayerExhaustEvent($this, $amount, $cause));
if($ev->isCancelled()){
return 0.0;
}
$exhaustion = $this->getExhaustion();
$exhaustion += $ev->getAmount();
while($exhaustion >= 4.0){
$exhaustion -= 4.0;
$saturation = $this->getSaturation();
if($saturation > 0){
$saturation = max(0, $saturation - 1.0);
$this->setSaturation($saturation);
}else{
$food = $this->getFood();
if($food > 0){
//$food--;
$food -= 0.5;            
$this->setFood($food);
}
}
}
$this->setExhaustion($exhaustion);
return $ev->getAmount();
}
public function getXpLevel() : int{
return (int) $this->attributeMap->getAttribute(Attribute::EXPERIENCE_LEVEL)->getValue();
}
public function setXpLevel(int $level) : bool{
$this->server->getPluginManager()->callEvent($ev = new PlayerExperienceChangeEvent($this, $level, $this->getXpProgress()));
if(!$ev->isCancelled()){
$this->attributeMap->getAttribute(Attribute::EXPERIENCE_LEVEL)->setValue($ev->getExpLevel());
return true;
}
return false;
}
public function addXpLevel(int $level) : bool{
return $this->setXpLevel($this->getXpLevel() + $level);
}
public function takeXpLevel(int $level) : bool{
return $this->setXpLevel($this->getXpLevel() - $level);
}
public function getXpProgress() : float{
return $this->attributeMap->getAttribute(Attribute::EXPERIENCE)->getValue();
}
public function setXpProgress(float $progress) : bool{
$this->attributeMap->getAttribute(Attribute::EXPERIENCE)->setValue($progress);
return true;
}
public function getTotalXp() : int{
return $this->totalXp;
}
public function setTotalXp(int $xp, bool $syncLevel = false) : bool{
$xp &= 0x7fffffff;
if($xp === $this->totalXp){
return false;
}
if(!$syncLevel){
$level = $this->getXpLevel();
$diff = $xp - $this->totalXp + $this->getFilledXp();
if($diff > 0){ //adding xp
while($diff > ($v = self::getLevelXpRequirement($level))){
$diff -= $v;
if(++$level >= 21863){
$diff = $v; //fill exp bar
break;
}
}
}else{ //taking xp
while($diff < ($v = self::getLevelXpRequirement($level - 1))){
$diff += $v;
if(--$level <= 0){
$diff = 0;
break;
}
}
}
$progress = ($diff / $v);
}else{
$values = self::getLevelFromXp($xp);
$level = $values[0];
$progress = $values[1];
}

$this->server->getPluginManager()->callEvent($ev = new PlayerExperienceChangeEvent($this, $level, $progress));
if(!$ev->isCancelled()){
$this->totalXp = $xp;
$this->setXpLevel($ev->getExpLevel());
$this->setXpProgress($ev->getProgress());
return true;
}
return false;
}
public function addXp(int $xp, bool $syncLevel = false) : bool{
return $this->setTotalXp($this->totalXp + $xp, $syncLevel);
}
public function takeXp(int $xp, bool $syncLevel = false) : bool{
return $this->setTotalXp($this->totalXp - $xp, $syncLevel);
}
public function getRemainderXp() : int{
return self::getLevelXpRequirement($this->getXpLevel()) - $this->getFilledXp();
}
public function getFilledXp() : int{
return self::getLevelXpRequirement($this->getXpLevel()) * $this->getXpProgress();
}
public function recalculateXpProgress() : float{
$this->setXpProgress($progress = $this->getRemainderXp() / self::getLevelXpRequirement($this->getXpLevel()));
return $progress;
}
public function getXpSeed() : int{
return $this->xpSeed;
}
public function resetXpCooldown(){
$this->xpCooldown = microtime(true);
}
public function canPickupXp() : bool{
return microtime(true) - $this->xpCooldown > 0.5;
}
public static function getTotalXpRequirement(int $level) : int{
if($level <= 16){
return ($level ** 2) + (6 * $level);
}elseif($level <= 31){
return (2.5 * ($level ** 2)) - (40.5 * $level) + 360;
}elseif($level <= 21863){
return (4.5 * ($level ** 2)) - (162.5 * $level) + 2220;
}
return PHP_INT_MAX; //prevent float returns for invalid levels on 32-bit systems
}
public static function getLevelXpRequirement(int $level) : int{
if($level <= 16){
return (2 * $level) + 7;
}elseif($level <= 31){
return (5 * $level) - 38;
}elseif($level <= 21863){
return (9 * $level) - 158;
}
return PHP_INT_MAX;
}
public static function getLevelFromXp(int $xp) : array{
$xp &= 0x7fffffff;
$a = 1;
$b = 6;
$c = -$xp;
if($xp > self::getTotalXpRequirement(16)){
if($xp <= self::getTotalXpRequirement(31)){
$a = 2.5;
$b = -40.5;
$c += 360;
}else{
$a = 4.5;
$b = -162.5;
$c += 2220;
}
}
$answer = max(Math::solveQuadratic($a, $b, $c)); //Use largest result value
$level = floor($answer);
$progress = $answer - $level;
return [$level, $progress];
}
public function getInventory(){
return $this->inventory;
}
public function getEnderChestInventory(){
return $this->enderChestInventory;
}
public function getFloatingInventory(){
return $this->floatingInventory;
}
public function getOffhandInventory() : OffhandInventory{
return $this->offhandInventory;
}
public function getTransactionQueue(){
if($this->transactionQueue === null){
$this->transactionQueue = new SimpleTransactionQueue($this);
}
return $this->transactionQueue;
}
protected function initEntity() : void{
$this->setDataFlag(self::DATA_PLAYER_FLAGS, self::DATA_PLAYER_FLAG_SLEEP, false, self::DATA_TYPE_BYTE);
$this->setDataProperty(self::DATA_PLAYER_BED_POSITION, self::DATA_TYPE_POS, [0, 0, 0], false);
$inventoryContents = ($this->namedtag->Inventory ?? null);
$this->inventory = new PlayerInventory($this, $inventoryContents);
$this->enderChestInventory = new EnderChestInventory($this, ($this->namedtag->EnderChestInventory ?? null));
$this->offhandInventory = new OffhandInventory($this);
$this->floatingInventory = new FloatingInventory($this);
if($this instanceof Player){
$this->addWindow($this->inventory, 0);
}else{
if(isset($this->namedtag->NameTag)){
$this->setNameTag($this->namedtag["NameTag"]);
}
if(isset($this->namedtag->Skin) and $this->namedtag->Skin instanceof CompoundTag){
$this->setSkin($this->namedtag->Skin["Data"], $this->namedtag->Skin["Name"]);
}
$this->uuid = UUID::fromData((string) $this->getId(), $this->getSkinData(), $this->getNameTag());
}
if(isset($this->namedtag->OffHandItem) && $this->namedtag->OffHandItem instanceof CompoundTag){
if($this->offhandInventory === null)
$this->offhandInventory = new OffhandInventory($this);

$this->offhandInventory->setItemInOffhand(ItemItem::nbtDeserialize($this->namedtag->OffHandItem));
}
parent::initEntity();
if(!isset($this->namedtag->foodLevel) or !($this->namedtag->foodLevel instanceof IntTag)){
$this->namedtag->foodLevel = new IntTag("foodLevel", $this->getFood());
}else{
$this->setFood($this->namedtag["foodLevel"]);
}
if(!isset($this->namedtag->foodExhaustionLevel) or !($this->namedtag->foodExhaustionLevel instanceof IntTag)){
$this->namedtag->foodExhaustionLevel = new FloatTag("foodExhaustionLevel", $this->getExhaustion());
}else{
$this->setExhaustion($this->namedtag["foodExhaustionLevel"]);
}
if(!isset($this->namedtag->foodSaturationLevel) or !($this->namedtag->foodSaturationLevel instanceof IntTag)){
$this->namedtag->foodSaturationLevel = new FloatTag("foodSaturationLevel", $this->getSaturation());
}else{
$this->setSaturation($this->namedtag["foodSaturationLevel"]);
}
if(!isset($this->namedtag->foodTickTimer) or !($this->namedtag->foodTickTimer instanceof IntTag)){
$this->namedtag->foodTickTimer = new IntTag("foodTickTimer", $this->foodTickTimer);
}else{
$this->foodTickTimer = $this->namedtag["foodTickTimer"];
}
if(!isset($this->namedtag->XpLevel) or !($this->namedtag->XpLevel instanceof IntTag)){
$this->namedtag->XpLevel = new IntTag("XpLevel", 0);
}
$this->setXpLevel($this->namedtag["XpLevel"]);
if(!isset($this->namedtag->XpP) or !($this->namedtag->XpP instanceof FloatTag)){
$this->namedtag->XpP = new FloatTag("XpP", 0);
}
$this->setXpProgress($this->namedtag["XpP"]);
if(!isset($this->namedtag->XpTotal) or !($this->namedtag->XpTotal instanceof IntTag)){
$this->namedtag->XpTotal = new IntTag("XpTotal", 0);
}
$this->totalXp = $this->namedtag["XpTotal"];
if(!isset($this->namedtag->XpSeed) or !($this->namedtag->XpSeed instanceof IntTag)){
$this->namedtag->XpSeed = new IntTag("XpSeed", mt_rand(PHP_INT_MIN, PHP_INT_MAX));
}
$this->xpSeed = $this->namedtag["XpSeed"];
}
public function getAbsorption() : int{
return $this->attributeMap->getAttribute(Attribute::ABSORPTION)->getValue();
}
public function setAbsorption(int $absorption) : void{
$this->attributeMap->getAttribute(Attribute::ABSORPTION)->setValue($absorption);
}
protected function addAttributes() : void{
parent::addAttributes();
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::SATURATION));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::EXHAUSTION));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::HUNGER));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::EXPERIENCE_LEVEL));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::EXPERIENCE));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::HEALTH));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::MOVEMENT_SPEED));
$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::ABSORPTION));
}
public function entityBaseTick($tickDiff = 1, $EnchantL = 0){
if($this->getInventory() instanceof PlayerInventory){
$EnchantL = $this->getInventory()->getHelmet()->getEnchantmentLevel(Enchantment::TYPE_WATER_BREATHING);
}
$hasUpdate = parent::entityBaseTick($tickDiff, $EnchantL);
$this->doFoodTick($tickDiff);
return $hasUpdate;
}
public function attack($damage, EntityDamageEvent $source){
if($source->getCause() !== EntityDamageEvent::CAUSE_VOID){
$damage = round($source->getFinalDamage());
if($this->getAbsorption() > 0){
$damage -= $this->getAbsorption();
}
if($damage > 0 && ($this->getHealth() - $damage) <= 0){
$isOffHand = $this->getOffhandInventory()->getItemInOffhand() instanceof Totem;
if($isOffHand
|| $this->getInventory()->getItemInHand() instanceof Totem){
$event = new EntityConsumeTotemEvent($this);
$this->server->getPluginManager()->callEvent($event);
if(!$event->isCancelled()){
$pk = new EntityEventPacket();
$pk->eid = $this->id;
$pk->event = EntityEventPacket::CONSUME_TOTEM;
$viewers = $this->getViewers();
if($this instanceof Player){
$viewers = array_merge($viewers, [$this]);
}
$this->server->batchPackets($viewers, [$pk]);
$this->level->broadcastLevelEvent($this, LevelEventPacket::EVENT_SOUND_TOTEM);
$this->setHealth($this->getHealth() + 5);
$this->addEffect(Effect::getEffect(Effect::REGENERATION)->setDuration(20 * 45)->setAmplifier(1));
$this->addEffect(Effect::getEffect(Effect::ABSORPTION)->setDuration(20 * 5)->setAmplifier(1));
$this->addEffect(Effect::getEffect(Effect::FIRE_RESISTANCE)->setDuration(20 * 40));
if($isOffHand){
$this->getOffhandInventory()->setItemInOffhand(ItemItem::get(ItemIds::AIR));
}else{
$this->getInventory()->setItemInHand(ItemItem::get(ItemIds::AIR));
}
return;
}
}
}
}
parent::attack($damage, $source);
}
public function doFoodTick(int $tickDiff = 1) : void{
if($this->isAlive()){
$food = $this->getFood();
$health = $this->getHealth();
$difficulty = $this->server->getDifficulty();
$this->foodTickTimer += $tickDiff;
if($this->foodTickTimer >= 80){
$this->foodTickTimer = 0;
}
if($difficulty === 0 and $this->foodTickTimer % 10 === 0){ //Peaceful
if($food < $this->getMaxFood()){
$this->addFood(1.0);
$food = $this->getFood();
}
if($this->foodTickTimer % 20 === 0 and $health < $this->getMaxHealth()){
$this->heal(1, new EntityRegainHealthEvent($this, 1, EntityRegainHealthEvent::CAUSE_SATURATION));
}
}
if($this->foodTickTimer === 0){
if($food >= 18){
if($health < $this->getMaxHealth()){
$this->heal(1, new EntityRegainHealthEvent($this, 1, EntityRegainHealthEvent::CAUSE_SATURATION));
$this->exhaust(3.0, PlayerExhaustEvent::CAUSE_HEALTH_REGEN);
}
}elseif($food <= 0){
if(($difficulty === 1 and $health > 10) or ($difficulty === 2 and $health > 1) or $difficulty === 3){
$this->attack(1, new EntityDamageEvent($this, EntityDamageEvent::CAUSE_STARVATION, 1));
}
}
}
if($food <= 6){
$this->setSprinting(false);
}
}
}
public function getName() : string{
return $this->getNameTag();
}
public function getDrops(){
$drops = [];
if($this->inventory !== null){
foreach($this->inventory->getContents() as $item){
$drops[] = $item;
}
}
return $drops;
}
public function saveNBT(){
parent::saveNBT();
if($this->offhandInventory !== null){
$this->namedtag->OffHandItem = $this->getOffhandInventory()->getItemInOffhand()->nbtSerialize(0, "OffHandItem");
}
$this->namedtag->Inventory = new ListTag("Inventory", []);
$this->namedtag->Inventory->setTagType(NBT::TAG_Compound);
if($this->inventory !== null){
for($slot = 0; $slot < $this->inventory->getHotbarSize(); ++$slot){
$inventorySlotIndex = $this->inventory->getHotbarSlotIndex($slot);
$item = $this->inventory->getItem($inventorySlotIndex);
$tag = $item->nbtSerialize($slot);
$tag->TrueSlot = new ByteTag("TrueSlot", $inventorySlotIndex);
$this->namedtag->Inventory[$slot] = $tag;
}
$slotCount = $this->inventory->getSize() + $this->inventory->getHotbarSize();
for($slot = $this->inventory->getHotbarSize(); $slot < $slotCount; ++$slot){
$item = $this->inventory->getItem($slot - $this->inventory->getHotbarSize());
if($item->getId() !== ItemItem::AIR){
$this->namedtag->Inventory[$slot] = $item->nbtSerialize($slot);
}
}
for($slot = 100; $slot < 104; ++$slot){
$item = $this->inventory->getItem($this->inventory->getSize() + $slot - 100);
if($item instanceof ItemItem and $item->getId() !== ItemItem::AIR){
$this->namedtag->Inventory[$slot] = $item->nbtSerialize($slot);
}
}
}
$this->namedtag->EnderChestInventory = new ListTag("EnderChestInventory", []);
$this->namedtag->Inventory->setTagType(NBT::TAG_Compound);
if($this->enderChestInventory !== null){
for($slot = 0; $slot < $this->enderChestInventory->getSize(); $slot++){
if(($item = $this->enderChestInventory->getItem($slot)) instanceof ItemItem){
$this->namedtag->EnderChestInventory[$slot] = $item->nbtSerialize($slot);
}
}
}
if(strlen($this->getSkinData()) > 0){
$this->namedtag->Skin = new CompoundTag("Skin", [
"Data" => new ByteArrayTag("Data", $this->getSkinData()),
"Name" => new StringTag("Name", $this->getSkinId())
]);
}
$this->namedtag->XpLevel = new IntTag("XpLevel", $this->getXpLevel());
$this->namedtag->XpTotal = new IntTag("XpTotal", $this->getTotalXp());
$this->namedtag->XpP = new FloatTag("XpP", $this->getXpProgress());
$this->namedtag->XpSeed = new IntTag("XpSeed", $this->getXpSeed());
$this->namedtag->foodLevel = new IntTag("foodLevel", $this->getFood());
$this->namedtag->foodExhaustionLevel = new FloatTag("foodExhaustionLevel", $this->getExhaustion());
$this->namedtag->foodSaturationLevel = new FloatTag("foodSaturationLevel", $this->getSaturation());
$this->namedtag->foodTickTimer = new IntTag("foodTickTimer", $this->foodTickTimer);
}
public function spawnTo(Player $player){
if(strlen($this->skin) < 64 * 32 * 4){
$e = new InvalidStateException((new ReflectionClass($this))->getShortName() . " must have a valid skin set");
$this->server->getLogger()->logException($e);
$this->close();
}elseif($player !== $this and !isset($this->hasSpawned[$player->getLoaderId()])){
$this->hasSpawned[$player->getLoaderId()] = $player;
if(!($this instanceof Player)){
$this->server->updatePlayerListData($this->getUniqueId(), $this->getId(), $this->getName(), $this->skinId, $this->skin, [$player]);
}
$pk = new AddPlayerPacket();
$pk->uuid = $this->getUniqueId();
$pk->username = $this->getName();
$pk->eid = $this->getId();
$pk->x = $this->x;
$pk->y = $this->y;
$pk->z = $this->z;
$pk->speedX = $this->motionX;
$pk->speedY = $this->motionY;
$pk->speedZ = $this->motionZ;
$pk->yaw = $this->yaw;
$pk->pitch = $this->pitch;
$pk->item = $this->getInventory()->getItemInHand();
$pk->metadata = $this->dataProperties;
$player->dataPacket($pk);
$this->sendLinkedData();
$this->inventory->sendArmorContents($player);
$this->offhandInventory->sendContents($player);
if(!($this instanceof Player)){
$this->server->removePlayerListData($this->getUniqueId(), [$player]);
}
}
}
public function close(){
if(!$this->closed){
if($this->getFloatingInventory() instanceof FloatingInventory){
foreach($this->getFloatingInventory()->getContents() as $craftingItem){
$this->level->dropItem($this, $craftingItem);
}
}else{
$this->server->getLogger()->debug("Attempted to drop a null crafting inventory\n");
}
if($this->inventory !== null){
$this->inventory->removeAllViewers();
$this->inventory = null;
}
if($this->enderChestInventory !== null){
$this->enderChestInventory->removeAllViewers();
$this->enderChestInventory = null;
}
parent::close();
}
}
}
