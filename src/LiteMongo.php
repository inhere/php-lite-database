<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-12-14
 * Time: 12:02
 */

namespace PhpComp\LiteDb;

/**
 * Class ExtendedMgo - for mongodb database
 * @package PhpComp\LiteDb
 */
class LiteMongo implements LiteDatabaseInterface
{
    use ConfigAndEventAwareTrait;

    const DRIVER_MONGO = 'mongo';
    const DRIVER_MONGO_DB = 'mongodb';

    private $mgo;

    /**
     * @var array
     */
    protected $config = [
        'url' => '',
        'database' => ''
    ];

    /**
     * Is this driver supported.
     * @param string $driver
     * @return bool
     */
    public static function isSupported(string $driver): bool
    {
        if ($driver === self::DRIVER_MONGO_DB) {
            return \extension_loaded('mongodb');
        }

        if ($driver === self::DRIVER_MONGO) {
            return \extension_loaded('mongo');
        }

        return false;
    }

    /**
     * connect
     */
    public function connect()
    {
        if ($this->mgo) {
            return;
        }

    }

    /**
     * reconnect
     */
    public function reconnect()
    {
        $this->mgo = null;
        $this->connect();
    }

    /**
     * disconnect
     */
    public function disconnect()
    {
        $this->fire(self::DISCONNECT, [$this]);
        $this->mgo = null;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return (bool) $this->mgo;
    }
}
