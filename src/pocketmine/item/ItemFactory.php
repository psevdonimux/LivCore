<?php //support pmmp class

namespace pocketmine\item;

class ItemFactory{

 public function get(int $id, int $meta = 0, int $count = 1, string $tags = "") : Item{
  return Item::get($id, $meta, $count, $tags);
 }
}
