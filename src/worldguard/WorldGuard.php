<?php
namespace worldguard;
use pocketmine\command\{CommandSender, Command};
use pocketmine\level\{Level, Position};
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use worldguard\region\{Region, RegionFlags};
use pocketmine\utils\TextFormat as TF;
class WorldGuard extends PluginBase {
    
    const HELP_MESSAGE = [
        "pos1" => TF::BLUE."/{CMD} pos1:".TF::YELLOW." Sets position 1",
        "pos2" => TF::BLUE."/{CMD} pos2:".TF::YELLOW." Sets position 2",
        "wand" => TF::BLUE."/{CMD} wand:".TF::YELLOW." Gives you worldguard wand",
        "create" => TF::BLUE."/{CMD} create <region>:".TF::YELLOW." Creates a new region.",
        "setflag" => TF::BLUE."/{CMD} setflag <region> <flag> <true/false>:".TF::YELLOW." Sets/removes a flag from a region.",
        "delete" => TF::BLUE."/{CMD} delete <region>:".TF::YELLOW." Deletes a region.",
        "list" => TF::BLUE."/{CMD} list <page>:".TF::YELLOW." Lists all regions.",
        "info" => TF::BLUE."/{CMD} info <region>:".TF::YELLOW." Get information of a region."
        ];
     private $creator = [];
    private $cache = [];
    /** @var Region[] */
    private $regions = [];
    /** @var string[] */
    private $regionCache = [];//for faster region checking.
    /** @var array */
    private $players = [];//realtime player regions
    public function onLoad() : void
    {
        $this->saveResource("regions.yml");
        $this->loadRegions($this->getDataFolder()."regions.yml");
    }
    public function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
    }
    public function onDisable() : void
    {
        $this->saveRegions($this->getDataFolder()."regions.yml");
    }
    /**
     * Loads regions from YAML file.
     */
    public function loadRegions(string $file) : void
    {
        if (substr($file, -4) === ".yml") {
            foreach (yaml_parse_file($file) as $region => [
                "world" => $world,
                "pos1" => $pos1,
                "pos2" => $pos2,
                "flags" => $flags
            ]) {
                $this->regions[$name = strtolower($region)] = new Region(
                    $name,
                    new Vector3(...array_map("floatval", explode(":", $pos1))),
                    new Vector3(...array_map("floatval", explode(":", $pos2))),
                    $world,
                    $flags
                );
            }
            return;
        }
        throw new \Error("Could not read file ".basename($file).", invalid format.");
    }
    /**
     * Creates a new region.
     *
     * @param string $name
     * @param Vector3 $pos1
     * @param Vector3 $pos2
     * @param Level $level
     *
     * @return Region that has been created.
     */
    public function createRegion(string $name, Vector3 $pos1, Vector3 $pos2, Level $level) : Region
    {
        $this->cacheRegion($region = $this->regions[$name = strtolower($name)] = new Region($name, $pos1, $pos2, $level->getName()));
        return $region;
    }
    /**
     * Saves regions to YAML file.
     */
    public function saveRegions(string $file) : void
    {
        $regions = $this->regions;
        foreach ($regions as &$region) {
            $region = $region->getData();
        }
        yaml_emit_file($file, $regions);
    }
    /**
     * Deletes a region
     *
     * @return bool
     */
    public function deleteRegion(string $region) : bool
    {
        if (isset($this->regions[$region = strtolower($region)])) {
            $this->removeFromCache($region);
            unset($this->regions[$region]);
            return true;
        }
        return false;
    }
    /**
     * Returns all loaded regions.
     *
     * @return Region[]
     */
    public function getRegions() : array
    {
        return $this->regions;
    }
    /**
     * Returns a region by name.
     *
     * @return Region|null
     */
    public function getRegion(string $region) : ?Region
    {
        return $this->regions[strtolower($region)] ?? null;
    }
    /**
     * Returns region at Position.
     *
     * @return Region|null
     */
    public function getRegionFromPos(Position $pos) : ?Region
    {
        if (!empty($this->regionCache[$k = $pos->level->getName().":".(isset($pos->chunk) ? $pos->chunk->getX().":".$pos->chunk->getZ() : ($pos->x >> 4).":".($pos->z >> 4))][$k2 = $pos->y >> 4])) {
            foreach ($this->regionCache[$k][$k2] as $k3 => $region) {
                $region = $this->regions[$region] ?? null;
                if ($region === null) {
                    unset($this->regionCache[$k][$k2][$k3]);
                    continue;
                }
                if ($region->contains($pos)) {
                    return $region;
                }
            }
        }
        return null;
    }
    public function regionsExistInChunk(int $chunkX, int $chunkZ, string $level) : bool
    {
        return !empty($this->regionCache[$level.":".$chunkX.":".$chunkZ]);
    }
    /**
     * Caches a region for faster checking.
     */
    public function cacheRegion(Region $region) : void
    {
        $regionName = $region->getName();
        $level = $region->getLevelname();
        [$posMin, $posMax] = $region->getPositions();
        $posMin->x >>= 4;
        $posMin->y >>= 4;
        $posMin->z >>= 4;
        $posMax->x >>= 4;
        $posMax->y >>= 4;
        $posMax->z >>= 4;
        for ($chunkX = $posMin->x; $chunkX <= $posMax->x; ++$chunkX) {
            for ($chunkZ = $posMin->z; $chunkZ <= $posMax->z; ++$chunkZ) {
                for ($chunkY = $posMin->y; $chunkY <= $posMax->y; ++$chunkY) {
                    if (!isset($this->regionCache[$chunkLevelXZ = $level.":".$chunkX.":".$chunkZ])) {
                        $this->regionCache[$chunkLevelXZ] = [];
                    }
                    if (!isset($this->regionCache[$chunkLevelXZ][$chunkY])) {
                        $this->regionCache[$chunkLevelXZ][$chunkY] = [];
                    }
                    $this->regionCache[$chunkLevelXZ][$chunkY][] = $regionName;//this will narrow down getRegionFromPos() checks
                }
            }
        }
    }
    /**
     * Caches all regions of a specific level.
     */
    public function cacheLevelRegions(string $level) : void
    {
        $regionList = array_keys(array_column($this->regions, "world", "name"), $level, true);//array of region names
        if (!empty($regionList)) {
            foreach ($regionList as $region) {
                $this->cacheRegion($this->getRegion($region));
            }
            $this->getLogger()->notice("Found and loaded ".count($regionList)." from level '".$level."'");
        }
    }
    /**
     * Called when player enters another region.
     *
     * @param string|null $oldRegion
     * @param string|null $newRegion
     *
     * @return bool whether player is allowed to enter the new region / get out of the old region.
     */
    public function onRegionChange(Player $player, ?string $oldRegion, ?string $newRegion) : bool
    {
        if ($oldRegion !== null) {
            $oldRegion = $this->getRegion($oldRegion);
            if ($oldRegion->hasFlag(RegionFlags::CANNOT_LEAVE)) {
                return false;
            }
            if ($oldRegion->hasFlag(RegionFlags::CAN_FLY)) {
                if($player->hasPermission("worldguard.fly.bypass")){
                $player->setAllowFlight(false);
                if ($player->isFlying()) {
                    $player->setFlying(false);
                }
            }
        }
        if ($newRegion !== null) {
            $newRegion = $this->getRegion($newRegion);
            if ($newRegion->hasFlag(RegionFlags::CANNOT_ENTER)) {
                return false;
            }
            if ($newRegion->hasFlag(RegionFlags::CAN_FLY)) {
                if($player->hasPermission("worldguard.fly.bypass")){
                $player->setAllowFlight(false);
                if ($player->isFlying()) {
                    $player->setFlying(false);
                }
                }
            }
        }
        }
    }
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
     if (strtolower($command->getName()) == "worldguard") {
         if($sender instanceof Player){
         if($sender->hasPermission("worldguard.command")){
                if (empty($args)) {
                    $sender->sendMessage("§bPlease use §3/$label help §bfor a list of commands.");
                    return true;
                }
             if ($args[0] == "pos1") {
                if($sender->isOp()){
                $this->setPosition($sender, 1);
                return true;
             if ($args[0] == "pos2") {
                if($sender->isOp()){
                $this->setPosition($sender, 2);
                return true;
             if ($args[0] == "create") {
                if($sender->isOp()){
                if (!isset($this->creator[$k = $sender->getId()]) || count($this->creator[$k]) !== 2) {
                    $sender->sendMessage(TF::RED."Please select two points using /$label pos1 and /$label pos2 before creating a new region.");
                    return true;
                }
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED."Usage: ".str_replace("{CMD}", $label, self::HELP_MESSAGE["create"]));
                    return true;
                }
                if (!ctype_alnum($args[1])) {
                    $sender->sendMessage(TF::RED."Region name must be alpha-numeric.");
                    return true;
                }
                if ($this->getRegion($args[1]) !== null) {
                    $sender->sendMessage(TF::RED."A region by the name ".$args[1]." already exists. Choose a different name or delete the current region named ".$args[1].".");
                    return true;
                }
                $this->creator[$k][] = $sender->getLevel();
                $region = $this->createRegion($args[1], ...$this->creator[$k]);
                unset($this->creator[$k]);
                $message = TF::GREEN."Created region ".$region->getName()." ";
                foreach ($region->getPositions() as $pos) {
                    $message .= $pos." ";
                }
                $sender->sendMessage($message);
                return true;
                }
             }
             if ($args[0] == "delete") {
                if($sender->isOp()){
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED."Usage: ".str_replace("{CMD}", $label, self::HELP_MESSAGE["delete"]));
                    return true;
                }
                if ($this->deleteRegion($args[1])) {
                    $sender->sendMessage(TF::GREEN."Region '".$args[1]."' has been deleted.");
                } else {
                    $sender->sendMessage(TF::RED."Region '".$args[1]."' does not exist.");
                }
                return true;
             if ($args[0] == "setflag") {
                if($sender->isOp()){
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED."Usage: ".str_replace("{CMD}", $label, self::HELP_MESSAGE["setflag"]));
                    return true;
                }
                if (!isset($args[2])) {
                    $sender->sendMessage(TF::BLUE."Available flags: ".($this->cache["flags"] ?? $this->cache["flags"] = TF::YELLOW.implode(TF::BLUE.", ".TF::YELLOW, array_keys(Region::FLAG2STRING))));
                    return true;
                }
                if (!isset($args[3])) {
                    $sender->sendMessage(TF::RED."Usage: ".str_replace("{CMD}", $label, self::HELP_MESSAGE["setflag"]));
                    return true;
                }
                $flag = Region::FLAG2STRING[$args[2] = strtolower($args[2])] ?? null;
                if ($flag === null) {
                    $sender->sendMessage(TF::RED."Invalid flag '".$args[2]."'.");
                    return true;
                }
                $region = $this->getRegion($args[1]);
                if ($region === null) {
                    $sender->sendMessage(TF::RED."No region with the name '".$args[1]."' exists.");
                    return true;
                }
                $args[3] = $args[3] ?? "true";
                if ($args[3] === "true") {
                    if ($region->hasFlag($flag)) {
                        $sender->sendMessage(TF::RED."'".$region->getName()."' already has this flag set.");
                    } else {
                        $region->setFlag($flag);
                        $sender->sendMessage(TF::GREEN."Flag '".$args[2]."' has been set to region '".$region->getName()."'.");
                    }
                } elseif ($args[3] === "false") {
                    if (!$region->hasFlag($flag)) {
                        $sender->sendMessage(TF::RED."'".$region->getName()."' does not have this flag set.");
                    } else {
                        $region->removeFlag($flag);
                        $sender->sendMessage(TF::GREEN."Flag '".$args[2]."' has been removed from region '".$region->getName()."'.");
                    }
                } else {
                    $sender->sendMessage(TF::RED."Invalid argument '".$args[3]."', you can set a flag to either 'true' or 'false'.");
                }
                return true;
             if ($args[0] == "help") {
                if($sender->isOp()){
                $sender->sendMessage(implode("\n", str_replace("{CMD}", $label, self::HELP_MESSAGE)));
                return true;
                }
             }
    }
             }
                }
             }
                }
             }
                return true;
             }
                }
             }
     }
    }
    }
    private function setPosition(Player $player, int $pos) : void{
        --$pos;
        $player->sendMessage(TF::LIGHT_PURPLE.($pos === 0 ? "First" : "Second")." position set to (".$player->x.", ".$player->y.", ".$player->z.", ".$player->level->getName().")");
        if (!isset($this->creator[$k = $player->getId()])) {
            $this->creator[$k] = [];
        }
        $this->creator[$k][$pos] = $player->asVector3();
    }
}
