<?php

namespace pocketmine\scheduler;

abstract class Task{

	private ?TaskHandler $taskHandler = null;

	public final function getHandler() : ?TaskHandler{
		return $this->taskHandler;
	}
	public final function getTaskId() : int{
		if($this->taskHandler !== null){
			return $this->taskHandler->getTaskId();
		}
		return -1;
	}
	public final function setHandler(?TaskHandler $taskHandler) : void{
		if($this->taskHandler === null or $taskHandler === null){
			$this->taskHandler = $taskHandler;
		}
	}
	public abstract function onRun() : void;
	public function onCancel() : void{}
}
