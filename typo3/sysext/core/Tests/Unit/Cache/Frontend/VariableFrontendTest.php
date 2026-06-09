<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Tests\Unit\Cache\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Serializer\AuthenticatedMessageDeserializer;
use TYPO3\CMS\Core\Serializer\DeserializationService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class VariableFrontendTest extends UnitTestCase
{
    private AuthenticatedMessageDeserializer $deserializer;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key';
        $this->deserializer = new AuthenticatedMessageDeserializer(
            new HashService(),
            new DeserializationService(),
        );
    }

    #[Test]
    public function setChecksIfTheIdentifierIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1233058264);

        $cache = $this->getMockBuilder(VariableFrontend::class)
            ->onlyMethods(['isValidEntryIdentifier'])
            ->disableOriginalConstructor()
            ->getMock();
        $cache->expects(self::once())->method('isValidEntryIdentifier')->with('foo')->willReturn(false);
        $cache->set('foo', 'bar');
    }

    #[Test]
    public function setPassesSerializedStringToBackend(): void
    {
        $theString = 'Just some value';
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('set')->with(
            'VariableCacheTest',
            $this->serialize($theString)
        );
        $cache = new VariableFrontend('VariableFrontend', $backend);
        $cache->set('VariableCacheTest', $theString);
    }

    #[Test]
    public function setPassesSerializedArrayToBackend(): void
    {
        $theArray = ['Just some value', 'and another one.'];
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('set')->with(
            'VariableCacheTest',
            $this->serialize($theArray)
        );
        $cache = new VariableFrontend('VariableFrontend', $backend);
        $cache->set('VariableCacheTest', $theArray);
    }

    #[Test]
    public function setPassesLifetimeToBackend(): void
    {
        $theString = 'Just some value';
        $theLifetime = 1234;
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('set')->with(
            'VariableCacheTest',
            $this->serialize($theString),
            [],
            $theLifetime
        );
        $cache = new VariableFrontend('VariableFrontend', $backend);
        $cache->set('VariableCacheTest', $theString, [], $theLifetime);
    }

    #[Test]
    public function getFetchesStringValueFromBackend(): void
    {
        $theString = 'Just some value';
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('get')->willReturn(serialize($theString));
        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertEquals($theString, $cache->get('VariableCacheTest'));
    }

    #[Test]
    public function getFetchesArrayValueFromBackend(): void
    {
        $theArray = ['Just some value', 'and another one.'];
        $backend = $this->getMockBuilder(AbstractBackend::class)
            ->onlyMethods(['get', 'set', 'has', 'remove', 'flush', 'collectGarbage'])
            ->addMethods(['findIdentifiersByTag', 'flushByTag'])
            ->disableOriginalConstructor()
            ->getMock();
        $backend->expects(self::once())->method('get')->willReturn(serialize($theArray));

        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertEquals($theArray, $cache->get('VariableCacheTest'), 'The returned value was not the expected unserialized array.');
    }

    #[Test]
    public function getFetchesFalseBooleanValueFromBackend(): void
    {
        $backend = $this->getMockBuilder(AbstractBackend::class)
            ->onlyMethods(['get', 'set', 'has', 'remove', 'flush', 'collectGarbage'])
            ->addMethods(['findIdentifiersByTag', 'flushByTag'])
            ->disableOriginalConstructor()
            ->getMock();
        $backend->expects(self::once())->method('get')->willReturn(serialize(false));

        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertFalse($cache->get('VariableCacheTest'), 'The returned value was not the FALSE.');
    }

    public static function getHavingUnsignedDataInBackendReturnsValueDataProvider(): iterable
    {
        yield 'int' => [13, 13];
        yield 'string' => ['Just some value', 'Just some value'];
        yield 'array' => [['Just some value', 'and another one.'], ['Just some value', 'and another one.']];
        yield 'stdClass' => [new \stdClass(), false];
    }

    #[Test]
    #[DataProvider('getHavingUnsignedDataInBackendReturnsValueDataProvider')]
    public function getHavingUnsignedDataInBackendReturnsValue(mixed $payload, mixed $expectation): void
    {
        $backend = $this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('get')->willReturn(serialize($payload));
        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertSame($expectation, $cache->get('VariableCacheTest'));
    }

    #[Test]
    public function hasReturnsResultFromBackend(): void
    {
        $backend = $this->getMockBuilder(AbstractBackend::class)
            ->onlyMethods(['get', 'set', 'has', 'remove', 'flush', 'collectGarbage'])
            ->addMethods(['findIdentifiersByTag', 'flushByTag'])
            ->disableOriginalConstructor()
            ->getMock();
        $backend->expects(self::once())->method('has')->with(self::equalTo('VariableCacheTest'))->willReturn(true);

        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertTrue($cache->has('VariableCacheTest'), 'has() did not return TRUE.');
    }

    #[Test]
    public function removeCallsBackend(): void
    {
        $cacheIdentifier = 'someCacheIdentifier';
        $backend = $this->getMockBuilder(AbstractBackend::class)
            ->onlyMethods(['get', 'set', 'has', 'remove', 'flush', 'collectGarbage'])
            ->addMethods(['findIdentifiersByTag', 'flushByTag'])
            ->disableOriginalConstructor()
            ->getMock();

        $backend->expects(self::once())->method('remove')->with(self::equalTo($cacheIdentifier))->willReturn(true);

        $cache = new VariableFrontend('VariableFrontend', $backend);
        self::assertTrue($cache->remove($cacheIdentifier), 'remove() did not return TRUE');
    }

    private function serialize(mixed $payload): string
    {
        return $this->deserializer->serialize($payload, VariableFrontend::class);
    }
}
