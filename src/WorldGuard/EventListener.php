<?php

/**
*
*  _     _  _______  ______    ___      ______   _______  __   __  _______  ______    ______  
* | | _ | ||       ||    _ |  |   |    |      | |       ||  | |  ||   _   ||    _ |  |      | 
* | || || ||   _   ||   | ||  |   |    |  _    ||    ___||  | |  ||  |_|  ||   | ||  |  _    |
* |       ||  | |  ||   |_||_ |   |    | | |   ||   | __ |  |_|  ||       ||   |_||_ | | |   |
* |       ||  |_|  ||    __  ||   |___ | |_|   ||   ||  ||       ||       ||    __  || |_|   |
* |   _   ||       ||   |  | ||       ||       ||   |_| ||       ||   _   ||   |  | ||       |
* |__| |__||_______||___|  |_||_______||______| |_______||_______||__| |__||___|  |_||______| 
*
* By Muqsit Rayyan.
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Twitter: @muqsitrayyan
* GitHub: https://github.com/Muqsit
*/

namespace WorldGuard;

use pocketmine\event\block\{BlockPlaceEvent, BlockBreakEvent};
use pocketmine\event\entity\{EntityDamageEvent, EntityDamageByEntityEvent, EntityExplodeEvent, ProjectileLaunchEvent};
use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerJoinEvent, PlayerMoveEvent, PlayerInteractEvent, PlayerCommandPreprocessEvent, PlayerDropItemEvent, PlayerBedEnterEvent, PlayerChatEvent};
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\utils\TextFormat as TF;

class EventListener implements Listener {

    //The reason why item IDs are being used directly, rather than ItemIds::CONSTANTs is for the cross-compatibility amongst forks.

    //These are the items that can be activated with the "use" flag enabled.
    const USABLES = [
        23, 25, 54, 58, 61, 62, 63, 64, 68, 69, 71, 77, 92, 93, 94, 96, 116, 117, 118, 130, 135, 138, 145, 146, 149, 150, 154, 183, 184, 185, 186, 187, 193, 194, 195, 196, 197, 
    ];

    const POTIONS = [
        373, 374, 437, 438, 444
    ];
    
    const OTHER = [
        256, 259, 269, 273, 277, 284, 290, 291, 292, 293, 294, 325
    ];

    private $plugin;

    public function __construct(WorldGuard $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
    * @priority MONITOR
    */
    public function onJoin(PlayerJoinEvent $event)
    {
        $this->plugin->sessionizePlayer($event->getPlayer());
    }

    public function onInteract(PlayerInteractEvent $event)
    {
        if (isset($this->plugin->creating[$id = ($player = $event->getPlayer())->getRawUniqueId()])) {
            if ($event->getAction() === $event::RIGHT_CLICK_BLOCK) {
                $block = $event->getBlock();
                $player->sendMessage(TF::YELLOW.'§dSelected position: X§5'.$block->x.', §dY: '.$block->y.', §5Z: '.$block->z.', §5dLevel: '.$block->getLevel()->getName());
                $this->plugin->creating[$id][] = [$block->x, $block->y, $block->z, $block->getLevel()->getName()];
                if (count($this->plugin->creating[$id]) >= 2) {
                    if (($reg = $this->plugin->processCreation($player)) !== false) {
                        $player->sendMessage(TF::GREEN.'§dSuccessfully created region! Now, you can use: /rg flags <set/get> '.$reg);
                    } else {
                        $player->sendMessage(TF::RED.'§cAn error occurred while creating the region. Are you sure you entered the command correctly?');
                    }
                }
                $event->setCancelled();
                return;
            }
        }

        if (($reg = $this->plugin->getRegionByPlayer($player)) !== "") {
            if (!$reg->isWhitelisted($player)) {

                if ($reg->getFlag("use") === "false") {
                    if (in_array($event->getBlock()->getId(), self::USABLES)) {
                        $player->sendMessage(TF::RED.'§2You cannot interact with §3'.$event->getBlock()->getName().'s.');
                        $event->setCancelled();
                        return;
                    }
                } else $event->setCancelled(false);

                if ($reg->getFlag("potions") === "false") {
                    if (in_array($event->getItem()->getId(), self::POTIONS)) {
                        $player->sendMessage(TF::RED.'§2You cannot use §3'.$event->getItem()->getName().' §2in this area.');
                        $event->setCancelled();
                        return;
                    }
                } else $event->setCancelled(false);

                if ($reg->getFlag("editable") === "false") {
                    if (in_array($event->getItem()->getId(), self::OTHER)) {
                        $player->sendMessage(TF::RED.'§2You cannot use §3'.$event->getItem()->getName().'§2in this area.');
                        $event->setCancelled();
                        return;
                    }
                } else $event->setCancelled(false);

            }
            return;
        }
    }

    /**
     * @param BlockPlaceEvent $event
     * @ignoreCancelled true
     */
    public function onPlace(BlockPlaceEvent $event)
    {
        if (($region = $this->plugin->getRegionFromPosition($event->getBlock())) !== "") {
            if (!$region->isWhitelisted($player = $event->getPlayer())) {
                if ($region->getFlag("editable") === "false") {
                    $player->sendMessage(TF::RED.'§2You cannot edit this area.');
                    $event->setCancelled();
                }
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     * @ignoreCancelled true
     */
    public function onBreak(BlockBreakEvent $event)
    {
        if (($region = $this->plugin->getRegionFromPosition($event->getBlock())) !== "") {
            if (!$region->isWhitelisted($player = $event->getPlayer())) {
                if ($region->getFlag("editable") === "false") {
                    $player->sendMessage(TF::RED.'§2You cannot edit this area.');
                    $event->setCancelled();
                }
            }
        }
    }

    /**
    * @priority MONITOR
    */
    public function onMove(PlayerMoveEvent $event)
    {
        if (!$event->getFrom()->equals($event->getTo())) {
            if ($this->plugin->updateRegion($player = $event->getPlayer()) !== true) {
                $player->setMotion($event->getFrom()->subtract($player->getLocation())->normalize()->multiply(4));
            }
        }
    }

    /**
     * @param EntityDamageEvent $event
     * @ignoreCancelled true
     */
    public function onHurt(EntityDamageEvent $event)
    {
        if ($event->getEntity() instanceof Player && $event instanceof EntityDamageByEntityEvent) {
            if (($reg = $this->plugin->getRegionByPlayer($event->getEntity())) !== "") {
                if ($reg->getFlag("pvp") === "false" && $event->getDamager() instanceof Player) {
                    $event->getDamager()->sendMessage(TF::RED.'§2You cannot PvP in this area.');
                    $event->setCancelled();
                }
            }
        }
    }

    /**
     * @param PlayerCommandPreprocessEvent $event
     * @ignoreCancelled true
     */
    public function onCommand(PlayerCommandPreprocessEvent $event)
    {
        $cmd = explode(" ", $event->getMessage())[0];
        if (substr($cmd, 0, 1) === '/') {
            if (($region = $this->plugin->getRegionByPlayer($player = $event->getPlayer())) !== "" && !$region->isCommandAllowed($cmd)) {
                $player->sendMessage(TF::RED.'§2You cannot use §3'.$cmd.' §2in this area.');
                $event->setCancelled();
            }
        }
    }

    /**
     * @param PlayerDropItemEvent $event
     * @ignoreCancelled true
     */
    public function onDrop(PlayerDropItemEvent $event)
    {
        if (($reg = $this->plugin->getRegionByPlayer($player = $event->getPlayer())) !== "") {
            if (!$reg->isWhitelisted($player)) {
                if ($reg->getFlag("item-drop") === "false") {
                    $player->sendMessage(TF::RED.'§2You cannot drop items in this region.');
                    $event->setCancelled();
                    return;
                }
            }
        }
    }

    /**
     * @param EntityExplodeEvent $event
     * @ignoreCancelled true
     */
    public function onExplode(EntityExplodeEvent $event)
    {
        foreach ($event->getBlockList() as $block) {
            if (($region = $this->plugin->getRegionFromPosition($block)) !== "") {
                if ($region->getFlag("explosion") === "false") {
                    $event->setCancelled();
                    return;
                }
            }
        }
    }

    /**
     * @param PlayerBedEnterEvent $event
     * @ignoreCancelled true
     */
    public function onSleep(PlayerBedEnterEvent $event)
    {
        if (($region = $this->plugin->getRegionFromPosition($event->getBed())) !== "") {
            if (!$region->isWhitelisted($player = $event->getPlayer())) {
                if ($region->getFlag("sleep") === "false") {
                    $event->setCancelled();
                }
            }
        }
    }

    /**
     * @param PlayerChatEvent $event
     * @ignoreCancelled true
     */
    public function onChat(PlayerChatEvent $event)
    {
        if (($reg = $this->plugin->getRegionByPlayer($player = $event->getPlayer())) !== "") {
            if (!$reg->isWhitelisted($player)) {
                if ($reg->getFlag("send-chat") === "false") {
                    $player->sendMessage(TF::RED.'§2You cannot chat in this region.');
                    $event->setCancelled();
                    return;
                }
            }
        }
        if (!empty($this->plugin->muted)) {
            $diff = array_diff($this->plugin->getServer()->getOnlinePlayers(), $this->plugin->muted);
            if (!in_array($player, $diff)) {
                $diff[] = $player;
            }
            $event->setRecipients($diff);
        }
    }

    /**
     * @param ProjectileLaunchEvent $event
     * @ignoreCancelled true
     */
    public function onEnderpearl(ProjectileLaunchEvent $event)
    {
        if ($event->getEntity()::NETWORK_ID !== 87) return;
        if (($region = $this->plugin->getRegionFromPosition($entity = $event->getEntity())) !== "") {
            if ($region->getFlag("enderpearl") === "false") {
                if ((($player = $entity->shootingEntity) !== null) && !$region->isWhitelisted($player)) {
                    $event->setCancelled();
                    $player->sendMessage(TF::RED.'§2You cannot use ender pearls in this area.');
                }
            }
        }
    }
}
