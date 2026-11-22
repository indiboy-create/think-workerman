<?php
declare(strict_types = 1);

namespace youvision\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use youvision\Message;
use youvision\support\Router;
use youvision\tests\fixtures\BoomHandler;
use youvision\tests\fixtures\ChatHandlers;
use youvision\tests\fixtures\EchoHandler;
use youvision\tests\fixtures\FakeGateway;
use youvision\tests\fixtures\NotAHandler;
use youvision\tests\fixtures\RouterEvents;
use youvision\WorkermanEvents;

class RouterTest extends TestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        $this->gw = new FakeGateway();
        WorkermanEvents::useGateway($this->gw);
        RouterEvents::$config = [];
        $_SESSION = ['uid' => '1001'];
    }

    protected function tearDown(): void
    {
        Router::using(null);
        WorkermanEvents::useGateway(null);
        $_SESSION = [];
    }

    private function send(array $msg): void
    {
        RouterEvents::onMessage('c1', json_encode($msg));
    }

    // ------------------------------------------------------------ 匹配

    public function testMatchExactBeforeWildcard(): void
    {
        Router::using([
            'sync.*' => EchoHandler::class,
            'sync.pull.*' => EchoHandler::class,
            'sync.ack' => EchoHandler::class,
            'chat' => EchoHandler::class,
        ]);

        $this->assertSame('sync.ack', Router::match('sync.ack'));
        $this->assertSame('sync.*', Router::match('sync.notify'));
        $this->assertSame('sync.pull.*', Router::match('sync.pull.full'), '最长前缀优先');
        $this->assertNull(Router::match('sync'), '"sync.*" 不匹配 "sync" 本身');
        $this->assertNull(Router::match('syncx.a'));
        $this->assertNull(Router::match('chat.send'), '无通配时不做前缀匹配');
    }

    public function testRouteKeysAreCaseInsensitive(): void
    {
        Router::using(['Chat.Send' => EchoHandler::class]);

        $this->assertSame('chat.send', Router::match('chat.send'));
    }

    // ------------------------------------------------------------ 处理器形式

    public function testClassHandlerWithReplyAndMsgId(): void
    {
        Router::using(['echo' => EchoHandler::class]);
        $this->send(['type' => 'echo', 'data' => ['n' => 1], 'msg_id' => 'm1']);

        $this->assertSame(['type' => 'echo_ok', 'data' => ['uid' => '1001', 'n' => 1], 'msg_id' => 'm1'], $this->gw->last());
    }

    public function testStaticMethodHandler(): void
    {
        Router::using(['chat.read' => [ChatHandlers::class, 'read']]);
        $this->send(['type' => 'chat.read', 'data' => ['id' => 7]]);

        $this->assertSame(['type' => 'chat.read_ok', 'data' => ['id' => 7]], $this->gw->last());
    }

    public function testInstanceMethodHandler(): void
    {
        Router::using(['chat.send' => [ChatHandlers::class, 'send']]);
        $this->send(['type' => 'chat.send']);

        $this->assertSame('chat.send_ok', $this->gw->last()['type']);
    }

    public function testClosureHandler(): void
    {
        $got = null;
        Router::using(['ping.app' => static function (Message $m) use (&$got): void {
            $got = [$m->clientId, $m->type, $m->uid()];
        }]);
        $this->send(['type' => 'ping.app']);

        $this->assertSame(['c1', 'ping.app', '1001'], $got);
    }

    public function testWildcardHandlerReceivesFullType(): void
    {
        $got = null;
        Router::using(['sync.*' => static function (Message $m) use (&$got): void {
            $got = $m->type;
        }]);
        $this->send(['type' => 'sync.ack']);

        $this->assertSame('sync.ack', $got);
    }

    public function testHandlerInstanceIsReused(): void
    {
        Router::using(['echo' => EchoHandler::class]);
        $this->send(['type' => 'echo']);
        $this->send(['type' => 'echo']);

        $this->assertCount(2, $this->gw->sent);
    }

    // ------------------------------------------------------------ 未匹配与异常

    public function testUnknownTypeRepliesError(): void
    {
        Router::using([]);
        $this->send(['type' => 'nope', 'msg_id' => 'x']);

        $this->assertSame(
            ['type' => 'error', 'data' => ['reason' => 'unknown_type', 'type' => 'nope'], 'msg_id' => 'x'],
            $this->gw->last(),
        );
    }

    public function testUnknownTypeReplyDisabled(): void
    {
        Router::using([]);
        RouterEvents::$config['route_unknown_reply'] = false;
        $this->send(['type' => 'nope']);

        $this->assertSame([], $this->gw->sent);
    }

    public function testHandlerExceptionRepliesErrorAndKeepsConnection(): void
    {
        Router::using(['boom' => BoomHandler::class]);
        $this->send(['type' => 'boom', 'msg_id' => 'b1']);

        $this->assertSame(
            ['type' => 'error', 'data' => ['reason' => 'handler_error', 'type' => 'boom'], 'msg_id' => 'b1'],
            $this->gw->last(),
        );
        $this->assertSame([], $this->gw->closed);
    }

    public function testUnauthedMessageNotRouted(): void
    {
        $_SESSION = [];
        $called = false;
        Router::using(['echo' => static function () use (&$called): void {
            $called = true;
        }]);
        $this->send(['type' => 'echo']);

        $this->assertFalse($called);
        $this->assertSame([], $this->gw->sent);
    }

    // ------------------------------------------------------------ 启动校验

    public function testCheckCountsValidRoutes(): void
    {
        Router::using([
            'a' => EchoHandler::class,
            'b' => [ChatHandlers::class, 'read'],
            'c' => static fn () => null,
        ]);

        $this->assertSame(3, Router::check());
    }

    #[DataProvider('badRoutes')]
    public function testCheckRejectsBadRoutes(mixed $target, string $needle): void
    {
        Router::using(['bad' => $target]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');
        Router::check();
    }

    public static function badRoutes(): array
    {
        return [
            'not handler' => [NotAHandler::class, '必须实现'],
            'missing class' => [['App\\Missing', 'x'], '不存在'],
            'missing method' => [[ChatHandlers::class, 'nope'], '不存在'],
            'bad value' => [123, '处理器必须是'],
            'unknown string' => ['App\\Nope', '处理器必须是'],
        ];
    }

    // ------------------------------------------------------------ Message

    public function testMessageHelpers(): void
    {
        $m = new Message('c9', 'chat', ['a' => 1], 'id1', ['uid' => '7'], $this->gw);

        $this->assertSame('7', $m->uid());
        $this->assertSame(1, $m->get('a'));
        $this->assertSame('d', $m->get('missing', 'd'));

        $m->error('bad_input', ['field' => 'a']);
        $this->assertSame(
            ['type' => 'error', 'data' => ['reason' => 'bad_input', 'type' => 'chat', 'field' => 'a'], 'msg_id' => 'id1'],
            $this->gw->last('c9'),
        );
    }
}
