author refactoring: psevdonimux
original core: LiteCore-public v1.0.5

1. вырезал комментарий, выровнил код, сократил импорты, проставил типы
 - pocketmine\Worker.php
 - pocketmine\ThreadManager.php
 - pocketmine\Thread.php
 - pocketmine\Server.php
 - pocketmine\command\defaults\StatusCommand.php
 - pocketmine\level\generator\PopulationTask.php
 - pocketmine\PocketMine.php
 - pocketmine\Player.php
 - pocketmine\IPlayer.php
 - pocketmine\command\CommandSender.php
 - pocketmine\permission\ServerOperator.php
 - pocketmine\permission\Permissible.php
 - pocketmine\level\ChunkLoader.php
 - pocketmine\command\ConsoleCommandSender.php
 - pocketmine\level\generator\normal\Normal.php
 - pocketmine\entity\Creature.php
 - pocketmine\network\Network.php
 - pocketmine\entity\Human.php
 - pocketmine\OfflinePlayer.php 
 - pocketmine\level\Level.php
 - pocketmine\level\format\Chunk.php 
2. исправленные баги:
 - при зажатий курсора на существе можно было ударить его 1 раз без рук, это исправлено
