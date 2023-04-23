<?php

namespace pocketmine;

use pocketmine\permission\ServerOperator;

interface IPlayer extends ServerOperator {

 public function isOnline() : bool;
 public function getName() : string;
 public function isBanned() : bool;
 public function setBanned(bool $banned) : void;
 public function isWhitelisted() : bool;
 public function setWhitelisted(bool $value) : void;
 public function getPlayer() : ?Player;
 public function getFirstPlayed() : ?float;
 public function getLastPlayed() : ?float;
 public function hasPlayedBefore() : bool;
}