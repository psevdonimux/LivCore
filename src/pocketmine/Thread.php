<?php declare(strict_types=1);

namespace pocketmine;

abstract class Thread extends \Thread {

 protected \ClassLoader $classLoader;
 protected bool $isKilled = false;

 public function getClassLoader(){
  return $this->classLoader;
 }
 public function setClassLoader(\ClassLoader $loader = null) : void{
  if($loader === null){
   $loader = Server::getInstance()->getLoader();
  }
  $this->classLoader = $loader;
 }
 public function registerClassLoader() : void{
  if(!interface_exists('ClassLoader', false)){
   require(\pocketmine\PATH. 'src/spl/ClassLoader.php');
   require(\pocketmine\PATH. 'src/spl/BaseClassLoader.php');
  }
  if($this->classLoader !== null){
   $this->classLoader->register(true);
  }
 }
 public function start(?int $options = \PTHREADS_INHERIT_ALL){
  ThreadManager::getInstance()->add($this);
  if($this->getClassLoader() === null){
   $this->setClassLoader();
  }
  return parent::start($options);
 }
 public function quit(){
  $this->isKilled = true;
  if(!$this->isJoined()){
   $this->notify();
   $this->join();
  }
  ThreadManager::getInstance()->remove($this);
 }
 public function getThreadName(){
  return (new \ReflectionClass($this))->getShortName();
 }
}