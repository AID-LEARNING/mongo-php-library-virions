<?php

namespace MongoDB\Tests\SpecTests\ClientSideEncryption;

use MongoDB\BSON\Int64;
use MongoDB\Client;
use MongoDB\Driver\WriteConcern;
use MongoDB\Tests\SpecTests\FunctionalTestCase as BaseFunctionalTestCase;
use PHPUnit\Framework\Assert;
use stdClass;

use function explode;
use function getenv;
use function is_executable;
use function is_readable;
use function sprintf;
use function strlen;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;

/**
 * Base class for client-side encryption prose tests.
 *
 * @see https://github.com/mongodb/specifications/blob/bc37892f360cab9df4082922384e0f4d4233f6d3/source/client-side-encryption/tests/README.rst
 */
abstract class FunctionalTestCase extends BaseFunctionalTestCase
{
    public const LOCAL_MASTERKEY = 'Mng0NCt4ZHVUYUJCa1kxNkVyNUR1QURhZ2h2UzR2d2RrZzh0cFBwM3R6NmdWMDFBMUN3YkQ5aXRRMkhGRGdQV09wOGVNYUMxT2k3NjZKelhaQmRCZGJkTXVyZG9uSjFk';

    public function setUp(): void
    {
        parent::setUp();

        $this->skipIfClientSideEncryptionIsNotSupported();

        if (! static::isCryptSharedLibAvailable() && ! static::isMongocryptdAvailable()) {
            $this->markTestSkipped('Neither crypt_shared nor mongocryptd are available');
        }
    }

    public static function createTestClient(?string $uri = null, array $options = [], array $driverOptions = []): Client
    {
        if (isset($driverOptions['autoEncryption']) && getenv('CRYPT_SHARED_LIB_PATH')) {
            $driverOptions['autoEncryption']['extraOptions']['cryptSharedLibPath'] = getenv('CRYPT_SHARED_LIB_PATH');
        }

        return parent::createTestClient($uri, $options, $driverOptions);
    }

    protected static function getAWSCredentials(): array
    {
        return [
            'accessKeyId' => static::getEnv('AWS_ACCESS_KEY_ID'),
            'secretAccessKey' => static::getEnv('AWS_SECRET_ACCESS_KEY'),
        ];
    }

    protected static function insertKeyVaultData(Client $client, ?array $keyVaultData = null): void
    {
        $collection = $client->selectCollection('keyvault', 'datakeys', ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY)]);
        $collection->drop();

        if (empty($keyVaultData)) {
            return;
        }

        $collection->insertMany($keyVaultData);
    }

    private function createInt64(string $value): Int64
    {
        $array = sprintf('a:1:{s:7:"integer";s:%d:"%s";}', strlen($value), $value);
        $int64 = sprintf('C:%d:"%s":%d:{%s}', strlen(Int64::class), Int64::class, strlen($array), $array);

        return unserialize($int64);
    }

    private function createTestCollection(?stdClass $encryptedFields = null, ?stdClass $jsonSchema = null): void
    {
        $context = $this->getContext();
        $options = $context->defaultWriteOptions;

        if (! empty($encryptedFields)) {
            $options['encryptedFields'] = $encryptedFields;
        }

        if (! empty($jsonSchema)) {
            $options['validator'] = ['$jsonSchema' => $jsonSchema];
        }

        $context->getDatabase()->createCollection($context->collectionName, $options);
    }

    private static function getEnv(string $name): string
    {
        $value = getenv($name);

        if ($value === false) {
            Assert::markTestSkipped(sprintf('Environment variable "%s" is not defined', $name));
        }

        return $value;
    }

    private static function isCryptSharedLibAvailable(): bool
    {
        $cryptSharedLibPath = getenv('CRYPT_SHARED_LIB_PATH');

        if ($cryptSharedLibPath === false) {
            return false;
        }

        return is_readable($cryptSharedLibPath);
    }

    private static function isMongocryptdAvailable(): bool
    {
        $paths = explode(PATH_SEPARATOR, getenv("PATH"));

        foreach ($paths as $path) {
            if (is_executable($path . DIRECTORY_SEPARATOR . 'mongocryptd')) {
                return true;
            }
        }

        return false;
    }
}