<?php
declare(strict_types = 1);

namespace youvision\tests;

use PHPUnit\Framework\TestCase;
use youvision\Identity;
use youvision\support\Auth;
use youvision\tests\fixtures\FakeGateway;
use youvision\tests\fixtures\TestEvents;
use youvision\WorkermanEvents;

class WorkermanEventsTest extends TestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        $this->gw = new FakeGateway();
        WorkermanEvents::useGateway($this->gw);
        TestEvents::reset();
        $_SESSION = [];

        // good → 不过期；exp:<ts> → 指定过期；other → 另一个 uid；其余拒绝
        Auth::using(static function (string $token): ?Identity {
            if ($token === 'good') {
                return new Identity('1001', ['device_id' => 'A']);
            }
            if (str_starts_with($token, 'exp:')) {
                return new Identity('1001', [], (int) substr($token, 4));
            }
            if ($token === 'other') {
                return new Identity('2002');
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        WorkermanEvents::useGateway(null);
        Auth::using(null);
        $_SESSION = [];
    }

    private function send(array $msg, string $client = 'c1'): void
    {
        TestEvents::onMessage($client, json_encode($msg));
    }

    // ------------------------------------------------------------ 连接与超时

    public function testConnectSendsWelcomeAndSchedulesTimeout(): void
    {
        TestEvents::$config['auth_timeout'] = 5;
        TestEvents::onConnect('c1');

        $this->assertSame(['type' => 'welcome', 'data' => ['client_id' => 'c1']], $this->gw->last());
        $this->assertCount(1, TestEvents::$timers);
        $this->assertSame(5.0, (float) TestEvents::$timers[0][0]);
    }

    public function testAuthTimeoutClosesUnauthedClient(): void
    {
        TestEvents::onConnect('c1');
        TestEvents::fireTimers();

        $this->assertSame(['reason' => 'timeout'], $this->gw->last()['data']);
        $this->assertArrayHasKey('c1', $this->gw->closed);
    }

    public function testAuthTimeoutKeepsAuthedClient(): void
    {
        TestEvents::onConnect('c1');
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);
        TestEvents::fireTimers();

        $this->assertArrayNotHasKey('c1', $this->gw->closed);
    }

    public function testAuthTimeoutDisabled(): void
    {
        TestEvents::$config['auth_timeout'] = 0;
        TestEvents::onConnect('c1');

        $this->assertSame([], TestEvents::$timers);
    }

    // ------------------------------------------------------------ 鉴权

    public function testAuthByMessage(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'good'], 'msg_id' => 'm1']);

        $this->assertSame('1001', $this->gw->bound['c1']);
        $this->assertSame('1001', $_SESSION['uid']);
        $this->assertSame('A', $_SESSION['device_id']);
        $this->assertSame(
            ['type' => 'auth_ok', 'data' => ['uid' => '1001', 'expires_at' => null], 'msg_id' => 'm1'],
            $this->gw->last(),
        );
        $this->assertSame([['authed', '1001']], TestEvents::$hooks);
    }

    public function testAuthByHeader(): void
    {
        TestEvents::onWebSocketConnect('c1', ['server' => ['HTTP_AUTHORIZATION' => 'Bearer good']]);

        $this->assertSame('1001', $this->gw->bound['c1']);
        $this->assertSame('auth_ok', $this->gw->last()['type']);
    }

    public function testHeaderWithoutBearerIsIgnored(): void
    {
        TestEvents::onWebSocketConnect('c1', ['server' => ['HTTP_AUTHORIZATION' => 'Basic xxx']]);
        TestEvents::onWebSocketConnect('c1', ['server' => []]);

        $this->assertSame([], $this->gw->sent);
        $this->assertSame([], $this->gw->closed);
    }

    public function testInvalidTokenClosesConnection(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'bad'], 'msg_id' => 'm2']);

        $this->assertSame(['type' => 'auth_fail', 'data' => ['reason' => 'invalid_token'], 'msg_id' => 'm2'], $this->gw->last());
        $this->assertArrayHasKey('c1', $this->gw->closed);
        $this->assertSame([], $this->gw->bound);
        $this->assertSame([], TestEvents::$hooks);
    }

    public function testEmptyTokenRejected(): void
    {
        $this->send(['type' => 'auth', 'data' => []]);

        $this->assertSame('invalid_token', $this->gw->last()['data']['reason']);
    }

    public function testAlreadyExpiredTokenRejected(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . (time() - 10)]]);

        $this->assertSame('expired', $this->gw->last()['data']['reason']);
        $this->assertArrayHasKey('c1', $this->gw->closed);
        $this->assertSame([], $this->gw->bound);
    }

    public function testAuthWithoutAuthenticatorRejected(): void
    {
        Auth::using(null);
        // 无 ThinkPHP 环境时 config() 不可用，Auth 视为未配置
        if (function_exists('config')) {
            $this->markTestSkipped('ThinkPHP config() 可用，跳过');
        }
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);

        $this->assertSame('invalid_token', $this->gw->last()['data']['reason']);
    }

    // ------------------------------------------------------------ 续期

    public function testReauthWithoutTokenConfirms(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);
        $this->send(['type' => 'auth', 'data' => [], 'msg_id' => 'm3']);

        $this->assertSame(['type' => 'auth_ok', 'data' => ['uid' => '1001'], 'msg_id' => 'm3'], $this->gw->last());
        $this->assertSame([['authed', '1001']], TestEvents::$hooks, 'onAuthed 只调用一次');
    }

    public function testRefreshExtendsExpiry(): void
    {
        $soon = time() + 60;
        $later = time() + 3600;

        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . $soon]]);
        $this->assertSame($soon, $_SESSION['_exp']);

        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . $later]]);
        $this->assertSame($later, $_SESSION['_exp']);
        $this->assertTrue($this->gw->last()['data']['refreshed']);
        $this->assertSame($later, $this->gw->last()['data']['expires_at']);
        $this->assertSame([['authed', '1001']], TestEvents::$hooks, '续期不重复触发 onAuthed');
    }

    public function testRefreshWithDifferentUidRejectedButKeepsConnection(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);
        $this->send(['type' => 'auth', 'data' => ['token' => 'other']]);

        $this->assertSame('uid_mismatch', $this->gw->last()['data']['reason']);
        $this->assertArrayNotHasKey('c1', $this->gw->closed);
        $this->assertSame('1001', $_SESSION['uid']);
    }

    public function testRefreshWithInvalidTokenCloses(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);
        $this->send(['type' => 'auth', 'data' => ['token' => 'bad']]);

        $this->assertSame('invalid_token', $this->gw->last()['data']['reason']);
        $this->assertArrayHasKey('c1', $this->gw->closed);
    }

    // ------------------------------------------------------------ 过期

    public function testExpireTimerScheduled(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . (time() + 120)]]);

        $this->assertCount(1, TestEvents::$timers);
        $this->assertGreaterThanOrEqual(119, TestEvents::$timers[0][0]);
    }

    public function testNoExpireTimerWithoutExpiresAt(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'good']]);

        $this->assertSame([], TestEvents::$timers);
    }

    public function testExpireTimerClosesWhenStillExpired(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . (time() + 1)]]);
        $this->gw->sessions['c1'] = ['uid' => '1001', '_exp' => time() - 1];

        TestEvents::fireTimers();

        $this->assertSame(['type' => 'auth_fail', 'data' => ['reason' => 'expired']], $this->gw->closed['c1']);
    }

    public function testExpireTimerSkipsWhenRefreshed(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . (time() + 1)]]);
        $this->gw->sessions['c1'] = ['uid' => '1001', '_exp' => time() + 3600];

        TestEvents::fireTimers();

        $this->assertArrayNotHasKey('c1', $this->gw->closed);
    }

    public function testExpireTimerSkipsOfflineClient(): void
    {
        $this->send(['type' => 'auth', 'data' => ['token' => 'exp:' . (time() + 1)]]);
        $this->gw->online['c1'] = false;
        $this->gw->sessions['c1'] = ['uid' => '1001', '_exp' => time() - 1];

        TestEvents::fireTimers();

        $this->assertArrayNotHasKey('c1', $this->gw->closed);
    }

    public function testMessageAfterExpiryCloses(): void
    {
        $_SESSION = ['uid' => '1001', '_exp' => time() - 1];
        $this->send(['type' => 'chat', 'data' => ['text' => 'hi']]);

        $this->assertSame('expired', $this->gw->closed['c1']['data']['reason']);
        $this->assertSame([], TestEvents::$hooks);
    }

    // ------------------------------------------------------------ 业务消息

    public function testUnauthedBusinessMessageIgnored(): void
    {
        $this->send(['type' => 'chat', 'data' => ['text' => 'hi']]);

        $this->assertSame([], TestEvents::$hooks);
        $this->assertSame([], $this->gw->sent);
    }

    public function testAuthedMessageDispatchedWithMsgId(): void
    {
        $_SESSION = ['uid' => '1001'];
        $this->send(['type' => 'chat', 'data' => ['text' => 'hi'], 'msg_id' => 'm9']);

        $this->assertSame([['message', ['chat', ['text' => 'hi'], 'm9']]], TestEvents::$hooks);
    }

    public function testReplyCarriesMsgId(): void
    {
        $_SESSION = ['uid' => '1001'];
        $this->send(['type' => 'echo', 'data' => ['n' => 1], 'msg_id' => 'e1']);

        $this->assertSame(['type' => 'echo_ok', 'data' => ['n' => 1], 'msg_id' => 'e1'], $this->gw->last());
    }

    public function testPongAndInvalidMessagesIgnored(): void
    {
        $_SESSION = ['uid' => '1001'];
        $this->send(['type' => 'pong']);
        TestEvents::onMessage('c1', 'not json');
        TestEvents::onMessage('c1', '[1,2]');

        $this->assertSame([], TestEvents::$hooks);
        $this->assertSame([], $this->gw->sent);
    }

    // ------------------------------------------------------------ 断开

    public function testCloseCallsOnDisconnectWithSession(): void
    {
        $_SESSION = ['uid' => '1001', 'device_id' => 'A'];
        TestEvents::onClose('c1');

        $this->assertSame([['disconnect', ['uid' => '1001', 'device_id' => 'A']]], TestEvents::$hooks);
    }
}
