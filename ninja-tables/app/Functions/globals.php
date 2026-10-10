<?php

if (!defined('ABSPATH')) {
    die(__FILE__);
}

/**
 * Backward-compatible global functions. Bodies live in Utility; do not add logic here.
 */

use NinjaTables\App\Functions\Utility;

if (!function_exists('ninja_table_get_table_columns')) {
    function ninja_table_get_table_columns($tableId, $scope = 'public')
    {
        return Utility::getTableColumns($tableId, $scope);
    }
}

if (!function_exists('ninja_table_get_table_settings')) {
    function ninja_table_get_table_settings($tableId, $scope = 'public')
    {
        return Utility::getTableSettings($tableId, $scope);
    }
}

if (!function_exists('ninjaTablesGetDefaultSettings')) {
    function ninjaTablesGetDefaultSettings()
    {
        return Utility::getDefaultSettings();
    }
}

if (!function_exists('ninja_table_admin_role')) {
    function ninja_table_admin_role()
    {
        return Utility::adminRole();
    }
}

if (!function_exists('ninja_tables_db_table_name')) {
    function ninja_tables_db_table_name()
    {
        return Utility::dbTableName();
    }
}

if (!function_exists('ninja_table_renameDuplicateValues')) {
    function ninja_table_renameDuplicateValues($values)
    {
        return Utility::renameDuplicateValues($values);
    }
}

if (!function_exists('ninja_table_is_in_production_mood')) {
    function ninja_table_is_in_production_mood()
    {
        return Utility::isInProductionMood();
    }
}

function ninjaTablesGetTablesDataByID($tableId,
    $tableColumns = [],
    $defaultSorting = false,
    $disableCache = false,
    $limit = false,
    $skip = false,
    $ownOnly = false)
{
    return Utility::getTablesDataByID($tableId, $tableColumns, $defaultSorting, $disableCache, $limit, $skip, $ownOnly);
}

function ninjaTablesClearTableDataCache($tableId)
{
    return Utility::clearTableDataCache($tableId);
}

function ninjaTablesDataMigratedForManualSort($tableId)
{
    return Utility::dataMigratedForManualSort($tableId);
}

function ninja_tables_shouldNotCache($tableId)
{
    return Utility::shouldNotCache($tableId);
}

function ninja_table_get_icon_url()
{
    return Utility::getIconUrl();
}

if (!defined('NINJA_TABLES_URL_FORMAT')) {
    define('NINJA_TABLES_URL_FORMAT',
        '/^(https?):\/\/' .                                         // protocol
        '(([a-z0-9$_\.\+!\*\'\(\),;\?&=-]|%[0-9a-f]{2})+' .         // username
        '(:([a-z0-9$_\.\+!\*\'\(\),;\?&=-]|%[0-9a-f]{2})+)?' .      // password
        '@)?(?#' .                                                  // auth requires @
        ')((([a-z0-9]\.|[a-z0-9][a-z0-9-]*[a-z0-9]\.)*' .                      // domain segments AND
        '[a-z][a-z0-9-]*[a-z0-9]' .                                 // top level domain  OR
        '|((\d|[1-9]\d|1\d{2}|2[0-4][0-9]|25[0-5])\.){3}' .
        '(\d|[1-9]\d|1\d{2}|2[0-4][0-9]|25[0-5])' .                 // IP address
        ')(:\d+)?' .                                                // port
        ')(((\/+([a-z0-9$_\.\+!\*\'\(\),;:@&=-]|%[0-9a-f]{2})*)*' . // path
        '(\?([a-z0-9$_\.\+!\*\'\(\),;:@&=-]|%[0-9a-f]{2})*)' .      // query string
        '?)?)?' .                                                   // path and query string optional
        '(#([a-z0-9$_\.\+!\*\'\(\),;:@&=-]|%[0-9a-f]{2})*)?' .      // fragment
        '$/i');
}

if (!function_exists('ninja_tables_is_valid_url')) {
    function ninja_tables_is_valid_url($url)
    {
        return Utility::isValidUrl($url);
    }
}

function ninja_tables_allowed_html_tags()
{
    return Utility::allowedHtmlTags();
}

function ninja_tables_allowed_css_properties()
{
    return Utility::allowedCssProperties();
}

if (!function_exists('ninja_tables_sanitize_array')) {
    function ninja_tables_sanitize_array(array $array)
    {
        return Utility::sanitizeArray($array);
    }
}

function ninja_tables_sanitize_table_content_array(array $array, $tableId)
{
    return Utility::sanitizeTableContentArray($array, $tableId);
}

function ninjaTableGetExternalCachedData($tableId)
{
    return Utility::getExternalCachedData($tableId);
}

function ninjaTableSetExternalCacheData($tableId, $data)
{
    return Utility::setExternalCacheData($tableId, $data);
}

if (!function_exists('ninjaTablesGetFluentFormMenuIcon')) {
    function ninjaTablesGetFluentFormMenuIcon()
    {
        return Utility::getFluentFormMenuIcon();
    }
}

if (!function_exists('ninjaTablesGetPostStatuses')) {
    function ninjaTablesGetPostStatuses()
    {
        return Utility::getPostStatuses();
    }
}

if (!function_exists('ninja_table_get_data_provider')) {
    function ninja_table_get_data_provider($tableId)
    {
        return Utility::getDataProvider($tableId);
    }
}

if (!function_exists('ninja_tables_normalize_dt_css_classes')) {
    function ninja_tables_normalize_dt_css_classes($settings)
    {
        return \NinjaTables\App\Support\TableSettings::normalizeDataTablesCssClasses($settings);
    }
}

if (!function_exists('ninja_tables_provider_executes_shortcodes')) {
    function ninja_tables_provider_executes_shortcodes($tableId)
    {
        return \NinjaTables\App\Support\TableSettings::providerExecutesShortcodes($tableId);
    }
}

if (!function_exists('ninja_tables_render_cell_shortcode')) {
    function ninja_tables_render_cell_shortcode($value, $execute)
    {
        return \NinjaTables\App\Support\TableSettings::renderCellShortcode($value, $execute);
    }
}

if (!function_exists('ninja_table_format_header')) {
    function ninja_table_format_header($header)
    {
        return Utility::formatHeader($header);
    }
}

if (!function_exists('ninja_table_url_slug')) {
    function ninja_table_url_slug($str, $options = array())
    {
        return Utility::urlSlug($str, $options);
    }
}

function ninjaTableInsertDataToTable($tableId, $values, $header)
{
    return Utility::insertDataToTable($tableId, $values, $header);
}

function ninjaTablePerChunk($table_id = false)
{
    return Utility::perChunk($table_id);
}

function ninja_table_clear_all_cache($posts = array())
{
    return Utility::clearAllCache($posts);
}

function ninjaTablesBatchInsert($table, $rows)
{
    return Utility::batchInsert($table, $rows);
}

function ninjaTableNormalize($data = [])
{
    return Utility::normalize($data);
}

function ninjaTablesGetShortCodeIds($content)
{
    return Utility::getShortCodeIds($content);
}

function ninjaTablesValidateNonce($key = 'ninja_table_admin_nonce')
{
    return Utility::validateNonce($key);
}

if (!function_exists('ninjaTablesPrintSafeVar')) {
    function ninjaTablesPrintSafeVar($content, $esc_func = false)
    {
        return Utility::printSafeVar($content, $esc_func);
    }
}

if (!function_exists('ninjaTablesEscCss')) {
    function ninjaTablesEscCss($css)
    {
        return Utility::escCss($css);
    }
}

function ninjaTablesStartsWith($haystack, $needles)
{
    return Utility::startsWith($haystack, $needles);
}

function ninjaTablesSanitizeForCSV($content)
{
    return Utility::sanitizeForCSV($content);
}

function ninjaTablesEscapeScript($data)
{
    return Utility::escapeScript($data);
}

function ninjaTablesCanUnfilteredHTML()
{
    return Utility::canUnfilteredHTML();
}

function ninjaTablesIsNotice($key = 'admin_notice')
{
    return Utility::isNotice($key);
}

function ninja_tables_boot()
{
    return Utility::boot();
}

function ninjaTablesExternalClearPageCaches()
{
    return Utility::externalClearPageCaches();
}

function ninjaTablesGetRemoteContent($url)
{
    return Utility::getRemoteContent($url);
}

function ninjaTablesRequest()
{
    return Utility::request();
}

function ninja_tables_maybe_disable_contaminated_pro()
{
    return Utility::maybeDisableContaminatedPro();
}
