<?php
declare(strict_types = 1);

namespace youvision\tests;

use PHPUnit\Framework\TestCase;
use youvision\Identity;
use youvision\support\Auth;
use youvision\support\Protocol;

class ProtocolTest extends TestCase
{
    protected function tearDown(): void
    {
        Auth::using(null);
    }

    public function testDecodeStandard(): void
    {
        $this->assertSame(
            ['sync.ack', ['version' => 3], 'm1'],
            Protocol::decode('{"type":"sync.ack","data":{"version":3},"msg_id":"m1"}'),
        );
        $this->assertSame(['ping', [], ''], Protocol::decode('{"type":"PING","data":{}}'));
        $this->assertSame(['x', [], ''], Protocol::decode('{"type":"x","data":"bad"}'));
    }

    public function testDecodeFlatCompat(): void
    {
        $this->assertSame(['auth', ['token' => 't'], 'a'], Protocol::decode('{"type":"auth","token":"t","msg_id":"a"}'));
        $this->assertSame(['pong', [], ''], Protocol::decode('{"type":"pong"}'));
    }

    public function testDecodeLegacyType(): void
    {
        $this->assertSame('auth', Protocol::decode('{"type":12,"token":"t"}')[0]);
        $this->assertSame('auth', Protocol::decode('{"type":"12"}')[0]);
        $this->assertNull(Protocol::decode('{"type":12}', false));
        $this->assertNull(Protocol::decode('{"type":99}'));
    }

    public function testDecodeInvalid(): void
    {
        $this->assertNull(Protocol::decode('not json'));
        $this->assertNull(Protocol::decode('[1,2]'));
        $this->assertNull(Protocol::decode('{"type":{"a":1}}'));
        $this->assertNull(Protocol::decode('{"foo":1}'));
    }

    public function testEncode(): void
    {
        $this->assertSame('{"type":"poke","data":{"seq":1,"msg":"你好/"}}', Protocol::encode('poke', ['seq' => 1, 'msg' => '你好/']));
        $this->assertSame('{"type":"auth_ok","data":{},"msg_id":"m1"}', Protocol::encode('auth_ok', [], 'm1'));
    }

    public function testEncodeInvalidData(): void
    {
        $this->assertSame("{\"type\":\"x\",\"data\":{\"s\":\"a\u{FFFD}\"}}", Protocol::encode('x', ['s' => "a\xB1"]));
        $this->assertSame('{"type":"x","data":{}}', Protocol::encode('x', ['n' => NAN]));
    }

    public function testId(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Protocol::id());
        $this->assertNotSame(Protocol::id(), Protocol::id());
    }

    public function testIdentityExpired(): void
    {
        $this->assertFalse((new Identity('1'))->expired());
        $this->assertFalse((new Identity('1', [], 200))->expired(100));
        $this->assertTrue((new Identity('1', [], 100))->expired(100));
        $this->assertTrue((new Identity('1', [], 50))->expired(100));
    }

    public function testBearer(): void
    {
        $this->assertSame('abc.def', Auth::bearer('Bearer abc.def'));
        $this->assertSame('abc', Auth::bearer('  bearer   abc '));
        $this->assertSame('', Auth::bearer('Basic abc'));
        $this->assertSame('', Auth::bearer(null));
    }

    public function testVerifyRejectsWithoutAuthenticator(): void
    {
        $this->assertNull(Auth::verify('token'));
    }

    public function testVerifyWithCallable(): void
    {
        Auth::using(static fn (string $t): ?Identity => $t === 'good' ? new Identity('1001', ['device_id' => 'A']) : null);

        $this->assertSame('1001', Auth::verify('good')?->uid);
        $this->assertNull(Auth::verify('bad'));
        $this->assertNull(Auth::verify('   '));
    }

    public function testVerifySwallowsExceptions(): void
    {
        Auth::using(static function (): ?Identity {
            throw new \RuntimeException('boom');
        });

        $this->assertNull(Auth::verify('x'));
    }
}
