<?php declare(strict_types=1);

namespace pocketmine;

use pocketmine\utils\MainLogger;

class ThreadManager extends \Volatile {

 private static ?ThreadManager $instance = null;

 public static function init() : void{
  self::$instance = new ThreadManager();
 }
 public static function getInstance() : ThreadManager{
  return self::$instance;
 }
 public function add($thread) : void{
  if($thread instanceof Thread or $thread instanceof Worker){
   $this->{spl_object_hash($thread)} = $thread;
  }
 }
 public function remove($thread) : void{
  if($thread instanceof Thread or $thread instanceof Worker){
   unset($this->{spl_object_hash($thread)});
  }
 }
 public function getAll() : array{
  $array = [];
  foreach($this as $key => $thread){
   $array[$key] = $thread;
  }
  return $array;
 }
 public function stopAll() : int{
  $logger = MainLogger::getLogger();
  $erroredThreads = 0;
   foreach($this->getAll() as $thread){
  $logger->debug('Stopping '. $thread->getThreadName(). ' thread');
  try{
   $thread->quit();
   $logger->debug($thread->getThreadName(). ' thread stopped successfully.');
  }
  catch(\ThreadException $e){
   ++$erroredThreads;
  $logger->debug('Could not stop '. $thread->getThreadName(). ' thread: '. $e->getMessage());
  }
   }
  return $erroredThreads;
 }
}