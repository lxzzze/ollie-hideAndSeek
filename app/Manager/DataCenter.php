<?php
/**
 * Created by PhpStorm.
 * User: Next
 * Date: 2019/3/17
 * Time: 17:13
 */

namespace App\Manager;


use App\Lib\Redis;

//数据中心,主要将数据存储在redis中
class DataCenter
{
    const PREFIX_KEY = "game";

    public static $server;
    public static $global;

    public static function redis()
    {
        return Redis::getInstance();
    }

    /*
     * 排行榜列表操作
     */


    public static function addPlayerWinTimes($playerId)
    {
        $key = self::PREFIX_KEY . ':player_rank';
        self::redis()->zIncrBy($key, 1, $playerId);
    }

    public static function getPlayersRank()
    {
        $key = self::PREFIX_KEY . ':player_rank';
        return self::redis()->zRevRange($key, 0, 9, true);
    }


    /*
     * 在线列表操作
     */

    //将用户id设置为在线
    public static function setOnlinePlayer($playerId)
    {
        $key = self::PREFIX_KEY . ':online_player';
        self::redis()->hSet($key, $playerId, 1);
    }

    //根据用户id获取是否在线
    public static function getOnlinePlayer($playerId)
    {
        $key = self::PREFIX_KEY . ':online_player';
        return self::redis()->hGet($key, $playerId);
    }

    //根据用户id删除在线列表中的值
    public static function delOnlinePlayer($playerId)
    {
        $key = self::PREFIX_KEY . ':online_player';
        self::redis()->hDel($key, $playerId);
    }

    //统计在线人数
    public static function lenOnlinePlayer()
    {
        $key = self::PREFIX_KEY . ':online_player';
        return self::redis()->hLen($key);
    }


    /*
     * 用户信息操作,存储fd与用户id对应信息
     */


    //设置房间信息
    public static function setPlayerRoomId($playerId, $roomId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'room_id:' . $playerId;
        self::redis()->hSet($key, $field, $roomId);
    }

    //获取房间信息
    public static function getPlayerRoomId($playerId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'room_id:' . $playerId;
        return self::redis()->hGet($key, $field);
    }

    //删除房间信息
    public static function delPlayerRoomId($playerId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'room_id:' . $playerId;
        self::redis()->hDel($key, $field);
    }

    //根据用户id获取fd
    public static function getPlayerFd($playerId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_fd:' . $playerId;
        return self::redis()->hGet($key, $field);
    }

    //设置根据fd可查询到用户id
    public static function setPlayerFd($playerId, $playerFd)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_fd:' . $playerId;
        self::redis()->hSet($key, $field, $playerFd);
    }

    //删除列表中fd信息
    public static function delPlayerFd($playerId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_fd:' . $playerId;
        self::redis()->hDel($key, $field);
    }

    //根据用户fd获取用户ID
    public static function getPlayerId($playerFd)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_id:' . $playerFd;
        return self::redis()->hGet($key, $field);
    }

    //设置根据用户id可查询到fd
    public static function setPlayerId($playerFd, $playerId)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_id:' . $playerFd;
        self::redis()->hSet($key, $field, $playerId);
    }

    //根据用户id删除用户fd
    public static function delPlayerId($playerFd)
    {
        $key = self::PREFIX_KEY . ':player_info';
        $field = 'player_id:' . $playerFd;
        self::redis()->hDel($key, $field);
    }

    /*
     * 用户在线匹配列表操作
     */

    //获取当前匹配玩家人数
    public static function getPlayerWaitListLen()
    {
        $key = self::PREFIX_KEY . ":player_wait_list";
        return self::redis()->sCard($key);
    }

    //将玩家id添加到匹配在线列表
    public static function pushPlayerToWaitList($playerId)
    {
        $key = self::PREFIX_KEY . ":player_wait_list";
        self::redis()->sAdd($key, $playerId);
    }

    //随机获取一名正在匹配的玩家
    public static function popPlayerFromWaitList()
    {
        $key = self::PREFIX_KEY . ":player_wait_list";
        return self::redis()->sPop($key);
    }
    //根据用户id删除匹配列表中的值
    public static function delPlayerFromWaitList($playerId)
    {
        $key = self::PREFIX_KEY . ":player_wait_list";
        self::redis()->sRem($key, $playerId);
    }

    //设置用户id与fd对应关系
    public static function setPlayerInfo($playerId, $playerFd)
    {
        self::setPlayerId($playerFd, $playerId);
        self::setPlayerFd($playerId, $playerFd);
        self::setOnlinePlayer($playerId);
    }

    //根据fd删除对应信息
    public static function delPlayerInfo($playerFd)
    {
        $playerId = self::getPlayerId($playerFd);
        self::delPlayerFd($playerId);
        self::delPlayerId($playerFd);
        self::delOnlinePlayer($playerId);
        self::delPlayerFromWaitList($playerId);
    }

    public static function cleanRoomData($roomId)
    {
        if (isset(self::$global['rooms'][$roomId])) {
            unset(self::$global['rooms'][$roomId]);
        }
    }

    //初始化数据
    public static function initDataCenter()
    {
        //清空匹配队列
        $key = self::PREFIX_KEY . ':player_wait_list';
        self::redis()->del($key);
        //清空在线玩家
        $key = self::PREFIX_KEY . ':online_player';
        self::redis()->del($key);
        //清空玩家信息
        $key = self::PREFIX_KEY . ':player_info';
        self::redis()->del($key);
    }

    //输出日志信息
    public static function log($info, $context = [], $level = 'INFO')
    {
        if ($context) {
            echo sprintf("[%s][%s]: %s %s\n", date('Y-m-d H:i:s'), $level, $info, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            echo sprintf("[%s][%s]: %s\n", date('Y-m-d H:i:s'), $level, $info);
        }
    }
}