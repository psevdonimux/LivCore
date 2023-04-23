<?php declare(strict_types=1);

namespace pocketmine\metadata;

use pocketmine\plugin\Plugin;

interface Metadatable {

 public function setMetadata(string $metadataKey, MetadataValue $newMetadataValue) : void;
 public function getMetadata(string $metadataKey) : array;
 public function hasMetadata(string $metadataKey) : bool;
 public function removeMetadata(string $metadataKey, Plugin $owningPlugin) : void;
}