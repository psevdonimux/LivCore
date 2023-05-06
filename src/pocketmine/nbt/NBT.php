<?php

namespace pocketmine\nbt;

use pocketmine\nbt\tag\{ByteArrayTag, ByteTag, CompoundTag, DoubleTag, EndTag, FloatTag, IntArrayTag, IntTag, ListTag, LongTag, NamedTag, ShortTag, StringTag, Tag};
#ifndef COMPILE
use pocketmine\utils\{Binary, BinaryDataException};
#endif
#include <rules/NBT.h>

class NBT {

const LITTLE_ENDIAN = 0;
const BIG_ENDIAN = 1;
const TAG_End = 0;
const TAG_Byte = 1;
const TAG_Short = 2;
const TAG_Int = 3;
const TAG_Long = 4;
const TAG_Float = 5;
const TAG_Double = 6;
const TAG_ByteArray = 7;
const TAG_String = 8;
const TAG_List = 9;
const TAG_Compound = 10;
const TAG_IntArray = 11;

public mixed $buffer, $endianness;
public int $offset;
private CompoundTag|array $data;

public static function createTag(int $type) : Tag{
switch($type){
case self::TAG_End:
return new EndTag();
case self::TAG_Byte:
return new ByteTag();
case self::TAG_Short:
return new ShortTag();
case self::TAG_Int:
return new IntTag();
case self::TAG_Long:
return new LongTag();
case self::TAG_Float:
return new FloatTag();
case self::TAG_Double:
return new DoubleTag();
case self::TAG_ByteArray:
return new ByteArrayTag();
case self::TAG_String:
return new StringTag();
case self::TAG_List:
return new ListTag();
case self::TAG_Compound:
return new CompoundTag();
case self::TAG_IntArray:
return new IntArrayTag();
default:
throw new \InvalidArgumentException("Unknown NBT tag type $type");
}
}
public static function matchList(ListTag $tag1, ListTag $tag2) : bool{
if($tag1->getName() !== $tag2->getName() or $tag1->getCount() !== $tag2->getCount()){
return false;
}
foreach($tag1 as $k => $v){
if(!($v instanceof Tag)){
continue;
}
if(!isset($tag2->{$k}) or !($tag2->{$k} instanceof $v)){
return false;
}
if($v instanceof CompoundTag){
if(!self::matchTree($v, $tag2->{$k})){
return false;
}
}elseif($v instanceof ListTag){
if(!self::matchList($v, $tag2->{$k})){
return false;
}
}else{
if($v->getValue() !== $tag2->{$k}->getValue()){
return false;
}
}
}
return true;
}
public static function matchTree(CompoundTag $tag1, CompoundTag $tag2) : bool{
if($tag1->getName() !== $tag2->getName() or $tag1->getCount() !== $tag2->getCount()){
return false;
}
foreach($tag1 as $k => $v){
if(!($v instanceof Tag)){
continue;
}
if(!isset($tag2->{$k}) or !($tag2->{$k} instanceof $v)){
return false;
}
if($v instanceof CompoundTag){
if(!self::matchTree($v, $tag2->{$k})){
return false;
}
}elseif($v instanceof ListTag){
if(!self::matchList($v, $tag2->{$k})){
return false;
}
}else{
if($v->getValue() !== $tag2->{$k}->getValue()){
return false;
}
}
}
return true;
}
public static function combineCompoundTags(CompoundTag $tag1, CompoundTag $tag2, bool $override = false) : CompoundTag{
$tag1 = clone $tag1;
foreach($tag2 as $k => $v){
if(!($v instanceof Tag)){
continue;
}
if(!isset($tag1->{$k}) or (isset($tag1->{$k}) and $override)){
$tag1->{$k} = clone $v;
}
}
return $tag1;
}
public function get(int $len) : string{
if($len === 0){
return '';
}

$buflen = strlen($this->buffer);
if($len === true){
$str = substr($this->buffer, $this->offset);
$this->offset = $buflen;
return $str;
}
if($len < 0){
$this->offset = $buflen - 1;
return '';
}
$remaining = $buflen - $this->offset;
if($remaining < $len){
throw new BinaryDataException("Not enough bytes left in buffer: need $len, have $remaining");
}
return $len === 1 ? $this->buffer[$this->offset++] : substr($this->buffer, ($this->offset += $len) - $len, $len);
}
public function put(mixed $v) : void{
$this->buffer .= $v;
}
public function feof() : bool{
return !isset($this->buffer[$this->offset]);
}
public function __construct(int $endianness = self::LITTLE_ENDIAN){
$this->offset = 0;
$this->endianness = $endianness & 0x01;
}
public function read($buffer, bool $doMultiple = false, bool $network = false) : void{
$this->offset = 0;
$this->buffer = $buffer;
$this->data = $this->readTag($network);
if($doMultiple and !$this->feof()){
$this->data = [$this->data];
do{
$tag = $this->readTag($network);
if($tag !== null){
$this->data[] = $tag;
}
}while(!$this->feof());
}
$this->buffer = '';
}
public function readCompressed(mixed $buffer) : void{
$decompressed = zlib_decode($buffer);
if($decompressed === false){
throw new \UnexpectedValueException("Failed to decompress data");
}
$this->read($decompressed);
}
public function write(bool $network = false) : string|bool{
$this->offset = 0;
$this->buffer = '';
if($this->data instanceof CompoundTag){
$this->writeTag($this->data, $network);
return $this->buffer;
}elseif(is_array($this->data)){
foreach($this->data as $tag){
$this->writeTag($tag, $network);
}
return $this->buffer;
}
return false;
}
public function writeCompressed(int $compression = ZLIB_ENCODING_GZIP, int $level = 7) : string|bool{
if(($write = $this->write()) !== false){
return zlib_encode($write, $compression, $level);
}
return false;
}
public function readTag(bool $network = false) : Tag{
$tagType = $this->getByte();
$tag = self::createTag($tagType);
if($tag instanceof NamedTag){
$tag->setName($this->getString($network));
$tag->read($this, $network);
}
return $tag;
}
public function writeTag(Tag $tag, bool $network = false) : void{
$this->putByte($tag->getType());
if($tag instanceof NamedTag){
$this->putString($tag->getName(), $network);
}
$tag->write($this, $network);
}
public function getByte() : int{
return Binary::readByte($this->get(1));
}
public function getSignedByte() : int{
return Binary::readSignedByte($this->get(1));
}
public function putByte(mixed $v) : void{
$this->buffer .= Binary::writeByte($v);
}
public function getShort() : int{
return $this->endianness === self::BIG_ENDIAN ? Binary::readShort($this->get(2)) : Binary::readLShort($this->get(2));
}
public function getSignedShort() : int{
return $this->endianness === self::BIG_ENDIAN ? Binary::readSignedShort($this->get(2)) : Binary::readSignedLShort($this->get(2));
}
public function putShort(mixed $v) : void{
$this->buffer .= $this->endianness === self::BIG_ENDIAN ? Binary::writeShort($v) : Binary::writeLShort($v);
}
public function getInt(bool $network = false) : int{
if($network === true){
return Binary::readVarInt($this->buffer, $this->offset);
}
return $this->endianness === self::BIG_ENDIAN ? Binary::readInt($this->get(4)) : Binary::readLInt($this->get(4));
}
public function putInt(mixed $v, bool $network = false) : void{
if($network === true){
$this->buffer .= Binary::writeVarInt($v);
}else{
$this->buffer .= $this->endianness === self::BIG_ENDIAN ? Binary::writeInt($v) : Binary::writeLInt($v);
}
}
public function getLong(bool $network = false) : int{
if($network){
return Binary::readVarLong($this->buffer, $this->offset);
}
return $this->endianness === self::BIG_ENDIAN ? Binary::readLong($this->get(8)) : Binary::readLLong($this->get(8));
}
public function putLong(mixed $v, bool $network = false) : void{
if($network){
$this->buffer .= Binary::writeVarLong($v);
}else{
$this->buffer .= $this->endianness === self::BIG_ENDIAN ? Binary::writeLong($v) : Binary::writeLLong($v);
}
}
public function getFloat() : float{
return $this->endianness === self::BIG_ENDIAN ? Binary::readFloat($this->get(4)) : Binary::readLFloat($this->get(4));
}
public function putFloat(mixed $v) : void{
$this->buffer .= $this->endianness === self::BIG_ENDIAN ? Binary::writeFloat($v) : Binary::writeLFloat($v);
}
public function getDouble() : mixed{
return $this->endianness === self::BIG_ENDIAN ? Binary::readDouble($this->get(8)) : Binary::readLDouble($this->get(8));
}
public function putDouble(mixed $v) : void{
$this->buffer .= $this->endianness === self::BIG_ENDIAN ? Binary::writeDouble($v) : Binary::writeLDouble($v);
}
public function getString(bool $network = false) : string|bool{
$len = $network ? Binary::readUnsignedVarInt($this->buffer, $this->offset) : $this->getShort();
return $this->get($len);
}
public function putString(mixed $v, bool $network = false) : void{
if($network === true){
$len = strlen($v);
if($len > 32767){
throw new \InvalidArgumentException("NBT strings cannot be longer than 32767 bytes, got $len bytes");
}
$this->put(Binary::writeUnsignedVarInt($len));
}else{
$len = strlen($v);
if($len > 32767){
throw new \InvalidArgumentException("NBT strings cannot be longer than 32767 bytes, got $len bytes");
}
$this->putShort($len);
}
$this->buffer .= $v;
}
public function getArray() : array{
$data = [];
self::toArray($data, $this->data);
return $data;
}
private static function toArray(array &$data, Tag $tag) : void{
foreach($tag as $key => $value){
if($value instanceof CompoundTag or $value instanceof ListTag or $value instanceof IntArrayTag){
$data[$key] = [];
self::toArray($data[$key], $value);
}else{
$data[$key] = $value->getValue();
}
}
}
public static function fromArrayGuesser($key, $value) : ?Tag{
if(is_int($value)){
return new IntTag($key, $value);
}elseif(is_float($value)){
return new FloatTag($key, $value);
}elseif(is_string($value)){
return new StringTag($key, $value);
}elseif(is_bool($value)){
return new ByteTag($key, $value ? 1 : 0);
}
return null;
}
private static function fromArray(Tag $tag, array $data, callable $guesser) : void{
foreach($data as $key => $value){
if(is_array($value)){
$isNumeric = true;
$isIntArray = true;
foreach($value as $k => $v){
if(!is_numeric($k)){
$isNumeric = false;
break;
}elseif(!is_int($v)){
$isIntArray = false;
}
}
$node = $isNumeric ? ($isIntArray ? new IntArrayTag($key, []) : new ListTag($key, [])) : new CompoundTag($key, []);
self::fromArray($node, $value, $guesser);
$tag[$key] = $node;
}else{
$v = call_user_func($guesser, $key, $value);
if($v instanceof Tag){
$tag[$key] = $v;
}
}
}
}
public function setArray(array $data, callable $guesser = null) : void{
$this->data = new CompoundTag("", []);
self::fromArray($this->data, $data, $guesser ?? [self::class, "fromArrayGuesser"]);
}
public function getData() : CompoundTag|array{
return $this->data;
}
public function setData(CompoundTag|array $data) : void{
$this->data = $data;
}
}
