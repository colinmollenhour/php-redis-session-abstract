# php-redis-session-abstract #

### A Redis-based session handler with optimistic locking. ###

#### Features: ####
- When a session's data size exceeds the compression threshold the session data will be compressed.
- Compression libraries supported are 'gzip', 'lzf', 'lz4', and 'snappy'.
-- Gzip is the slowest but offers the best compression ratios.
-- Lzf can be installed easily via PECL.
-- Lz4 is supported by HHVM.
- Compression can be enabled, disabled, or reconfigured on the fly with no loss of session data.
- Expiration is handled by Redis; no garbage collection needed.
- Logs when sessions are not written due to not having or losing their lock.
- Limits the number of concurrent lock requests.
- Detects inactive waiting processes to prevent false-positives in concurrency throttling.
- Detects crashed processes to prevent session deadlocks (Linux only).
- Gives shorter session lifetimes to bots and crawlers to reduce wasted resources.
- Locking can be disabled entirely
- Requires PHP >= 5.3. Yes, this is a feature. You're welcome. ;)

#### Locking Algorithm Properties: ####
- Only one process may get a write lock on a session.
- A process may lose it's lock if another process breaks it, in which case the session will not be written.
- The lock may be broken after `BREAK_AFTER` seconds and the process that gets the lock is indeterminate.
- Only `MAX_CONCURRENCY` processes may be waiting for a lock for the same session or else a ConcurrentConnectionsExceededException will be thrown.

### Compression ##

Session data compresses very well so using compression is a great way to increase your capacity without
dedicating a ton of RAM to Redis. Compression can be disabled by setting the `compression_threshold` to 0.
The default `compression threshold` is 2048 bytes so any session data equal to or larger than this size
will be compressed with the chosen `compression_lib` which is 'gzip' by default. However, both lzf and
snappy offer much faster compression with comparable compression ratios so I definitely recommend using
one of these if you have root. lzf is easy to install via pecl:

    sudo pecl install lzf

_NOTE:_ If using suhosin with session data encryption enabled (default is `suhosin.session.encrypt=on`), two things:

1. You will probably get very poor compression ratios.
2. Lzf fails to compress the encrypted data in my experience. No idea why...

If any compression lib fails to compress the session data an error will be logged in `system.log` and the
session will still be saved without compression. If you have `suhosin.session.encrypt=on` I would either
recommend disabling it (unless you are on a shared host since Magento does it's own session validation already)
or disable compression or at least don't use lzf with encryption enabled.

## Bot Detection ##

Bots and crawlers typically do not use cookies which means you may be storing thousands of sessions that
serve no purpose. Even worse, an attacker could use your limited session storage against you by flooding
your backend, thereby causing your legitimate sessions to get evicted. However, you don't want to misidentify
a user as a bot and kill their session unintentionally. This module uses both a regex as well as a
counter on the number of writes against the session to determine the session lifetime.

## Using with [Cm_Cache_Backend_Redis](https://github.com/colinmollenhour/Cm_Cache_Backend_Redis) ##

Using Cm_RedisSession alongside Cm_Cache_Backend_Redis should be no problem at all. The main thing to
keep in mind is that if both the cache and the sessions are using the same database, flushing the cache
backend would also flush the sessions! So, don't use the same 'db' number for both if running only one
instance of Redis. However, using a separate Redis instance for each is recommended to make sure that
one or the other can't run wild consuming space and cause evictions for the other. For example,
configure two instances each with 100M maxmemory rather than one instance with 200M maxmemory.

## License ##

    @copyright  Copyright (c) 2013 Colin Mollenhour (http://colin.mollenhour.com)
    This project is licensed under the "New BSD" license (see source).
