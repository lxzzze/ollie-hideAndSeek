<?php
/**
 * Created by PhpStorm.
 * User: Next
 * Date: 2019/3/17
 * Time: 16:59
 */

namespace App;


use App\Manager\DataCenter;
use App\Manager\Logic;
use App\Manager\Sender;
use App\Manager\TaskManager;

require_once __DIR__ . '/../vendor/autoload.php';

class Server
{
    //定义事件类型枚举
    const CLIENT_CODE_MATCH_PLAYER = 600;
    const CLIENT_CODE_START_ROOM = 601;
    const CLIENT_CODE_MOVE_PLAYER = 602;
    const CLIENT_CODE_MAKE_CHALLENGE = 603;
    const CLIENT_CODE_ACCEPT_CHALLENGE = 604;
    const CLIENT_CODE_REFUSE_CHALLENGE = 605;
    //当前ip
    const HOST = '0.0.0.0';
    //websocket端口
    const PORT = 8811;
    //前端端口
    const FRONT_PORT = 8812;
    //websocket配置
    const CONFIG = [
        'worker_num' => 4,
        'task_worker_num' => 4,
        'dispatch_mode' => 5,
        'enable_static_handler' => true,
        'document_root' =>
            '/Users/tengtengcai/sites/ollie-hideAndSeek/frontend',
    ];

    private $ws;
    private $logic;

    //初始化事件
    public function __construct()
    {
        $this->logic = new Logic();
        $this->ws = new \Swoole\WebSocket\Server(self::HOST, self::PORT);
        $this->ws->listen(self::HOST, self::FRONT_PORT, SWOOLE_SOCK_TCP);
        $this->ws->set(self::CONFIG);
        $this->ws->on('start', [$this, 'onStart']);
        $this->ws->on('workerStart', [$this, 'onWorkerStart']);
        $this->ws->on('open', [$this, 'onOpen']);
        $this->ws->on('message', [$this, 'onMessage']);
        $this->ws->on('close', [$this, 'onClose']);
        $this->ws->on('task', [$this, 'onTask']);
        $this->ws->on('finish', [$this, 'onFinish']);
        $this->ws->on('request', [$this, 'onRequest']);
        $this->ws->start();
    }

    //程序运行时触发事件
    public function onStart($server)
    {
        swoole_set_process_name('hide-and-seek');
        DataCenter::log(
            sprintf(
                'master start (listening on %s:%d)',
                self::HOST,
                self::PORT
            ));
        //初始化数据
        DataCenter::initDataCenter();
    }

    //程序运行时触发
    public function onWorkerStart($server, $workerId)
    {
        echo "server: onWorkStart,worker_id:{$server->worker_id}\n";
        //将server绑定到DataCenter类中，方便后续推送消息
        DataCenter::$server = $server;
    }

    /**
     * websocket开启时触发事件
     * @param $server \swoole_websocket_server
     * @param $request
     */
    public function onOpen($server, $request)
    {
        DataCenter::log(sprintf('client open fd：%d', $request->fd));

        $playerId = $request->get['player_id'];
        //查看用户是否已在线,若无则设置为在线
        if (empty(DataCenter::getOnlinePlayer($playerId))) {
            DataCenter::setPlayerInfo($playerId, $request->fd);
        } else {
            $server->disconnect($request->fd, 4000, '该player_id已在线');
        }
    }

    //接收到客户端信息时触发事件
    public function onMessage($server, $request)
    {
        DataCenter::log(sprintf('client open fd：%d，message：%s', $request->fd, $request->data));

        $requestData = json_decode($request->data, true);
        //获取用户id
        $playerId = DataCenter::getPlayerId($request->fd);
        //根据不同事件触发不同动作
        switch ($requestData['code']) {
            //匹配玩家
            case self::CLIENT_CODE_MATCH_PLAYER:
                $this->logic->matchPlayer($playerId);
                break;
            //匹配成功,获取游戏地图数据
            case self::CLIENT_CODE_START_ROOM:
                $this->logic->startRoom($requestData['room_id'], $playerId);
                break;
             //移动位置
            case self::CLIENT_CODE_MOVE_PLAYER:
                $this->logic->movePlayer($requestData['direction'], $playerId);
                break;
            //挑战指定用户
            case self::CLIENT_CODE_MAKE_CHALLENGE:
                $this->logic->makeChallenge($requestData['opponent_id'], $playerId);
                break;
            //接收用户挑战
            case self::CLIENT_CODE_ACCEPT_CHALLENGE:
                $this->logic->acceptChallenge($requestData['challenger_id'], $playerId);
                break;
            //拒绝用户挑战
            case self::CLIENT_CODE_REFUSE_CHALLENGE:
                $this->logic->refuseChallenge($requestData['challenger_id']);
                break;
        }
        //数据发送成功
        Sender::sendMessage($playerId, Sender::MSG_SUCCESS);
    }

    //websocket连接关闭时触发事件
    public function onClose($server, $fd)
    {
        DataCenter::log(sprintf('client close fd：%d', $fd));
        $this->logic->closeRoom(DataCenter::getPlayerId($fd));
        //删除该fd对应的信息
        DataCenter::delPlayerInfo($fd);
    }

    //处理异步任务(此回调函数在task进程中执行)程序立即返回,onTask回调函数Task进程池内被异步执行,提高响应
    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        DataCenter::log("onTask", $data);
        $result = [];
        switch ($data['code']) {
            case TaskManager::TASK_CODE_FIND_PLAYER:
                $ret = TaskManager::findPlayer();
                if (!empty($ret)) {
                    $result['data'] = $ret;
                }
                break;
        }
        if (!empty($result)) {
            $result['code'] = $data['code'];
            return $result;
        }
    }

    //onTask任务处理完成后,将结果返回并触发该方法
    public function onFinish($server, $taskId, $data)
    {
        DataCenter::log("onFinish", $data);
        switch ($data['code']) {
            case TaskManager::TASK_CODE_FIND_PLAYER:
                //创建房间
                $this->logic->createRoom($data['data']['red_player'], $data['data']['blue_player']);
                break;
        }
    }

    /**
     * 接收http请求信息并触发该方法
     * @param $request \swoole_http_request
     * @param $response \swoole_http_response
     */
    public function onRequest($request, $response)
    {
        DataCenter::log("onRequest");
        $action = $request->get['a'];
        if ($action == 'get_online_player') {
            //返回在线人数
            $data = [
                'online_player' => DataCenter::lenOnlinePlayer()
            ];
            $response->end(json_encode($data));
        } elseif ($action == 'get_player_rank') {
            //返回排行榜信息
            $data = [
                'players_rank' => DataCenter::getPlayersRank()
            ];
            $response->end(json_encode($data));
        }
    }
}

new Server();