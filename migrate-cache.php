<?php
error_reporting(2147483647);

try {
    /*
     * If we are run from another directory, try to change the current
     * working directory to a directory the script is in
     */
    if (@!file_exists(getcwd() . '/' . basename($argv[0]))) {
        chdir(dirname(__FILE__));
    } # if

    require_once "lib/SpotClassAutoload.php";
    require_once "lib/Bootstrap.php";

    /*
     * Create a DAO factory. We cannot use the bootstrapper here,
     * because it validates for a valid settings etc. version.
     */
    $bootstrap = new Bootstrap();
    $daoFactory = $bootstrap->getDaoFactory();
    $settings = $bootstrap->getSettings($daoFactory);
    $dbSettings = $bootstrap->getDbSettings();

    /*
     * Try to create the directory, we hardcode it here because
     * it cannot be made configurable in the database anyway
     * and this is just the lazy way out, really
     */
    $daoFactory->setCachePath('./cache/');
    $cacheDao = $daoFactory->getCacheDao();

    if (!is_dir('./cache')) {
        mkdir('./cache', 0777);
    } # if

    /*
     * Now try to get all current cache items
     */
    $dbConnection = $daoFactory->getConnection();

    # Update old blacklisttable
    $schemaVer = $dbConnection->singleQuery("SELECT value FROM settings WHERE name = 'schemaversion'", array());
    if ($schemaVer >= 0.60) {
        throw new Exception("Your schemaversion is already upgraded");
    } # if

    /*
     * Remove any serialized caches as we don't support them anymore
     */
    echo "Removing serialized entries from database";
    $dbConnection->modify("DELETE FROM cache WHERE cachetype = 4");
    echo ", done. " . PHP_EOL;

    $counter = 1;
    while(true) {
        $counter++;
        echo "Migrating cache content, items " . (($counter - 1) * 100) . ' to ' . ($counter * 100);

        $results = null;
        switch ($dbSettings['engine']) {
            case 'mysql'                :
            case 'pdo_mysql'            : {
                $results = $dbConnection->arrayQuery(
                        'SELECT stamp, metadata, serialized, UNCOMPRESS(content) AS content FROM cache WHERE content IS NOT NULL LIMIT 100');

                                              var_dump($results);
                break;
            } # mysql

            case 'pgsql'                :
            case 'pdo_pgsql'            : {
                $results = $dbConnection->arrayQuery(
                        "SELECT stamp, metadata, serialized, content FROM cache WHERE content IS NOT NULL LIMIT 100");
                foreach($results as &$v) {
                    $v['content'] = stream_get_contents($v['content']);
                } # foreach

                break;
            } # case Postgresql

            case 'pdo_sqlite'          : {
                $results = $dbConnection>arrayQuery(
                    'SELECT stamp, metadata, serialized, content FROM cache WHERE content IS NOT NULL LIMIT 100');

                break;
            } # mysql
        }
        foreach($results as $cacheItem) {
            echo '.';
            $cacheDao->putCacheContent($cacheItem['resourceid'], $cacheItem['cachetype'], $cacheItem['content'], $cacheItem['metadata']);

            /*
             * Actually invalidate the cache content
             */
            $dbConnection->modify("UPDATE cache SET content = NULL where resourceid = '%s' AND cachetype = %d",
                       Array($cacheItem['resourceid'], $cacheItem['cachetype']));
        } # results

        echo ", done. " . PHP_EOL;
    } # while

}
catch(Exception $x) {
        echo PHP_EOL . PHP_EOL;
        echo 'SpotWeb crashed' . PHP_EOL . PHP_EOL;
        echo "Cache migration failed:" . PHP_EOL;
        echo "   " . $x->getMessage() . PHP_EOL;
        echo PHP_EOL . PHP_EOL;
        echo $x->getTraceAsString();
        die(1);
    } # catch