<?php declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

#include <rules/DataPacket.h>

class MovePlayerPacket extends DataPacket {

	const NETWORK_ID = ProtocolInfo::MOVE_PLAYER_PACKET;

	const MODE_NORMAL = 0;
	const MODE_RESET = 1;
    const MODE_TELEPORT = 2;
    const MODE_PITCH = 3; //facepalm Mojang

	public /*float*/ $x, $y, $z, $yaw, $bodyYaw, $pitch;
	public /*bool*/ $onGround = false; //TODO
	public /*int*/$eid2 = 0, $teleportCause = 0, $teleportItem = 0, $mode = self::MODE_NORMAL, $eid;

	public function decode(){
		$this->eid = $this->getEntityId(); //EntityRuntimeID
		$this->getVector3f($this->x, $this->y, $this->z);
		$this->pitch = $this->getLFloat();
		$this->yaw = $this->getLFloat();
		$this->bodyYaw = $this->getLFloat();
		$this->mode = $this->getByte();
		$this->onGround = $this->getBool();
		$this->eid2 = $this->getEntityId();
        if($this->mode === MovePlayerPacket::MODE_TELEPORT){
            $this->teleportCause = $this->getLInt();
            $this->teleportItem = $this->getLInt();
        }
	}
	public function encode(){
		$this->reset();
		$this->putEntityId($this->eid); //EntityRuntimeID
		$this->putVector3f($this->x, $this->y, $this->z);
		$this->putLFloat($this->pitch);
		$this->putLFloat($this->yaw);
		$this->putLFloat($this->bodyYaw); //TODO
		$this->putByte($this->mode);
		$this->putBool($this->onGround);
		$this->putEntityId($this->eid2); //EntityRuntimeID
        if($this->mode === MovePlayerPacket::MODE_TELEPORT){
            $this->putLInt($this->teleportCause);
            $this->putLInt($this->teleportItem);
        }
	}
}
