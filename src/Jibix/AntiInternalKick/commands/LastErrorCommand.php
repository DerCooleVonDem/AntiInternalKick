<?php

namespace Jibix\AntiInternalKick\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class LastErrorCommand extends Command{

    private static string $lastError = "§aNo Error! :D";

    public function __construct()
    {
        parent::__construct("lasterror", "Shows the last Internal Server Error", "", ["le"]);
    }

    /**
     * @param CommandSender $sender
     * @param string $commandLabel
     * @param string[] $args
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args)
    {
        if($sender->isOp()){
            $sender->sendMessage(self::getLastError());
            return;
        }
        $sender->sendMessage("§cYou are not allowed to use this command!");
    }

    /**
     * @return string
     */
    public static function getLastError(): string
    {
        return self::$lastError;
    }

    /**
     * @param string $lastError
     */
    public static function setLastError(string $lastError): void
    {
        self::$lastError = $lastError;
    }
}