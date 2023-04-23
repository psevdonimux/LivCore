<?php

namespace pocketmine\permission;

use pocketmine\plugin\Plugin;

interface Permissible extends ServerOperator {
	
 public function isPermissionSet(Permission|string $name) : bool;
 public function hasPermission(Permission|string $name) : bool;
 public function addAttachment(Plugin $plugin, $name = null, $value = null) : ?PermissionAttachment;
 public function removeAttachment(PermissionAttachment $attachment) : void;
 public function recalculatePermissions() : void;
 public function getEffectivePermissions() : array;
}