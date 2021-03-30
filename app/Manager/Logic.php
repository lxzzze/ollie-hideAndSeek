<?php

namespace App\Manager;

use App\Model\Player;

//游戏逻辑管理类
class Logic
{
    const PLAYER_DISPLAY_LEN = 2;

    const GAME_TIME_LIMIT = 10;

    public function acceptChallenge($challengerId, $playerId)
    {
        $this->createRoom($challengerId, $playerId);
    }

    public function refuseChallenge($challengerId)
    {
        Sender::sendMessage($challengerId, Sender::MSG_REFUSE_CHALLENGE);
    }

    public function makeChallenge($opponentId, $playerId)
    {
        if (empty(DataCenter::getOnlinePlayer($opponentId))) {
            Sender::sendMessage($playerId, Sender::MSG_OPPONENT_OFFLINE);
        } else {
            $data = [
                'challenger_id' => $playerId
            ];
            Sender::sendMessage($opponentId, Sender::MSG_MAKE_CHALLENGE, $data);
        }
    }

    //匹配玩家
    public function matchPlayer($playerId)
    {
        //将用户放入队列中
        DataCenter::pushPlayerToWaitList($playerId);
        //发起一个Task尝试匹配
        DataCenter::$server->task(['code' => TaskManager::TASK_CODE_FIND_PLAYER]);
    }

    //移动玩家
    public function movePlayer($direction, $playerId)
    {
        //验证方向是否符合规范
        if (!in_array($direction, Player::DIRECTION)) {
            echo $direction;
            return;
        }
        //获取房间号
        $roomId = DataCenter::getPlayerRoomId($playerId);
        //确认房间存在
        if (isset(DataCenter::$global['rooms'][$roomId])) {
            /**
             * @var Game $gameManager
             */
            $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
            //移动角色
            $gameManager->playerMove($playerId, $direction);
            //发送当前游戏信息
            $this->sendGameInfo($roomId);
            //检查游戏是否结束
            $this->checkGameOver($roomId);
        }
    }

    public function createRoom($redPlayer, $bluePlayer)
    {
        $roomId = uniqid('room_');
        $this->bindRoomWorker($redPlayer, $roomId);
        $this->bindRoomWorker($bluePlayer, $roomId);
    }

    public function closeRoom($closerId)
    {
        $roomId = DataCenter::getPlayerRoomId($closerId);
        if (!empty($roomId)) {
            /**
             * @var Game $gameManager
             * @var Player $player
             */
            $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
            $players = $gameManager->getPlayers();
            foreach ($players as $player) {
                if ($player->getId() != $closerId) {
                    Sender::sendMessage($player->getId(), Sender::MSG_OTHER_CLOSE);
                }
                DataCenter::delPlayerRoomId($player->getId());
            }
            unset(DataCenter::$global['rooms'][$roomId]);
        }
    }

    //匹配成功,创建房间
    public function startRoom($roomId, $playerId)
    {
        //判断是否存在房间信息,若不存在则添加
        if (!isset(DataCenter::$global['rooms'][$roomId])) {
            DataCenter::$global['rooms'][$roomId] = [
                'id' => $roomId,
                'manager' => new Game()
            ];
        }
        /**
         * @var Game $gameManager
         */
        $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
        if (empty(count($gameManager->getPlayers()))) {
            //第一个玩家
            $gameManager->createPlayer($playerId, 6, 1);
            Sender::sendMessage($playerId, Sender::MSG_WAIT_PLAYER);
        } else {
            //第二个玩家
            $gameManager->createPlayer($playerId, 6, 10);
            DataCenter::$global['rooms'][$roomId]['timer_id'] = $this->createGameTimer($roomId);
            Sender::sendMessage($playerId, Sender::MSG_ROOM_START);
            $this->sendGameInfo($roomId);
        }
    }

    //初始化游戏时间
    private function createGameTimer($roomId)
    {
        return swoole_timer_after(self::GAME_TIME_LIMIT * 1000, function () use ($roomId) {
            if (isset(DataCenter::$global['rooms'][$roomId])) {
                //游戏还未结束则主动结束游戏
                /**
                 * @var Game $gameManager
                 */
                $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
                $players = $gameManager->getPlayers();
                $winner = end($players)->getId();
                $this->gameOver($roomId, $winner);
            }
        });
    }

    //检查游戏是否结束
    private function checkGameOver($roomId)
    {
        /**
         * @var Game $gameManager
         * @var Player $player
         */
        $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
        //游戏是否结束
        if ($gameManager->isGameOver()) {
            $players = $gameManager->getPlayers();
            //获取胜利玩家
            $winner = current($players)->getId();
            $this->gameOver($roomId, $winner);
        }
    }

    //游戏结束
    private function gameOver($roomId, $winner)
    {
        /**
         * @var Game $gameManager
         * @var Player $player
         */
        $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
        //获取房间内的用户id
        $players = $gameManager->getPlayers();
        //新增用户id获胜记录次数
        DataCenter::addPlayerWinTimes($winner);
        foreach ($players as $player) {
            //发送游戏结束信息
            Sender::sendMessage($player->getId(), Sender::MSG_GAME_OVER, ['winner' => $winner]);
            //删除房间信息
            DataCenter::delPlayerRoomId($player->getId());
        }
        //删除全局变量中的房间信息
        unset(DataCenter::$global['rooms'][$roomId]);
    }

    //发送游戏信息(地图,玩家,游戏时间)
    private function sendGameInfo($roomId)
    {
        /**
         * @var Game $gameManager
         * @var Player $player
         */
        $gameManager = DataCenter::$global['rooms'][$roomId]['manager'];
        $players = $gameManager->getPlayers();
        $mapData = $gameManager->getMapData();
        //必须倒序输出，因为游戏设定数组第一个是寻找者，第二个是躲藏者，叠加时赢的是寻找者。
        foreach (array_reverse($players) as $player) {
            $mapData[$player->getX()][$player->getY()] = $player->getId();
        }
        foreach ($players as $player) {
            $data = [
                'players' => $players,
                'map_data' => $this->getNearMap($mapData, $player->getX(), $player->getY()),
                'time_limit' => self::GAME_TIME_LIMIT
            ];
            Sender::sendMessage($player->getId(), Sender::MSG_GAME_INFO, $data);
        }
    }

    private function getNearMap($mapData, $x, $y)
    {
        $result = [];
        for ($i = -1 * self::PLAYER_DISPLAY_LEN; $i <= self::PLAYER_DISPLAY_LEN; $i++) {
            $tmp = [];
            for ($j = -1 * self::PLAYER_DISPLAY_LEN; $j <= self::PLAYER_DISPLAY_LEN; $j++) {
                $tmp[] = $mapData[$x + $i][$y + $j] ?? 0;
            }
            $result[] = $tmp;
        }
        return $result;
    }

    /**
     * 绑定同一个room的player到某个worker进程中，内存共享
     * @param $playerId
     * @param $roomId
     */
    private function bindRoomWorker($playerId, $roomId)
    {
        $playerFd = DataCenter::getPlayerFd($playerId);
        DataCenter::$server->bind($playerFd, crc32($roomId));
        DataCenter::setPlayerRoomId($playerId, $roomId);
        Sender::sendMessage($playerId, Sender::MSG_ROOM_ID, ['room_id' => $roomId]);
    }
}