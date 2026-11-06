<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_shell_layout_renders_fullbleed_slot_without_max_w_7xl_main_container(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $html = Blade::render(<<<'BLADE'
            <x-app-layout>
                <x-slot name="fullbleed">
                    <div id="gantt-viewport">Gantt Fullbleed Canvas</div>
                </x-slot>
            </x-app-layout>
        BLADE);

        $this->assertStringContainsString('<main class="flex-1 min-h-0 flex flex-col overflow-hidden">', $html);
        $this->assertStringContainsString('Gantt Fullbleed Canvas', $html);
        $this->assertStringNotContainsString('<main class="flex-1 py-6">', $html);

        // Extract <main>...</main> to verify no max-w-7xl exists inside main content area
        preg_match('/<main[^>]*>(.*?)<\/main>/s', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? '');
        $this->assertStringNotContainsString('max-w-7xl', $matches[1]);
    }

    public function test_master_shell_layout_renders_unified_chrome_frame_and_inset_scrollable_stage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $html = Blade::render(<<<'BLADE'
            <x-app-layout>
                <div>Standard Inset Stage Content</div>
            </x-app-layout>
        BLADE);

        $this->assertStringContainsString('h-dvh w-screen overflow-hidden flex flex-col bg-surface text-ink antialiased', $html);
        $this->assertStringContainsString('@click="mobileMenuOpen = true"', $html);
        $this->assertStringContainsString('flex-1 min-h-0 rounded-xl sm:rounded-2xl border border-line bg-canvas overflow-y-auto relative shadow-sm o-inset-stage', $html);
        $this->assertStringContainsString('data-sidebar="expanded"', $html);
        $this->assertStringContainsString('o-sidebar hidden lg:flex flex-col shrink-0 h-full bg-surface', $html);
        $this->assertStringNotContainsString('$store.sidebar.collapsed ? \'w-20\'', $html);
        $this->assertStringContainsString('view-transition-name: app-sidebar', $html);
        $this->assertStringContainsString('view-transition-name: app-topbar', $html);
        $this->assertStringContainsString('view-transition-name: app-stage', $html);
        $this->assertStringContainsString('o-ready', $html);
        $this->assertStringContainsString('fixed inset-y-0 left-0 w-72 max-w-[80vw] bg-surface border-r border-line shadow-2xl z-50 flex flex-col lg:hidden', $html);
        $this->assertStringContainsString('Standard Inset Stage Content', $html);
    }

    public function test_sidebar_collapsed_state_is_prerendered_server_side_from_cookie(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        request()->cookies->set('sidebar', 'collapsed');

        $html = Blade::render(<<<'BLADE'
            <x-app-layout>
                <div>Stage</div>
            </x-app-layout>
        BLADE);

        $this->assertStringContainsString('data-sidebar="collapsed"', $html);
    }

    public function test_native_dialog_modal_renders_dialog_element_with_valid_accessibility_attributes(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.modal name="shift-editor" title="Edit Shift" maxWidth="lg">
                <p>Modal Body Content</p>
                <x-slot name="footer">
                    <button type="button">{{ __('common.save') }}</button>
                </x-slot>
            </x-ui.modal>
        BLADE);

        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="modal-title-shift-editor"', $html);
        $this->assertStringContainsString('id="modal-title-shift-editor"', $html);
        $this->assertStringContainsString('sm:max-w-lg', $html);
        $this->assertStringContainsString('m-3 sm:m-auto', $html);
        $this->assertStringContainsString('rounded-2xl sm:rounded-3xl', $html);
        $this->assertStringNotContainsString('rounded-t-xl', $html);
        $this->assertStringNotContainsString('rounded-b-none', $html);
        $this->assertStringContainsString('Modal Body Content', $html);
        $this->assertStringContainsString('$el.showModal()', $html);
    }

    public function test_data_table_component_renders_o_table_classes_and_slots(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.table :striped="true" maxHeight="32rem">
                <x-slot name="header">
                    <th class="o-sticky-col">Employee</th>
                    <th>Role</th>
                </x-slot>

                <tr data-state="selected">
                    <td class="o-sticky-col">Budi Santoso</td>
                    <td>Front Office</td>
                </tr>

                <x-slot name="footer">
                    <span>Pagination Summary</span>
                </x-slot>
            </x-ui.table>
        BLADE);

        $this->assertStringContainsString('o-table', $html);
        $this->assertStringContainsString('o-table--zebra', $html);
        $this->assertStringContainsString('--table-max-h: 32rem;', $html);
        $this->assertStringContainsString('o-sticky-col', $html);
        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString('Pagination Summary', $html);
    }

    public function test_flash_session_messages_trigger_toast_notification_stack(): void
    {
        session()->flash('success', 'Jadwal berhasil disimpan.');
        session()->flash('error', 'Terjadi kesalahan validasi.');

        $html = Blade::render('<x-ui.toast />');

        $this->assertStringContainsString('z-toast', $html);
        $this->assertStringContainsString('Jadwal berhasil disimpan.', $html);
        $this->assertStringContainsString('Terjadi kesalahan validasi.', $html);
        $this->assertStringContainsString('border-emerald-500/30', $html);
        $this->assertStringContainsString('border-red-500/30', $html);
        $this->assertStringContainsString('setTimeout(() => show = false, 4000)', $html);
    }

    public function test_locale_switcher_renders_single_smart_toggle_form_targeting_opposite_locale(): void
    {
        app()->setLocale('id');
        $htmlId = Blade::render('<x-ui.locale-switcher />');

        $this->assertSame(1, substr_count($htmlId, '<form'));
        $this->assertStringContainsString('name="locale" value="en"', $htmlId);
        $this->assertStringContainsString('aria-current="true"', $htmlId);

        app()->setLocale('en');
        $htmlEn = Blade::render('<x-ui.locale-switcher />');

        $this->assertSame(1, substr_count($htmlEn, '<form'));
        $this->assertStringContainsString('name="locale" value="id"', $htmlEn);
    }

    public function test_login_and_app_shell_integrate_default_corporate_logo_assets_and_utility_controls(): void
    {
        $loginResponse = $this->get('/login');
        $loginResponse->assertOk()
            ->assertSee('storage/oatcake_logo_full.jpg', escape: false)
            ->assertSee('$store.theme.toggle()', escape: false)
            ->assertSee(route('locale.update'), escape: false);

        $user = User::factory()->create();
        $this->actingAs($user);

        $shellHtml = Blade::render('<x-app-layout><div>Shell Content</div></x-app-layout>');
        $this->assertStringContainsString('storage/oatcake_logo_min.jpg', $shellHtml);
    }

    public function test_views_adhere_to_semantic_color_tokens_and_eliminate_hardcoded_colors(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user);

        $deptHtml = $this->get('/departments')->getContent();
        $this->assertStringNotContainsString('text-zinc-900', $deptHtml);
        $this->assertStringNotContainsString('bg-white', $deptHtml);
        $this->assertStringContainsString('text-ink', $deptHtml);
        $this->assertStringContainsString('bg-surface', $deptHtml);

        $settingsHtml = $this->get('/company/settings')->getContent();
        $this->assertStringNotContainsString('text-zinc-900', $settingsHtml);
        $this->assertStringNotContainsString('bg-white', $settingsHtml);
        $this->assertStringContainsString('text-ink', $settingsHtml);

        $profileHtml = $this->get('/profile')->getContent();
        $this->assertStringNotContainsString('text-gray-900', $profileHtml);
        $this->assertStringNotContainsString('bg-white', $profileHtml);
        $this->assertStringContainsString('text-ink', $profileHtml);
    }
}
