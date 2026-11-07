## 1.0.0

- 基于 GatewayWorker 封装 ThinkPHP8 WebSocket 即时通讯扩展
- 支持 Linux 下 `php think workerman` 单命令启动全部进程
- 支持 Windows 下 register、business、gateway 分进程启动
- 支持客户端连接下发 client_id、绑定 member_id、心跳检测
- 支持 wss 及证书配置
- 自动发布 `config/workerman.php` 配置文件