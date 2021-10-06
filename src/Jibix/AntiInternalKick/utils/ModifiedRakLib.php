<?php
namespace Jibix\AntiInternalKick\utils;
use Jibix\AntiInternalKick\commands\LastErrorCommand;
use Jibix\AntiInternalKick\Main;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\AdvancedSourceInterface;
use pocketmine\network\mcpe\CachedEncapsulatedPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\Network;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\MainLogger;
use pocketmine\utils\Utils;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use raklib\utils\InternetAddress;


/**
 * Class Main
 * @package Jibix\AntiInternalKick\utils
 * @author Jibix
 * @date 29.08.2021 - 17:37
 * @project AntiInternalKick
 */
class ModifiedRakLib implements ServerInstance, AdvancedSourceInterface{
    /**
     * Sometimes this gets changed when the MCPE-layer protocol gets broken to the point where old and new can't
     * communicate. It's important that we check this to avoid catastrophes.
     */
    private const MCPE_RAKNET_PROTOCOL_VERSION = 10;

    /** @var Server */
    private $server;

    /** @var Network */
    private $network;

    /** @var RakLibServer */
    private $rakLib;

    /** @var Player[] */
    private $players = [];

    /** @var string[] */
    private $identifiers = [];

    /** @var int[] */
    private $identifiersACK = [];

    /** @var ServerHandler */
    private $interface;

    /** @var SleeperNotifier */
    private $sleeper;

    public function __construct(Server $server){
        $this->server = $server;

        $this->sleeper = new SleeperNotifier();
        $this->rakLib = new RakLibServer(
            $this->server->getLogger(),
            \pocketmine\COMPOSER_AUTOLOADER_PATH,
            new InternetAddress($this->server->getIp(), $this->server->getPort(), 4),
            (int) $this->server->getProperty("network.max-mtu-size", 1492),
            self::MCPE_RAKNET_PROTOCOL_VERSION,
            $this->sleeper
        );
        $this->interface = new ServerHandler($this->rakLib, $this);
    }

    public function start(){
        $this->server->getTickSleeper()->addNotifier($this->sleeper, function() : void{
            $this->process();
        });
        $this->rakLib->start(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
    }

    public function setNetwork(Network $network){
        $this->network = $network;
    }

    public function process() : void{
        while($this->interface->handlePacket()){}

        if(!$this->rakLib->isRunning() and !$this->rakLib->isShutdown()){
            throw new \Exception("RakLib Thread crashed");
        }
    }

    public function closeSession(string $identifier, string $reason) : void{
        if(isset($this->players[$identifier])){
            $player = $this->players[$identifier];
            unset($this->identifiers[spl_object_hash($player)]);
            unset($this->players[$identifier]);
            unset($this->identifiersACK[$identifier]);
            $player->close($player->getLeaveMessage(), $reason);
        }
    }

    public function close(Player $player, string $reason = "unknown reason"){
        if(isset($this->identifiers[$h = spl_object_hash($player)])){
            unset($this->players[$this->identifiers[$h]]);
            unset($this->identifiersACK[$this->identifiers[$h]]);
            $this->interface->closeSession($this->identifiers[$h], $reason);
            unset($this->identifiers[$h]);
        }
    }

    public function shutdown(){
        $this->server->getTickSleeper()->removeNotifier($this->sleeper);
        $this->interface->shutdown();
    }

    public function emergencyShutdown(){
        $this->server->getTickSleeper()->removeNotifier($this->sleeper);
        $this->interface->emergencyShutdown();
    }

    public function openSession(string $identifier, string $address, int $port, int $clientID) : void{
        $ev = new PlayerCreationEvent($this, Player::class, Player::class, $address, $port);
        $ev->call();
        $class = $ev->getPlayerClass();

        /**
         * @var Player $player
         * @see Player::__construct()
         */
        $player = new $class($this, $ev->getAddress(), $ev->getPort());
        $this->players[$identifier] = $player;
        $this->identifiersACK[$identifier] = 0;
        $this->identifiers[spl_object_hash($player)] = $identifier;
        $this->server->addPlayer($player);
    }

    public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags) : void{
        if(isset($this->players[$identifier])){
            //get this now for blocking in case the player was closed before the exception was raised
            $player = $this->players[$identifier];
            $address = $player->getAddress();
            try{
                if($packet->buffer !== ""){
                    $pk = new BatchPacket($packet->buffer);
                    $player->handleDataPacket($pk);
                }
            }catch(\Throwable $e){
                $config = Main::getInstance()->getConfig()->getAll();

                if ($config['throw-error']) {
                    $logger = $this->server->getLogger();
                    $logger->debug("Packet " . (isset($pk) ? get_class($pk) : "unknown") . ": " . base64_encode($packet->buffer));
                    $logger->logException($e);
                }

                $lastErrorAsString = "";

                $trace = $e->getTrace();
                $exception = self::printExceptionMessage($e);
                $lastErrorAsString = $lastErrorAsString."\n\n\n§a$exception\n\n";

                foreach(Utils::printableTrace($trace) as $line){
                    $lastErrorAsString = $lastErrorAsString."§7$line\n\n";
                }

                for($prev = $e->getPrevious(); $prev !== null; $prev = $prev->getPrevious()){
                    foreach(Utils::printableTrace($prev->getTrace()) as $line){
                        $lastErrorAsString = $lastErrorAsString."   §e$line\n";
                    }
                }

                LastErrorCommand::setLastError($lastErrorAsString);

                if ($player instanceof Player) {
                    if ($config['send-message'])
                        $player->sendMessage($config['message']);
                    if ($config['send-title'])
                        $player->sendMessage($config['title']);
                }
                //$this->interface->blockAddress($address, 5);
            }
        }
    }

    private static function printExceptionMessage(\Throwable $e) : string{
        static $errorConversion = [
            0 => "EXCEPTION",
            E_ERROR => "E_ERROR",
            E_WARNING => "E_WARNING",
            E_PARSE => "E_PARSE",
            E_NOTICE => "E_NOTICE",
            E_CORE_ERROR => "E_CORE_ERROR",
            E_CORE_WARNING => "E_CORE_WARNING",
            E_COMPILE_ERROR => "E_COMPILE_ERROR",
            E_COMPILE_WARNING => "E_COMPILE_WARNING",
            E_USER_ERROR => "E_USER_ERROR",
            E_USER_WARNING => "E_USER_WARNING",
            E_USER_NOTICE => "E_USER_NOTICE",
            E_STRICT => "E_STRICT",
            E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
            E_DEPRECATED => "E_DEPRECATED",
            E_USER_DEPRECATED => "E_USER_DEPRECATED"
        ];

        $errstr = preg_replace('/\s+/', ' ', trim($e->getMessage()));

        $errno = $e->getCode();
        $errno = $errorConversion[$errno] ?? $errno;

        $errfile = Utils::cleanPath($e->getFile());
        $errline = $e->getLine();

        return get_class($e) . ": \"$errstr\" ($errno) in \"$errfile\" at line $errline";
    }

    public function blockAddress(string $address, int $timeout = 300){
        $this->interface->blockAddress($address, $timeout);
    }

    public function unblockAddress(string $address){
        $this->interface->unblockAddress($address);
    }

    public function handleRaw(string $address, int $port, string $payload) : void{
        $this->server->handlePacket($this, $address, $port, $payload);
    }

    public function sendRawPacket(string $address, int $port, string $payload){
        $this->interface->sendRaw($address, $port, $payload);
    }

    public function notifyACK(string $identifier, int $identifierACK) : void{

    }

    public function setName(string $name){
        $info = $this->server->getQueryInformation();

        $this->interface->sendOption("name", implode(";",
                [
                    "MCPE",
                    rtrim(addcslashes($name, ";"), '\\'),
                    ProtocolInfo::CURRENT_PROTOCOL,
                    ProtocolInfo::MINECRAFT_VERSION_NETWORK,
                    $info->getPlayerCount(),
                    $info->getMaxPlayerCount(),
                    $this->rakLib->getServerId(),
                    $this->server->getName(),
                    Server::getGamemodeName(Player::getClientFriendlyGamemode($this->server->getGamemode()))
                ]) . ";"
        );
    }

    /**
     * @param bool $name
     *
     * @return void
     */
    public function setPortCheck($name){
        $this->interface->sendOption("portChecking", $name);
    }

    public function setPacketLimit(int $limit) : void{
        $this->interface->sendOption("packetLimit", $limit);
    }

    public function handleOption(string $option, string $value) : void{
        if($option === "bandwidth"){
            $v = unserialize($value);
            $this->network->addStatistics($v["up"], $v["down"]);
        }
    }

    public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = true){
        if(isset($this->identifiers[$h = spl_object_hash($player)])){
            $identifier = $this->identifiers[$h];
            if(!$packet->isEncoded){
                $packet->encode();
            }

            if($packet instanceof BatchPacket){
                if($needACK){
                    $pk = new EncapsulatedPacket();
                    $pk->identifierACK = $this->identifiersACK[$identifier]++;
                    $pk->buffer = $packet->buffer;
                    $pk->reliability = PacketReliability::RELIABLE_ORDERED;
                    $pk->orderChannel = 0;
                }else{
                    if(!isset($packet->__encapsulatedPacket)){
                        $packet->__encapsulatedPacket = new CachedEncapsulatedPacket;
                        $packet->__encapsulatedPacket->identifierACK = null;
                        $packet->__encapsulatedPacket->buffer = $packet->buffer;
                        $packet->__encapsulatedPacket->reliability = PacketReliability::RELIABLE_ORDERED;
                        $packet->__encapsulatedPacket->orderChannel = 0;
                    }
                    $pk = $packet->__encapsulatedPacket;
                }

                $this->interface->sendEncapsulated($identifier, $pk, ($needACK ? RakLib::FLAG_NEED_ACK : 0) | ($immediate ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));
                return $pk->identifierACK;
            }else{
                $this->server->batchPackets([$player], [$packet], true, $immediate);
                return null;
            }
        }

        return null;
    }

    public function updatePing(string $identifier, int $pingMS) : void{
        if(isset($this->players[$identifier])){
            $this->players[$identifier]->updatePing($pingMS);
        }
    }
}