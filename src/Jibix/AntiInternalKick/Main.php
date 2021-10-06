<?php
namespace Jibix\AntiInternalKick;
use Jibix\AntiInternalKick\commands\LastErrorCommand;
use Jibix\AntiInternalKick\utils\ModifiedRakLib;
use pocketmine\network\mcpe\RakLibInterface;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;


/**
 * Class Main
 * @package Jibix\AntiInternalKick
 * @author Jibix
 * @date 29.08.2021 - 17:25
 * @project AntiInternalKick
 */
class Main extends PluginBase{

    /** @var Main */
    private static Main $instance;

    /**
     * Function getInstance
     * @return Main
     */
    public static function getInstance(): Main{
        return self::$instance;
    }

    /**
     * Function onEnable
     */
    public function onEnable(): void{
        self::$instance = $this;
        $this->saveResource('config.yml');

        $this->getServer()->getCommandMap()->register("lasterror", new LastErrorCommand());

        $network = Server::getInstance()->getNetwork();
        foreach ($network->getInterfaces() as $interface) {
            if ($interface instanceof RakLibInterface) {
                $interface->shutdown();

                $network->unregisterInterface($interface);
                $network->registerInterface(new ModifiedRakLib($this->getServer()));
                return;
            }
        }
    }
}