<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\snooze;

use function assert;

/**
 * Notifiers are Threaded objects which can be attached to threaded sleepers in order to wake them up. They also record
 * state so that the main thread handler can determine which notifier woke up the sleeper.
 */
class SleeperNotifier extends \Threaded{
	/** @var ThreadedSleeper */
	private $threadedSleeper;

	/** @var int */
	private $sleeperId;

	/** @var bool */
	private $notification = false;

	final public function attachSleeper(ThreadedSleeper $sleeper, int $id) : void{
		$this->threadedSleeper = $sleeper;
		$this->sleeperId = $id;
	}

	final public function getSleeperId() : int{
		return $this->sleeperId;
	}

	/**
	 * Call this method from other threads to wake up the main server thread.
	 */
	final public function wakeupSleeper() : void{
		assert($this->threadedSleeper !== null);

		$this->threadedSleeper->synchronized(function() : void{
			if(!$this->notification){
				$this->notification = true;

				/*
				 * if we didn't synchronize with ThreadedSleeper, the main thread might detect the notification
				 * (notification = true by this point in the code), process and decrement notification count, all before
				 * we got a chance to increment it and wake up the sleeper in the first place, leading to an underflow.
				 */
				$this->threadedSleeper->wakeupNoSync();
			}
		});
	}

	final public function hasNotification() : bool{
		return $this->notification;
	}

	final public function clearNotification() : void{
		/* wakeupSleeper() synchronizes with ThreadedSleeper, we must do the same here. */
		$this->threadedSleeper->synchronized(function() : void{
			$this->threadedSleeper->clearNotificationNoSync();
			$this->notification = false;
		});
	}
}
