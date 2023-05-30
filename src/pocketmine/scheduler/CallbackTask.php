<?php

namespace pocketmine\scheduler;

class CallbackTask extends Task {

	protected callable $callable;
	protected array $args;

	public function __construct(callable $callable, array $args = []){
		$this->callable = $callable;
		$this->args = $args;
		$this->args[] = $this;
	}
	public function getCallable() : callable{
		return $this->callable;
	}
	public function onRun() : void{
		call_user_func_array($this->callable, $this->args);
	}
}
