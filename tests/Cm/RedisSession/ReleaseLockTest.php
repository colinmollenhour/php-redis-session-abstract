<?php
namespace Cm\RedisSession;

use Cm\RedisSession\Handler\ConfigInterface;
use Cm\RedisSession\Handler\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ReleaseLockTest extends TestCase
{
    private $config;
    private $logger;
    private $handler;
    private $redis;
    private $sessionId;
    private $key;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getDatabase')->willReturn(15);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new Handler($this->config, $this->logger);
        $this->redis = new \Credis_Client();
        $this->redis->select(15);
        $this->sessionId = 'release-lock-'.bin2hex(random_bytes(8));
        $this->key = Handler::SESSION_PREFIX.$this->sessionId;
    }

    protected function tearDown(): void
    {
        $this->redis->unlink($this->key);
        $this->redis->close();
        $this->handler->close();
    }

    public function testReleasePreservesSessionAndPreventsLaterWrites()
    {
        $this->seedAndRead();
        $before = $this->redis->hGetAll($this->key);
        $ttl = $this->redis->pTtl($this->key);

        $this->assertTrue($this->handler->releaseLock($this->sessionId));
        $expected = $before;
        $expected['lock'] = '0';
        unset($expected['pid']);
        $this->assertEquals($expected, $this->redis->hGetAll($this->key));
        $this->assertTtlPreserved($ttl);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertTrue($this->handler->write($this->sessionId, 'discarded'));
        $this->assertEquals($expected, $this->redis->hGetAll($this->key));

        $this->assertSame('stored', $this->handler->read($this->sessionId));
        $this->assertTrue($this->handler->write($this->sessionId, 'updated'));
        $this->assertSame('updated', $this->redis->hGet($this->key, 'data'));
        $this->assertSame('8', $this->redis->hGet($this->key, 'writes'));
    }

    public function testReleaseDoesNotClearAnotherOwnersLock()
    {
        $this->seedAndRead();
        $this->redis->hMSet($this->key, array('pid' => 'another-host|123', 'lock' => 5));
        $before = $this->redis->hGetAll($this->key);
        $ttl = $this->redis->pTtl($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertTrue($this->handler->write($this->sessionId, 'discarded'));
        $this->assertEquals($before, $this->redis->hGetAll($this->key));
        $this->assertTtlPreserved($ttl);
    }

    public function testReleaseDoesNotRecreateAnExpiredSession()
    {
        $this->seedAndRead();
        $this->redis->unlink($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertTrue($this->handler->write($this->sessionId, 'discarded'));
        $this->assertSame(0, $this->redis->exists($this->key));
    }

    public function testReleaseDoesNotClearLockWithoutAnOwner()
    {
        $this->seedAndRead();
        $this->redis->hDel($this->key, 'pid');
        $before = $this->redis->hGetAll($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertEquals($before, $this->redis->hGetAll($this->key));
    }

    public function testReleaseDoesNotTouchASessionWithoutReadingIt()
    {
        $this->redis->hMSet($this->key, array('pid' => gethostname().'|'.getmypid(), 'lock' => 1));
        $before = $this->redis->hGetAll($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertEquals($before, $this->redis->hGetAll($this->key));
    }

    public function testReleaseAfterWritingDoesNotClearASubsequentLock()
    {
        $this->seedAndRead();
        $this->assertTrue($this->handler->write($this->sessionId, 'updated'));
        $this->redis->hSet($this->key, 'lock', 1);
        $before = $this->redis->hGetAll($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertEquals($before, $this->redis->hGetAll($this->key));
    }

    /**
     * @dataProvider unlockedModes
     */
    public function testReleaseDoesNotTouchSessionWhenNoLockWasAcquired($readOnly, $disableLocking)
    {
        $this->handler->close();
        $this->config->method('getDisableLocking')->willReturn($disableLocking);
        $this->handler = new Handler($this->config, $this->logger, $readOnly);
        $this->redis->hMSet($this->key, array('pid' => 'another-host|123', 'lock' => 1));
        $this->seedAndRead();
        $before = $this->redis->hGetAll($this->key);
        $ttl = $this->redis->pTtl($this->key);

        $this->assertFalse($this->handler->releaseLock($this->sessionId));
        $this->assertEquals($before, $this->redis->hGetAll($this->key));
        $this->assertTtlPreserved($ttl);
    }

    public static function unlockedModes()
    {
        return array(
            'read only' => array(true, false),
            'locking disabled' => array(false, true),
        );
    }

    public function testReleasePreservesAPersistentSession()
    {
        $this->seedAndRead();
        $this->redis->persist($this->key);

        $this->assertTrue($this->handler->releaseLock($this->sessionId));
        $this->assertSame(-1, $this->redis->pTtl($this->key));
    }

    public function testReleaseSelectsTheConfiguredDatabase()
    {
        $this->seedAndRead();
        $property = new \ReflectionProperty(Handler::class, '_redis');
        $property->setAccessible(true);
        $property->getValue($this->handler)->select(0);

        $this->assertTrue($this->handler->releaseLock($this->sessionId));
        $this->assertSame('0', $this->redis->hGet($this->key, 'lock'));
        $this->assertFalse($this->redis->hGet($this->key, 'pid'));
    }

    public function testReleaseReportsRedisFailuresAndCanBeRetried()
    {
        $this->seedAndRead();
        $before = $this->redis->hGetAll($this->key);
        $error = new \CredisException('Release failed');
        $redis = $this->getMockBuilder(\Credis_Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('select'))
            ->addMethods(array('eval'))
            ->getMock();
        $redis->method('eval')->willThrowException($error);
        $this->logger->expects($this->once())->method('logException')->with($error);
        $property = new \ReflectionProperty(Handler::class, '_redis');
        $property->setAccessible(true);
        $connection = $property->getValue($this->handler);
        $property->setValue($this->handler, $redis);

        try {
            $this->assertFalse($this->handler->releaseLock($this->sessionId));
            $this->assertEquals($before, $this->redis->hGetAll($this->key));
        } finally {
            $property->setValue($this->handler, $connection);
        }
        $this->assertTrue($this->handler->releaseLock($this->sessionId));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReleaseThenAbortDiscardsSessionChanges()
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.serialize_handler', 'php');
        session_cache_limiter('');
        $data = 'value|s:6:"stored";';
        $this->redis->hMSet($this->key, array('data' => $data, 'writes' => 7));
        session_set_save_handler($this->handler, true);
        session_id($this->sessionId);
        $this->assertTrue(session_start());
        $_SESSION['value'] = 'discarded';
        $this->redis->pExpire($this->key, 120000);
        $ttl = $this->redis->pTtl($this->key);

        $this->assertTrue($this->handler->releaseLock(session_id()));
        $this->assertTrue(session_abort());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame($data, $this->redis->hGet($this->key, 'data'));
        $this->assertSame('7', $this->redis->hGet($this->key, 'writes'));
        $this->assertSame('0', $this->redis->hGet($this->key, 'lock'));
        $this->assertFalse($this->redis->hGet($this->key, 'pid'));
        $this->assertTtlPreserved($ttl);
    }

    private function seedAndRead()
    {
        $this->redis->hMSet($this->key, array('data' => 'stored', 'writes' => 7, 'wait' => 2));
        $this->assertSame('stored', $this->handler->read($this->sessionId));
        $this->redis->pExpire($this->key, 120000);
    }

    private function assertTtlPreserved($before)
    {
        $after = $this->redis->pTtl($this->key);
        $this->assertLessThanOrEqual($before, $after);
        $this->assertGreaterThan($before - 1000, $after);
    }
}
