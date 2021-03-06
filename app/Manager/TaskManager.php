<?php
/**
 * Created by PhpStorm.
 * User: Next
 * Date: 2019/3/24
 * Time: 11:50
 */

namespace App\Manager;


class TaskManager
{
    const TASK_CODE_FIND_PLAYER = 1;

    //匹配玩家
    public static function findPlayer()
    {
        //获取当前匹配玩家人数
        $playerListLen = DataCenter::getPlayerWaitListLen();
        if ($playerListLen >= 2) {
            //匹配成功返回
            $redPlayer = DataCenter::popPlayerFromWaitList();
            $bluePlayer = DataCenter::popPlayerFromWaitList();
            return [
                'red_player' => $redPlayer,
                'blue_player' => $bluePlayer
            ];
        }
        return false;
    }
}