<?php

namespace NinjaTables\App\Hooks\Handlers;

use NinjaTables\App\App;
use NinjaTables\App\Services\Vite;
use NinjaTables\App\Modules\DataProviders\NinjaFooTable;
use NinjaTables\Framework\Support\Arr;

class PreviewHandler
{
    public function register()
    {
        add_action('wp_loaded', [$this, 'defaultTable']);
        add_action('wp_loaded', [$this, 'dragAndDropTable']);
    }

    public function defaultTable()
    {
        $tableId = null;

        if (isset($_GET['ninjatable_preview'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $tableId = intval($_GET['ninjatable_preview']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($tableId) {
            if (ninja_table_admin_role()) {
                // Embedded in the admin Table Design tab: no chrome, and the tab may POST its
                // unsaved settings so the real frontend pipeline renders the draft.
                $embed = !empty($_GET['nt_embed']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ($embed) {
                    $this->applyDraftSettings($tableId);
                }

                do_action('ninja_tables_will_render_table', $tableId);

                Vite::enqueueStyle('ninja-tables-preview', 'css/ninja-tables-preview.css');

                NinjaFooTable::enqueuePublicCss();
                $table = get_post($tableId);

                if ($table) {
                    $app = App::getInstance();
                    $app->view->render('/admin/preview/default-table', [
                        'table_id' => $tableId,
                        'embed'    => $embed,
                    ]);
                    exit();
                }
            }
        }
    }

    /**
     * Overlay the unsaved settings posted by the Table Design tab for this request only.
     * Nothing is persisted; the nonce keeps other pages from posting drafts here.
     */
    protected function applyDraftSettings($tableId)
    {
        if (empty($_POST['nt_draft_settings'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash(Arr::get($_POST, 'ninja_table_admin_nonce', ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the nonce; verified on the next line
        if (!wp_verify_nonce($nonce, 'ninja_table_admin_nonce')) {
            return;
        }

        $draft = json_decode(wp_unslash($_POST['nt_draft_settings']), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (!is_array($draft)) {
            return;
        }
        $draft = ninja_tables_normalize_dt_css_classes(ninja_tables_sanitize_array($draft));

        // Priority 5 so Pro's own normalisation of the public settings still runs on top of the draft.
        add_filter('ninja_get_table_settings_public', function ($settings, $id) use ($tableId, $draft) {
            if (intval($id) !== $tableId) {
                return $settings;
            }

            return array_merge(is_array($settings) ? $settings : [], $draft);
        }, 5, 2);
    }

    public function dragAndDropTable()
    {
        if (isset($_GET['ninjatable_builder_preview']) && intval(wp_unslash($_GET['ninjatable_builder_preview']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (ninja_table_admin_role()) {
                $tableId = intval(wp_unslash($_GET['ninjatable_builder_preview'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                do_action('ninja_tables_will_render_table', $tableId);

                Vite::enqueueStyle('ninja-tables-preview', 'css/ninja-tables-preview.css');

                $table   = get_post($tableId);

                if ($table) {
                    $app = App::getInstance();
                    $app->view->render('/admin/preview/drag-and-drop', [
                        'table_id' => $tableId
                    ]);
                    exit();
                }
            }
        }
    }
}
