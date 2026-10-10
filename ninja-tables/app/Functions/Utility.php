<?php

namespace NinjaTables\App\Functions;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Support\TableSettings;
use NinjaTables\App\Support\TableCache;
use NinjaTables\App\Support\Sanitize;
use NinjaTables\App\Support\Str;
use NinjaTables\App\Support\TableData;

/**
 * Facade over the app/Support helpers. Kept so Utility::x() keeps working; new code should call
 * the Support classes directly.
 */
class Utility
{
    public static function getTableColumns($tableId, $scope = 'public')
    {
        return TableSettings::getTableColumns($tableId, $scope);
    }

    public static function getTableSettings($tableId, $scope = 'public')
    {
        return TableSettings::getTableSettings($tableId, $scope);
    }

    public static function getDefaultSettings()
    {
        return TableSettings::getDefaultSettings();
    }

    public static function getDataProvider($tableId)
    {
        return TableSettings::getDataProvider($tableId);
    }

    public static function adminRole()
    {
        return TableSettings::adminRole();
    }

    public static function dbTableName()
    {
        return TableSettings::dbTableName();
    }

    public static function isInProductionMood()
    {
        return TableSettings::isInProductionMood();
    }

    public static function getIconUrl()
    {
        return TableSettings::getIconUrl();
    }

    public static function getFluentFormMenuIcon()
    {
        return TableSettings::getFluentFormMenuIcon();
    }

    public static function getPostStatuses()
    {
        return TableSettings::getPostStatuses();
    }

    public static function perChunk($table_id = false)
    {
        return TableSettings::perChunk($table_id);
    }

    public static function boot()
    {
        return TableSettings::boot();
    }

    public static function request()
    {
        return TableSettings::request();
    }

    public static function isNotice($key = 'admin_notice')
    {
        return TableSettings::isNotice($key);
    }

    public static function maybeDisableContaminatedPro()
    {
        return TableSettings::maybeDisableContaminatedPro();
    }

    public static function clearTableDataCache($tableId)
    {
        return TableCache::clearTableDataCache($tableId);
    }

    public static function dataMigratedForManualSort($tableId)
    {
        return TableCache::dataMigratedForManualSort($tableId);
    }

    public static function shouldNotCache($tableId)
    {
        return TableCache::shouldNotCache($tableId);
    }

    public static function getExternalCachedData($tableId)
    {
        return TableCache::getExternalCachedData($tableId);
    }

    public static function setExternalCacheData($tableId, $data)
    {
        return TableCache::setExternalCacheData($tableId, $data);
    }

    public static function clearAllCache($posts = array())
    {
        return TableCache::clearAllCache($posts);
    }

    public static function externalClearPageCaches()
    {
        return TableCache::externalClearPageCaches();
    }

    public static function isValidUrl($url)
    {
        return Sanitize::isValidUrl($url);
    }

    public static function allowedHtmlTags()
    {
        return Sanitize::allowedHtmlTags();
    }

    public static function allowedCssProperties()
    {
        return Sanitize::allowedCssProperties();
    }

    public static function sanitizeArray(array $array)
    {
        return Sanitize::sanitizeArray($array);
    }

    public static function sanitizeTableContentArray(array $array, $tableId)
    {
        return Sanitize::sanitizeTableContentArray($array, $tableId);
    }

    public static function printSafeVar($content, $esc_func = false)
    {
        return Sanitize::printSafeVar($content, $esc_func);
    }

    public static function escCss($css)
    {
        return Sanitize::escCss($css);
    }

    public static function sanitizeForCSV($content)
    {
        return Sanitize::sanitizeForCSV($content);
    }

    public static function escapeScript($data)
    {
        return Sanitize::escapeScript($data);
    }

    public static function canUnfilteredHTML()
    {
        return Sanitize::canUnfilteredHTML();
    }

    public static function validateNonce($key = 'ninja_table_admin_nonce')
    {
        return Sanitize::validateNonce($key);
    }

    public static function renameDuplicateValues($values)
    {
        return Str::renameDuplicateValues($values);
    }

    public static function formatHeader($header)
    {
        return Str::formatHeader($header);
    }

    public static function urlSlug($str, $options = array())
    {
        return Str::urlSlug($str, $options);
    }

    public static function startsWith($haystack, $needles)
    {
        return Str::startsWith($haystack, $needles);
    }

    public static function normalize($data = [])
    {
        return Str::normalize($data);
    }

    public static function getShortCodeIds($content)
    {
        return Str::getShortCodeIds($content);
    }

    public static function getTablesDataByID($tableId,
    $tableColumns = [],
    $defaultSorting = false,
    $disableCache = false,
    $limit = false,
    $skip = false,
    $ownOnly = false)
    {
        return TableData::getTablesDataByID($tableId, $tableColumns, $defaultSorting, $disableCache, $limit, $skip, $ownOnly);
    }

    public static function insertDataToTable($tableId, $values, $header)
    {
        return TableData::insertDataToTable($tableId, $values, $header);
    }

    public static function batchInsert($table, $rows)
    {
        return TableData::batchInsert($table, $rows);
    }

    public static function getRemoteContent($url)
    {
        return TableData::getRemoteContent($url);
    }
}
