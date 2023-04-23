<?php declare(strict_types=1);

namespace pocketmine;

abstract class Worker extends \Worker {

 protected \ClassLoader $classLoader;
 protected bool $isKilled = false;

 public function getClassLoader() : \ClassLoader{
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
 public function quit() : void{
  $this->isKilled = true;
  if($this->isRunning()){
  while($this->unstack() !== null);
   $this->notify();
   $this->shutdown();
  }
  ThreadManager::getInstance()->remove($this);
 }
 public function getThreadName(){
  return (new \ReflectionClass($this))->getShortName();
 }
}