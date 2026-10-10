<?php

namespace NinjaTables\App\Modules\DataProviders;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Collection;
use NinjaTables\Framework\Support\Sanitizer;
use FluentFormPro\Payments\PaymentHelper;

class FluentFormProvider extends BaseProvider
{
    public function key()
    {
        return 'fluent-form';
    }

    public function getFields($id)
    {
        $form            = wpFluentForm('FluentForm\App\Modules\Form\Form');
        $formFieldParser = wpFluentForm('FluentForm\App\Modules\Form\FormFieldsParser');

        // Default meta data fields.
        $labels = [
            ['name' => 'id', 'label' => 'ID'],
            ['name' => 'serial_number', 'label' => __('Serial Number', 'ninja-tables')],
            ['name' => 'status', 'label' => __('Status', 'ninja-tables')]
        ];

        $form   = $form->fetchForm($id);
        $inputs = $formFieldParser->getEntryInputs($form);
        foreach ($formFieldParser->getAdminLabels($form, $inputs) as $key => $value) {
            $labels[] = array('name' => $key, 'label' => $value);
        }

        if ($form->has_payment) {
            $labels = $this->makePaymentFieldLabels($labels);
        }

        return $labels;
    }

    public function makePaymentFieldLabels($labels)
    {
        $labels[] = ['name' => 'payment_total', 'label' => __('Payment Total', 'ninja-tables')];
        $labels[] = ['name' => 'payment_status', 'label' => __('Payment Status', 'ninja-tables')];
        $labels[] = ['name' => 'payer_name', 'label' => __('Billing Name', 'ninja-tables')];
        $labels[] = ['name' => 'payer_email', 'label' => __('Billing Email', 'ninja-tables')];
        $labels[] = ['name' => 'charge_id', 'label' => __('Transaction ID', 'ninja-tables')];
        $labels[] = ['name' => 'created_at', 'label' => __('Payment Date', 'ninja-tables')];

        return $labels;
    }

    public function setPaymentFieldValue($value)
    {
        if (isset($value->payment_status)) {
            $transaction = wpFluent()->table('fluentform_transactions')
                                     ->where('submission_id', $value->id)
                                     ->first();

            $transaction = Collection::make($transaction)->toArray();

            return [
                'payment_total'  => PaymentHelper::formatMoney($value->payment_total, $value->currency),
                'payment_status' => $value->payment_status,
                'payer_name'     => Arr::get($transaction, 'payer_name'),
                'payer_email'    => Arr::get($transaction, 'payer_email'),
                'charge_id'      => Arr::get($transaction, 'charge_id'),
                'created_at'     => $value->created_at
            ];
        }

        return [];
    }

    public function saveTable($form, $fields, $tableId, $formId)
    {
        $currentUserEntryOnly = Arr::get($form, 'current_user_entry_only');
        $entryLimit           = Arr::get($form, 'entry_limit');
        $entryStatus          = Arr::get($form, 'entry_status');

        $columns = array();
        foreach ($fields as $field) {
            $columns[] = array(
                'name'                => $field['label'],
                'key'                 => $field['name'],
                'breakpoints'         => null,
                'data_type'           => 'text',
                'dateFormat'          => null,
                'header_html_content' => null,
                'enable_html_content' => false,
                'contentAlign'        => null,
                'textAlign'           => null,
                'original_name'       => $field['name']
            );
        }

        if ($tableId) {
            $oldColumns = get_post_meta($tableId, '_ninja_table_columns', true);
            foreach ($columns as $key => $newColumn) {
                foreach ($oldColumns as $oldColumn) {
                    if ($oldColumn['original_name'] == $newColumn['original_name']) {
                        $columns[$key] = $oldColumn;
                    }
                }
            }

            // Reset/Reorder array indices
            $columns = array_values($columns);
        } else {
            $tableId = $this->saveOrCreateTable();
        }

        update_post_meta($tableId, '_ninja_table_columns', $columns);
        update_post_meta($tableId, '_ninja_tables_data_provider', 'fluent-form');
        update_post_meta($tableId, '_ninja_tables_data_provider_ff_form_id', $formId);

        if ($currentUserEntryOnly) {
            update_post_meta(
                $tableId,
                '_ninja_tables_ff_own_submission_only',
                Sanitizer::sanitizeTextField($currentUserEntryOnly)
            );
        }

        update_post_meta(
            $tableId,
            '_ninja_tables_data_provider_ff_entry_limit',
            Sanitizer::sanitizeTextField($entryLimit)
        );

        update_post_meta(
            $tableId,
            '_ninja_tables_data_provider_ff_entry_status',
            Sanitizer::sanitizeTextField($entryStatus)
        );

        return $tableId;
    }

    public function getTableSettings($table)
    {
        $table->isEditable        = false;
        $table->dataSourceType    = 'fluent-form';
        $table->isEditableMessage = __('You may edit your table settings here.', 'ninja-tables');
        $table->fluentFormFormId  = get_post_meta(
            $table->ID,
            '_ninja_tables_data_provider_ff_form_id',
            true
        );
        $table->entry_limit       = get_post_meta(
            $table->ID,
            '_ninja_tables_data_provider_ff_entry_limit',
            true
        );
        $table->entry_status      = get_post_meta(
            $table->ID,
            '_ninja_tables_data_provider_ff_entry_status',
            true
        );

        $table->current_user_entry_only = get_post_meta($table->ID, '_ninja_tables_ff_own_submission_only', true);

        $table->isExportable      = true;
        $table->isImportable      = false;
        $table->isCreatedSortable = true;
        $table->isSortable        = false;
        $table->hasCacheFeature   = false;

        return $table;
    }

    public function getTableData($data, $tableId, $perPage = -1, $offset = 0)
    {
        if (function_exists('wpFluentForm')) {
            // we need this short-circuite to overwrite fluentform entry permissions
            add_filter(
                'fluentform/verify_user_permission_fluentform_entries_viewer',
                array($this, 'addEntryPermission')
            );

            $formId = get_post_meta($tableId, '_ninja_tables_data_provider_ff_form_id', true);

            $status            = get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_status', true);
            $entryLimit        = get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_limit', true);
            $ownSubmissionOnly = get_post_meta($tableId, '_ninja_tables_ff_own_submission_only', true);

            $entryStatus  = $status ? $status : 'all';
            $perPageLimit = $entryLimit ? intval($entryLimit) : intval($perPage);

            $wheres = array();
            if ($ownSubmissionOnly === 'yes') {
                $userId = get_current_user_id();

                // If user is not logged in and only want their submissions, return empty data
                if (!$userId) {
                    remove_filter(
                        'fluentform/verify_user_permission_fluentform_entries_viewer',
                        array($this, 'addEntryPermission')
                    );

                    return array(array(), 0);
                }

                $wheres = array(
                    array('user_id', $userId)
                );
            }

            $entries = $this->fetchEntries(
                intval($formId),
                isset($_GET['page']) ? intval($_GET['page']) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $perPageLimit,
                $this->getOrderBy($tableId),
                $entryStatus,
                null,
                $wheres
            );

            // removing this short-circuite to overwrite fluentform entry permissions
            remove_filter(
                'fluentform/verify_user_permission_fluentform_entries_viewer',
                array($this, 'addEntryPermission')
            );

            $columns = $this->getTableColumns($tableId);

            $formattedEntries = array();
            foreach ($entries['submissions']['data'] as $key => $value) {
                // Prepare the entry with the selected columns.
                $value->user_inputs = $this->prepareEntry($value, $columns);
                $formattedEntries[] = array(
                    'id'       => $value->id,
                    'position' => $key,
                    'values'   => $value->user_inputs
                );
            }

            return array(
                $formattedEntries,
                $entries['submissions']['paginate']['total']
            );
        }

        return $data;
    }

    public function getEntryCount($tableId)
    {
        if (!function_exists('wpFluentForm')) {
            return 0;
        }

        add_filter('fluentform/verify_user_permission_fluentform_entries_viewer', array($this, 'addEntryPermission'));

        $formId = get_post_meta($tableId, '_ninja_tables_data_provider_ff_form_id', true);
        $status = get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_status', true);

        $entryStatus       = $status ? $status : 'all';
        $orderBy           = $this->getOrderBy($tableId);
        $ownSubmissionOnly = get_post_meta($tableId, '_ninja_tables_ff_own_submission_only', true);

        $wheres = array();
        if ($ownSubmissionOnly === 'yes') {
            $userId = get_current_user_id();
            if (!$userId) {
                remove_filter(
                    'fluentform/verify_user_permission_fluentform_entries_viewer',
                    array($this, 'addEntryPermission')
                );

                return 0;
            }
            $wheres = array(array('user_id', $userId));
        }

        $entries = $this->fetchEntries(
            intval($formId),
            1,
            1,
            $orderBy,
            $entryStatus,
            null,
            $wheres
        );

        remove_filter('fluentform/verify_user_permission_fluentform_entries_viewer', [$this, 'addEntryPermission']);

        $total      = intval(Arr::get($entries, 'submissions.paginate.total', 0));
        $entryLimit = (int)get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_limit', true);

        if ($entryLimit > 0 && $total > $entryLimit) {
            $total = $entryLimit;
        }

        return $total;
    }

    public function data($data, $tableId, $defaultSorting, $limitEntries = false, $skip = false)
    {
        if (!function_exists('wpFluentForm')) {
            return $data;
        }

        add_filter('fluentform/verify_user_permission_fluentform_entries_viewer', array($this, 'addEntryPermission'));

        $formId = get_post_meta($tableId, '_ninja_tables_data_provider_ff_form_id', true);
        $status = get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_status', true);

        $perPage = intval($limitEntries);

        if (!$perPage) {
            $perPage = (int)get_post_meta($tableId, '_ninja_tables_data_provider_ff_entry_limit', true);
        }

        $skipCount  = intval($skip);
        $entryLimit = $perPage ? $perPage + $skipCount : -1;
        $entryLimit = apply_filters(
            'ninja_tables_fluentform_per_page',
            $entryLimit,
            $tableId,
            $formId
        );

        $entryStatus = $status ? $status : 'all';
        $entryStatus = apply_filters(
            'ninja_tables_fluentform_entry_status',
            $entryStatus,
            $tableId,
            $formId
        );

        $orderBy = apply_filters(
            'ninja_tables_fluentform_order_by',
            $this->getOrderBy($tableId),
            $tableId,
            $formId
        );

        $ownSubmissionOnly = get_post_meta($tableId, '_ninja_tables_ff_own_submission_only', true);

        $wheres = array();
        if ($ownSubmissionOnly === 'yes') {
            $userId = get_current_user_id();

            // If user is not logged in and only want their submissions, return empty data
            if (!$userId) {
                remove_filter(
                    'fluentform/verify_user_permission_fluentform_entries_viewer',
                    array($this, 'addEntryPermission')
                );

                return $data;
            }

            $wheres = array(
                array('user_id', $userId)
            );
        }

        $entries = $this->fetchEntries(
            intval($formId),
            -1,
            $entryLimit,
            $orderBy,
            $entryStatus,
            null,
            $wheres
        );

        remove_filter(
            'fluentform/verify_user_permission_fluentform_entries_viewer',
            array($this, 'addEntryPermission')
        );

        $columns = $this->getTableColumns($tableId);

        if (!isset($entries['submissions']['data'])) {
            return $data;
        }

        $submissions = $entries['submissions']['data']->toArray();

        $skip  = intval($skip);
        $limit = intval($limitEntries);

        if ($skip > 0 || $limit > 0) {
            $submissions = array_slice($submissions, $skip, $limit ?: null);
        }

        foreach ($submissions as $key => $value) {
            // Prepare the entry with the selected columns.
            $data[] = $this->prepareEntry($value, $columns);
        }

        $data = apply_filters(
            'ninja_tables_fluentform_all_entries',
            $data,
            $submissions,
            $columns,
            $tableId
        );

        return $data;
    }

    public function saveOrCreateTable($postId = null)
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        //Need to update this code segment by replacing $_REQUEST
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $attributes = array(
            'post_title'   => isset($_REQUEST['post_title']) ? Sanitizer::sanitizeTextField( // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                wp_unslash($_REQUEST['post_title']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ) : '',
            //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
            'post_content' => isset($_REQUEST['post_content']) ? wp_kses_post( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                wp_unslash($_REQUEST['post_content']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ) : '',
            'post_type'    => 'ninja-table',
            'post_status'  => 'publish'
        );
        if (!$postId) {
            $postId = wp_insert_post($attributes);
        } else {
            $attributes['ID'] = $postId;
            wp_update_post($attributes);
        }

        return $postId;
    }


    /**
     * Query submissions directly. Replaces FluentForm's Entries::_getEntries(),
     * deprecated in Fluent Forms 6.2.0 and slated for removal.
     * Keeps the same return shape the callers already consume.
     */
    private function fetchEntries($formId, $currentPage, $perPage, $sortBy, $entryStatus, $search, $wheres = array())
    {
        $sortBy = strtoupper($sortBy) === 'DESC' ? 'DESC' : 'ASC';

        $query = wpFluent()->table('fluentform_submissions')
            ->where('form_id', $formId)
            ->orderBy('id', $sortBy);

        if ($perPage > 0) {
            $query->limit($perPage);
        }

        if ($currentPage > 0 && $perPage > 0) {
            $query->offset(($currentPage - 1) * $perPage);
        }

        if ($entryStatus === 'favorite') {
            $query->where('is_favourite', 1)->where('status', '!=', 'trashed');
        } elseif ($entryStatus && $entryStatus !== 'all') {
            $query->where('status', $entryStatus);
        } else {
            $query->where('status', '!=', 'trashed');
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                  ->orWhere('response', 'LIKE', "%{$search}%")
                  ->orWhere('status', 'LIKE', "%{$search}%")
                  ->orWhere('created_at', 'LIKE', "%{$search}%");
            });
        }

        foreach ($wheres as $where) {
            if (!is_array($where) || count($where) < 2) {
                continue;
            }
            list($column, $operator, $value) = count($where) > 2 ? $where : array($where[0], '=', $where[1]);
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $operator, $value);
            }
        }

        $total     = $query->count();
        $responses = $query->get();
        $responses = apply_filters('fluentform/get_raw_responses', $responses, $formId); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentionally fires Fluent Forms' own filter

        $form = wpFluent()->table('fluentform_forms')->find($formId);
        $data = \FluentForm\App\Modules\Form\FormDataParser::parseFormEntries($responses, $form);

        return array(
            'submissions' => array(
                'data'     => $data,
                'paginate' => array(
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $currentPage,
                    'last_page'    => $perPage > 0 ? ceil($total / $perPage) : 1,
                ),
            ),
        );
    }

    private function getOrderBy($tableId)
    {
        $tableSettings = get_post_meta($tableId, '_ninja_table_settings', true);
        if (Arr::get($tableSettings, 'default_sorting') == 'old_first') {
            return 'ASC';
        } else {
            return 'DESC';
        }
    }

    public function addEntryPermission()
    {
        return true;
    }

    /**
     * Prepare the entry with the selected columns.
     *
     * @param  $entry
     * @param array $columns
     *
     * @return array
     */
    private function prepareEntry($entry, $columns = [])
    {
        $entry->user_inputs = $this->addEntryMeta($entry, $columns);

        return array_intersect_key(
            $entry->user_inputs,
            array_combine($columns, $columns)
        );
    }

    /**
     * Add available meta data to the entry.
     *
     * @param  $value
     * @param array $columns
     *
     * @return array
     */
    private function addEntryMeta($value, $columns = [])
    {
        $defaultData = [
            'id'            => $value->id,
            'serial_number' => $value->serial_number,
            'status'        => $value->status
        ];

        return array_merge(
            $value->user_inputs,
            array_intersect_key(
                array_merge($defaultData, $this->setPaymentFieldValue($value)),
                array_combine($columns, $columns)
            )
        );
    }

    /**
     * Get the table columns extracted from the column settings.
     *
     * @param  $tableId
     *
     * @return array
     */
    private function getTableColumns($tableId)
    {
        return array_map(function ($column) {
            return $column['original_name'];
        }, get_post_meta($tableId, '_ninja_table_columns', true));
    }
}
