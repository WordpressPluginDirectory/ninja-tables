<?php

namespace NinjaTables\App\Services;

class TranslationService
{
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
