<?php
/*
==New BSD License==

Copyright (c) 2013, Colin Mollenhour
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.
    * Redistributions in any form must not change the Cm_RedisSession namespace.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
namespace Cm\RedisSession;

use Cm\RedisSession\Handler\ConfigInterface;
use Cm\RedisSession\Handler\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    /**
     * @var ConfigInterface|MockObject
     */
    private $config;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var Handler
     */
    private $handler;

    /**
     * Create Handler instance
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new Handler($this->config, $this->logger);
    }

    /**
     * Smoke test: open and close connection
     */
    public function testOpenClose()
    {
        $this->assertTrue($this->handler->open('', ''));

        $this->logger->expects($this->once())
            ->method('log')
            ->with($this->stringContains('Closing connection'), LoggerInterface::DEBUG);

        $this->assertTrue($this->handler->close());
    }

    /**
     * Test basic handler operations
     */
    public function testHandler()
    {
        $sessionId = 1;
        $data = 'data';
        $this->handler->destroy($sessionId);
        $this->assertTrue($this->handler->write($sessionId, $data));
        $this->assertEquals(0, $this->handler->getFailedLockAttempts());
        $this->assertEquals($data, $this->handler->read($sessionId));
        $this->handler->destroy($sessionId);
        $this->assertEquals('', $this->handler->read($sessionId));
        $this->assertTrue($this->handler->close());
    }
}
