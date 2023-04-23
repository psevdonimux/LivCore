<?php //support pmmp class

namespace pocketmine\block;

class BlockFactory{

 public function get(int $id, int $meta = 0) : Block{
  return Block::get($id, $meta);
 }
}
