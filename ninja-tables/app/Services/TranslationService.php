<?php

namespace NinjaTables\App\Services;

class TranslationService
{
    /**
     * Labels the public table JS (both engines) reads from tableConfig.i18n.
     * Mostly aria-labels and small UI text; the JS keeps English fallbacks.
     */
    public static function getPublicUiI18n()
    {
        return [
            'scrollable_table'   => __('Scrollable table', 'ninja-tables'),
            'search_table'       => __('Search table', 'ninja-tables'),
            'clear_search'       => __('Clear search', 'ninja-tables'),
            'row_details'        => __('Row Details', 'ninja-tables'),
            'toggle_row_details' => __('Toggle row details', 'ninja-tables'),
            'select_month'       => __('Select Month', 'ninja-tables'),
            'select_year'        => __('Select Year', 'ninja-tables'),
        ];
    }

    /**
     * DataTables' own UI strings. _START_/_END_/_TOTAL_/_MAX_/_MENU_ are
     * replaced by DataTables and must be kept in translations.
     */
    public static function getDataTablesLanguage()
    {
        return [
            'info'           => __('Showing _START_ to _END_ of _TOTAL_ entries', 'ninja-tables'),
            'infoEmpty'      => __('Showing 0 to 0 of 0 entries', 'ninja-tables'),
            'infoFiltered'   => __('(filtered from _MAX_ total entries)', 'ninja-tables'),
            'lengthMenu'     => __('Show _MENU_ entries', 'ninja-tables'),
            'processing'     => __('Processing...', 'ninja-tables'),
            'loadingRecords' => __('Loading...', 'ninja-tables'),
            'paginate'       => [
                'first'    => __('First', 'ninja-tables'),
                'last'     => __('Last', 'ninja-tables'),
                'next'     => __('Next', 'ninja-tables'),
                'previous' => __('Previous', 'ninja-tables'),
            ],
            'aria'           => [
                'sortAscending'  => __(': activate to sort column ascending', 'ninja-tables'),
                'sortDescending' => __(': activate to sort column descending', 'ninja-tables'),
            ],
        ];
    }

    public static function getSumoSelectI18n()
    {
        return [
            'search_in'      => __('Search in', 'ninja-tables'),
            'search'         => __('Search', 'ninja-tables'),
            'empty_text'     => __('No Result Found', 'ninja-tables'),
            // {0} is replaced by SumoSelect with the typed search term
            'no_match'       => __('No matches for "{0}"', 'ninja-tables'),
            'clear_all'      => __('Clear All', 'ninja-tables'),
            'caption_format' => __('Selected', 'ninja-tables'),
            'pikaday'        => [
                'previousMonth' => __('Previous Month', 'ninja-tables'),
                'nextMonth'     => __('Next Month', 'ninja-tables'),
                'months'        => [
                    __('January', 'ninja-tables'),
                    __('February', 'ninja-tables'),
                    __('March', 'ninja-tables'),
                    __('April', 'ninja-tables'),
                    __('May', 'ninja-tables'),
                    __('June', 'ninja-tables'),
                    __('July', 'ninja-tables'),
                    __('August', 'ninja-tables'),
                    __('September', 'ninja-tables'),
                    __('October', 'ninja-tables'),
                    __('November', 'ninja-tables'),
                    __('December', 'ninja-tables'),
                ],
                'weekdays'      => [
                    __('Sunday', 'ninja-tables'),
                    __('Monday', 'ninja-tables'),
                    __('Tuesday', 'ninja-tables'),
                    __('Wednesday', 'ninja-tables'),
                    __('Thursday', 'ninja-tables'),
                    __('Friday', 'ninja-tables'),
                    __('Saturday', 'ninja-tables'),
                ],
                'weekdaysShort' => [
                    __('Sun', 'ninja-tables'),
                    __('Mon', 'ninja-tables'),
                    __('Tue', 'ninja-tables'),
                    __('Wed', 'ninja-tables'),
                    __('Thu', 'ninja-tables'),
                    __('Fri', 'ninja-tables'),
                    __('Sat', 'ninja-tables'),
                ],
                'midnight'      => __('12 AM', 'ninja-tables'),
                'noon'          => __('12 PM', 'ninja-tables'),
            ],
        ];
    }
}
